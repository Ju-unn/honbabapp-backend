const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const cron = require('node-cron');
const admin = require('firebase-admin');
const jwt = require('jsonwebtoken');
const crypto = require('crypto');
require('dotenv').config({ path: '/var/www/html/.env' });

const pool = mysql.createPool({
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME,
  waitForConnections: true,
  connectionLimit: 10,
  timezone: 'Z',
});

pool.pool.on('connection', (conn) => {
  conn.query("SET time_zone='+09:00'");
});

const io = new Server(3000, { cors: { origin: '*' } });
console.log('서버 켜짐! 포트 3000');

// ═══════════════════════════════════════════════════════════════
//   FCM(Firebase Cloud Messaging) 초기화
//
//   서비스 계정 JSON 경로는 env(FIREBASE_SA_PATH)에서 로드하며,
//   미설정 시 기본 경로(/var/www/html/firebase-service-account.json)를 사용.
//   초기화 실패해도 서버 자체는 계속 동작해야 하므로 throw 하지 않고
//   로그만 남긴다 (이후 푸시 발송 호출은 모두 try/catch로 보호됨).
// ═══════════════════════════════════════════════════════════════
const FIREBASE_SERVICE_ACCOUNT_PATH =
  process.env.FIREBASE_SA_PATH ||
  '/var/www/html/firebase-service-account.json';

try {
  admin.initializeApp({
    credential: admin.credential.cert(require(FIREBASE_SERVICE_ACCOUNT_PATH)),
  });
  console.log('[push] firebase-admin 초기화 완료');
} catch (err) {
  console.error('[push] firebase-admin 초기화 실패:', err.message);
}

// ═══════════════════════════════════════════════════════════════
//   fanOutToRoom — 특정 room의 멤버 전원에게 이벤트 전송
//
//   /emit 라우트와 review-notice 크론이 공유하는 헬퍼.
//   "방 멤버 조회 → user_{id} 채널로 emit" 로직을 한 곳으로 모아
//   동일 로직이 여러 곳에 중복되는 것을 방지한다.
//
//   반환값: emit 대상 멤버 수
// ═══════════════════════════════════════════════════════════════

async function fanOutToRoom(room_id, event, payload) {
  const [members] = await pool.execute(
    'SELECT user_id FROM chat_room_members WHERE room_id = ?',
    [room_id],
  );

  for (const m of members) {
    io.to(`user_${m.user_id}`).emit(event, payload);
  }

  return members.length;
}

// ═══════════════════════════════════════════════════════════════
//   assertActiveRoomMember — message/read 이벤트 공통 권한 체크
//
//   지금까지는 소켓이 보낸 data.user_id를 그대로 믿고 DB에 쓰거나
//   fan-out했음 (로그인 때만 JWT로 신원 확인, 이후 이벤트는 무검증).
//   그래서 (1) 로그인한 소켓으로 남의 user_id를 실어 보내는 스푸핑,
//   (2) 탈퇴한 계정이 만료 전 토큰으로 계속 메시지를 보내는 문제가
//   둘 다 가능했음. 여기서 한 번에 막는다:
//     - claimedUserId가 로그인 때 검증된 socket.data.userId와 같은지
//     - 그 user가 이 room의 현재 멤버이면서 계정이 활성 상태인지
// ═══════════════════════════════════════════════════════════════
async function assertActiveRoomMember(socket, roomId, claimedUserId) {
  if (!roomId || !claimedUserId || claimedUserId !== socket.data.userId) {
    return false;
  }

  const [rows] = await pool.execute(
    `SELECT 1 FROM chat_room_members crm
     JOIN users u ON u.user_id = crm.user_id
     WHERE crm.room_id = ? AND crm.user_id = ? AND u.user_status = '활성'
     LIMIT 1`,
    [roomId, claimedUserId],
  );

  return rows.length > 0;
}

// 1:1(direct) 방인데 상대방이 탈퇴/나가서 나 혼자만 남았으면 더 보낼 대상이 없다.
// 읽음 처리(read)는 과거 대화 확인용이라 막을 이유가 없어서 message 전송에만 건다.
async function isRoomStillSendable(roomId) {
  const [rows] = await pool.execute(
    `SELECT cr.room_type,
            (SELECT COUNT(*) FROM chat_room_members m WHERE m.room_id = cr.room_id) AS member_count
     FROM chat_rooms cr
     WHERE cr.room_id = ?
     LIMIT 1`,
    [roomId],
  );

  if (rows.length === 0) return false;
  return !(rows[0].room_type === 'direct' && rows[0].member_count < 2);
}

// ═══════════════════════════════════════════════════════════════
//   FCM 푸시 발송 헬퍼
//
//   buildFcmDataPayload({type, title, body, data})
//     - FCM data 메시지는 모든 필드가 string이어야 하므로 변환 담당
//
//   sendPushToUsers(userIds, {type, title, body, data})
//     - userIds 중 fcm_token IS NOT NULL AND push_enabled = 1 인 사용자만 조회
//     - admin.messaging().sendEachForMulticast() 로 data-only 메시지 일괄 발송
//       (포그라운드 억제·딥링크 제어를 위해 notification 필드는 사용하지 않음)
//     - 응답 중 'messaging/registration-token-not-registered' 인 토큰은
//       users.fcm_token = NULL 로 정리
//     - 반환값: 발송 성공 건수
//
//   sendPushToRoom(room_id, sender_id, {type, title, body, data})
//     - chat_room_members 에서 room_id 멤버 조회, sender_id 제외 후
//       sendPushToUsers 호출
//
//   두 함수 모두 내부에서 에러를 잡아 로그만 남기고 0을 반환한다.
//   호출부에서는 await 하지 않는 발사-후-망각(fire-and-forget) 방식으로
//   사용하므로, 푸시 실패가 채팅/후기 흐름 자체를 막아서는 안 된다.
// ═══════════════════════════════════════════════════════════════

function buildFcmDataPayload({ type, title, body, data = {} }) {
  const payload = {
    type: String(type ?? ''),
    title: String(title ?? ''),
    body: String(body ?? ''),
  };

  for (const [key, value] of Object.entries(data)) {
    payload[key] = String(value);
  }

  return payload;
}

async function sendPushToUsers(userIds, { type, title, body, data = {} }) {
  if (!Array.isArray(userIds) || userIds.length === 0) {
    return 0;
  }

  try {
    const placeholders = userIds.map(() => '?').join(',');
    const [rows] = await pool.execute(
      `SELECT user_id, fcm_token FROM users
       WHERE user_id IN (${placeholders})
         AND fcm_token IS NOT NULL
         AND push_enabled = 1`,
      userIds,
    );

    if (rows.length === 0) {
      return 0;
    }

    const tokens = rows.map((row) => row.fcm_token);
    const fcmDataPayload = buildFcmDataPayload({ type, title, body, data });

    const response = await admin.messaging().sendEachForMulticast({
      tokens,
      data: fcmDataPayload,
      android: { priority: 'high' },
    });

    // 무효 토큰(앱 삭제/재설치 등) 정리
    const invalidTokens = [];
    response.responses.forEach((result, idx) => {
      if (
        !result.success &&
        result.error?.code ===
          'messaging/registration-token-not-registered'
      ) {
        invalidTokens.push(tokens[idx]);
      }
    });

    if (invalidTokens.length > 0) {
      const tokenPlaceholders = invalidTokens.map(() => '?').join(',');
      await pool.execute(
        `UPDATE users SET fcm_token = NULL WHERE fcm_token IN (${tokenPlaceholders})`,
        invalidTokens,
      );
      console.log(`[push] 무효 토큰 ${invalidTokens.length}개 정리`);
    }

    console.log(
      `[push] type=${type} 대상=${tokens.length} 성공=${response.successCount} 실패=${response.failureCount}`,
    );

    return response.successCount;
  } catch (err) {
    console.error('[push] sendPushToUsers 실패:', err.message);
    return 0;
  }
}

async function sendPushToRoom(room_id, sender_id, { type, title, body, data = {} }) {
  try {
    const [members] = await pool.execute(
      'SELECT user_id FROM chat_room_members WHERE room_id = ?',
      [room_id],
    );

    const targetUserIds = members
      .map((member) => member.user_id)
      .filter((userId) => userId !== sender_id);

    return await sendPushToUsers(targetUserIds, { type, title, body, data });
  } catch (err) {
    console.error('[push] sendPushToRoom 실패:', err.message);
    return 0;
  }
}

// ═══════════════════════════════════════════════════════════════
//   HTTP /emit, /emit_user, /push_user, /push_room 엔드포인트
//   (PHP → Node.js broadcast / push 요청 수신용)
//
//   사용처: api/utils/socket_emit.php, api/utils/push_emit.php
//
//   /emit        : 방 전체 fan-out (chat_room_members 기준)
//                  POST { "room_id": 5, "event": "member_left", "payload": {...} }
//
//   /emit_user   : 특정 user 1명에게 직접 (강퇴 알림 등)
//                  POST { "user_id": 7, "event": "kicked", "payload": {...} }
//
//   /push_user   : 특정 user(들)에게 FCM 푸시
//                  POST { "user_ids": [7], "type": "review_received",
//                         "title": "새 후기", "body": "...", "data": {...} }
//
//   /push_room   : 방 멤버 전원(발신자 제외)에게 FCM 푸시
//                  POST { "room_id": 5, "exclude_user_id": 3, "type": "...",
//                         "title": "...", "body": "...", "data": {...} }
//
//   응답: { "success": true, "broadcasted_to": 3 }  (room fan-out)
//        { "success": true, "target_user_id": 7 }  (user 직접)
//        { "success": true, "sent": 2 }            (push_user / push_room)
//
// ═══════════════════════════════════════════════════════════════

// 1) socket.io 가 등록해둔 'request' 리스너들을 빼서 보관
const socketIoRequestListeners = io.httpServer.listeners('request').slice();
io.httpServer.removeAllListeners('request');

// 2)  라우팅 통합 담당
io.httpServer.on('request', (req, res) => {
  const isEmitRoom = req.url === '/emit' && req.method === 'POST';
  const isEmitUser = req.url === '/emit_user' && req.method === 'POST';
  const isPushUser = req.url === '/push_user' && req.method === 'POST';
  const isPushRoom = req.url === '/push_room' && req.method === 'POST';

  // ─── 우리 라우트가 아니면 socket.io 기본 핸들러로 위임 ───
  if (!isEmitRoom && !isEmitUser && !isPushUser && !isPushRoom) {
    for (const listener of socketIoRequestListeners) {
      listener.call(io.httpServer, req, res);
    }
    return;
  }

  // ─── 우리 라우트 처리: HTTP body 읽기 (스트림 → 문자열) ───
  let body = '';
  req.on('data', (chunk) => {
    body += chunk;
  });
  req.on('end', async () => {
    try {
      const data = JSON.parse(body);

      // ───────────────────────────────────────
      // ROUTE C) /push_user — 특정 user(들)에게 푸시
      //   body: { user_ids: [...], type, title, body, data }
      // ───────────────────────────────────────
      if (isPushUser) {
        const userIds = Array.isArray(data.user_ids)
          ? data.user_ids.map((id) => parseInt(id)).filter((id) => !!id)
          : [];

        if (userIds.length === 0) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(
            JSON.stringify({
              success: false,
              error: 'user_ids 가 필요합니다',
            }),
          );
        }

        const sentCount = await sendPushToUsers(userIds, {
          type: data.type,
          title: data.title,
          body: data.body,
          data: data.data || {},
        });

        console.log(
          `[/push_user] users=[${userIds.join(',')}] type=${data.type} → ${sentCount}건 발송`,
        );

        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(
          JSON.stringify({
            success: true,
            sent: sentCount,
          }),
        );
      }

      // ───────────────────────────────────────
      // ROUTE D) /push_room — 방 멤버 전원(발신자 제외)에게 푸시
      //   body: { room_id, exclude_user_id, type, title, body, data }
      // ───────────────────────────────────────
      if (isPushRoom) {
        const room_id = parseInt(data.room_id);
        if (!room_id) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(
            JSON.stringify({
              success: false,
              error: 'room_id 가 필요합니다',
            }),
          );
        }

        const excludeUserId = data.exclude_user_id
          ? parseInt(data.exclude_user_id)
          : null;

        const sentCount = await sendPushToRoom(room_id, excludeUserId, {
          type: data.type,
          title: data.title,
          body: data.body,
          data: data.data || {},
        });

        console.log(
          `[/push_room] room=${room_id} type=${data.type} → ${sentCount}건 발송`,
        );

        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(
          JSON.stringify({
            success: true,
            sent: sentCount,
          }),
        );
      }

      // ───────────────────────────────────────
      // 이하 /emit, /emit_user 는 event 필드 필요
      // ───────────────────────────────────────
      const event = data.event;
      const payload = data.payload || {};

      if (!event) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        return res.end(
          JSON.stringify({
            success: false,
            error: 'event 가 필요합니다',
          }),
        );
      }

      // ───────────────────────────────────────
      // ROUTE A) /emit — 방 전체 fan-out
      // ───────────────────────────────────────
      if (isEmitRoom) {
        const room_id = parseInt(data.room_id);
        if (!room_id) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(
            JSON.stringify({
              success: false,
              error: 'room_id 가 필요합니다',
            }),
          );
        }

        // 방 멤버 조회 → user_{id} 채널로 fan-out (헬퍼 함수 재사용)
        const count = await fanOutToRoom(room_id, event, payload);

        console.log(
          `[/emit] room=${room_id} event=${event} → ${count}명 broadcast`,
        );

        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(
          JSON.stringify({
            success: true,
            broadcasted_to: count,
          }),
        );
      }

      // ───────────────────────────────────────
      // ROUTE B) /emit_user — 특정 user 1명에게 직접
      // ───────────────────────────────────────
      if (isEmitUser) {
        const user_id = parseInt(data.user_id);
        if (!user_id) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(
            JSON.stringify({
              success: false,
              error: 'user_id 가 필요합니다',
            }),
          );
        }

        io.to(`user_${user_id}`).emit(event, payload);

        console.log(`[/emit_user] user=${user_id} event=${event}`);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(
          JSON.stringify({
            success: true,
            target_user_id: user_id,
          }),
        );
      }
    } catch (err) {
      console.error('[emit handler] 처리 실패:', err.message);
      // 헤더 아직 안 보냈을 때만 응답 (이미 보냈으면 그냥 스킵 — 프로세스 안 죽게)
      if (!res.headersSent) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(
          JSON.stringify({
            success: false,
            error: err.message,
          }),
        );
      }
    }
  });
});

io.on('connection', (socket) => {
  console.log('접속 User SocketID:', socket.id);

  // 방 단위가 아니라 유저 단위로 채널 등록
  // → 앱 켜지면 login 한 번으로 모든 채팅방 이벤트 수신 가능
  socket.on('login', (data) => {
    // access token 검증 — user_id를 위조한 login으로 타인의 채팅 이벤트를
    // 수신하는 것을 차단한다. PHP(api/utils/jwt.php)와 동일한 JWT_SECRET/HS256 사용.
    try {
      const decoded = jwt.verify(data.token, process.env.JWT_SECRET, {
        algorithms: ['HS256'],
      });
      if (parseInt(decoded.user_id, 10) !== parseInt(data.user_id, 10)) {
        console.warn(
          `login 거부: token user(${decoded.user_id}) != payload user(${data.user_id})`,
        );
        socket.emit('login_error', { content: '인증 정보가 일치하지 않습니다' });
        return;
      }
    } catch (err) {
      console.warn('login 거부: 토큰 검증 실패 -', err.message);
      socket.emit('login_error', { content: '유효하지 않은 인증 정보입니다' });
      return;
    }

    socket.data.userId = parseInt(data.user_id, 10);
    socket.join(`user_${data.user_id}`); // "user_5" 같은 개인 채널에 등록
    console.log(`user_${data.user_id} 로그인 등록`);
    socket.emit('system', { content: '로그인 등록 완료' });
  });

  // 메시지 수신 → DB 저장 → 멤버 전원 user 채널로 fan-out
  //
  // 지원하는 msg_type:
  //   'text'  : 일반 텍스트 메시지
  //   'image' : 이미지 메시지 (data.image_url 필수, S3 URL)
  //
  // 클라이언트 예시:
  //   텍스트: { room_id, user_id, content: "안녕하세요" }
  //          → msg_type 생략 시 자동으로 'text'
  //   이미지: { room_id, user_id, msg_type: 'image', image_url: 'https://...', content: '사진' }
  //
  socket.on('message', async (data) => {
    const room_id = parseInt(data.room_id);
    const user_id = parseInt(data.user_id);

    if (!(await assertActiveRoomMember(socket, room_id, user_id))) {
      console.warn(`message 거부: room=${room_id} user=${user_id} (멤버 아님/탈퇴/신원불일치)`);
      socket.emit('message_error', {
        room_id,
        client_msg_id: data.client_msg_id ?? null,
        content: '메시지를 보낼 권한이 없습니다',
      });
      return;
    }

    if (!(await isRoomStillSendable(room_id))) {
      console.warn(`message 거부: room=${room_id} (1:1 상대방 없음)`);
      socket.emit('message_error', {
        room_id,
        client_msg_id: data.client_msg_id ?? null,
        content: '상대방이 나간 채팅방이라 메시지를 보낼 수 없습니다',
      });
      return;
    }

    // msg_type 기본값 'text'
    const msg_type = data.msg_type === 'image' ? 'image' : 'text';

    // image_urls 배열 파싱 (신규 다중 이미지)
    // 구버전 클라이언트 호환: image_urls 없으면 image_url 단일값으로 폴백
    let urls = [];
    if (Array.isArray(data.image_urls)) {
      urls = data.image_urls.filter(Boolean);
    }
    if (urls.length === 0 && data.image_url) {
      urls = [data.image_url];
    }

    // 이미지 메시지인데 URL이 하나도 없으면 거부
    if (msg_type === 'image' && urls.length === 0) {
      console.error('이미지 메시지인데 image_url/image_urls 없음, 무시');
      return;
    }

    const image_url = msg_type === 'image' ? urls[0] : null;
    const image_urls = msg_type === 'image' ? JSON.stringify(urls) : null;

    // last_message 표시용 텍스트
    //   - 이미지: '사진' 으로 고정
    //   - 텍스트: content 그대로
    const lastMessageText = msg_type === 'image' ? '사진' : data.content;

    // chat_messages.content 저장값
    //   - 이미지: '사진'
    //   - 텍스트: data.content 그대로
    const contentForDB = msg_type === 'image' ? '사진' : data.content;

    console.log(
      `room:${data.room_id} type:${msg_type} ${msg_type === 'image' ? image_url : data.content}`,
    );

    try {
      // sender_nickname_snapshot/sender_profile_image_snapshot 은 여기서 안 채운다.
      // 그 방 멤버로 있는 동안은 messages.php가 live join으로 최신 닉네임을 보여주고,
      // 방을 나가는 시점(leave.php/kick.php/delete_account.php)에 그 사람이 그 방에
      // 보낸 메시지 전체를 일괄로 스냅샷 고정한다 — 메시지 보낼 때마다 매번 찍으면
      // 활성 유저가 닉네임을 여러 번 바꿨을 때 옛 메시지마다 다른 닉네임이 뒤섞여 보이는
      // 문제가 있었음.
      const [userRows] = await pool.execute(
        'SELECT user_nickname, user_profile_image FROM users WHERE user_id = ?',
        [data.user_id],
      );

      // 1) INSERT — image_url 컬럼까지 채워넣음
      const [result] = await pool.execute(
        'INSERT INTO chat_messages (room_id, sender_id, msg_type, content, image_url, image_urls, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
        [
          data.room_id,
          data.user_id,
          msg_type,
          contentForDB,
          image_url,
          image_urls,
        ],
      );

      const [rows] = await pool.execute(
        'SELECT created_at FROM chat_messages WHERE message_id = ?',
        [result.insertId],
      );

      const [memberRows] = await pool.execute(
        'SELECT COUNT(*) as cnt FROM chat_room_members WHERE room_id = ?',
        [data.room_id],
      );

      // fan-out payload 구성
      data.message_id = result.insertId;
      data.msg_type = msg_type;
      data.image_url = image_url;
      data.image_urls = urls;
      data.content = contentForDB;

      // client_msg_id는 들어온 값 그대로 echo (지우지 않음)

      // 파싱문제로 파싱해서 db로 전달
      const rawDate = rows[0].created_at;
      const utcDate = rawDate instanceof Date ? rawDate : new Date(rawDate);
      const pad = (n) => String(n).padStart(2, '0');
      data.created_at = `${utcDate.getUTCFullYear()}-${pad(utcDate.getUTCMonth() + 1)}-${pad(utcDate.getUTCDate())} ${pad(utcDate.getUTCHours())}:${pad(utcDate.getUTCMinutes())}:${pad(utcDate.getUTCSeconds())}`;
      data.sender_name = userRows[0]?.user_nickname ?? '';
      data.sender_profile_image = userRows[0]?.user_profile_image ?? '';
      data.unread_count = memberRows[0].cnt - 1; // 발신자 제외

      // 2) chat_rooms.last_message 갱신 — 이미지면 '사진', 텍스트면 원문
      await pool.execute(
        `UPDATE chat_rooms SET last_message = ?, last_msg_time = ? WHERE room_id = ?`,
        [lastMessageText, rows[0].created_at, data.room_id],
      );

      console.log(
        `DB 저장 완료! message_id:${result.insertId} type:${msg_type} unread:${data.unread_count}`,
      );

      // 3) fan-out: room_id 대신 멤버 각자의 user_채널로 전송 (헬퍼 함수 재사용)
      await fanOutToRoom(data.room_id, 'message', data);

      // 4) FCM 푸시 발송 — 백그라운드/다른 방을 보고 있는 멤버 대상
      //    fire-and-forget (await 하지 않음, 실패해도 메시지 저장/fan-out은 이미 완료)
      //    포그라운드에서 현재 이 방을 보고 있는 멤버는 앱(FirebaseInstanceIdService)에서
      //    onMessageReceived 단계에서 알림 표시를 억제한다.
      sendPushToRoom(data.room_id, data.user_id, {
        type: 'chat',
        title: data.sender_name || '새 메시지',
        body: lastMessageText,
        data: { room_id: data.room_id, message_id: data.message_id },
      }).catch((err) =>
        console.error('[push] chat 발송 실패:', err.message),
      );
    } catch (err) {
      console.error('DB 저장 실패:', err.message);
    }
  });

  // 읽음 처리
  socket.on('read', async (data) => {
    console.log('읽음 처리 room:' + data.room_id + ' user:' + data.user_id);

    const room_id = parseInt(data.room_id);
    const user_id = parseInt(data.user_id);

    if (!(await assertActiveRoomMember(socket, room_id, user_id))) {
      console.warn(`read 거부: room=${room_id} user=${user_id} (멤버 아님/탈퇴/신원불일치)`);
      return;
    }

    try {
      const [unreadMessages] = await pool.execute(
        `
        SELECT cm.message_id FROM chat_messages cm
        WHERE cm.room_id = ?
          AND (cm.sender_id != ? OR cm.sender_id IS NULL)
          AND cm.message_id NOT IN (
            SELECT message_id FROM chat_message_reads WHERE user_id = ?
          )
      `,
        [data.room_id, data.user_id, data.user_id],
      );

      if (unreadMessages.length > 0) {
        const placeholders = unreadMessages
          .map(() => '(?, ?, NOW())')
          .join(',');
        const params = unreadMessages.flatMap((m) => [
          m.message_id,
          data.user_id,
        ]);
        await pool.execute(
          `INSERT IGNORE INTO chat_message_reads (message_id, user_id, read_at) VALUES ${placeholders}`,
          params,
        );
        console.log('읽음 INSERT 완료 ' + unreadMessages.length + '건');
      }

      await pool.execute(
        `UPDATE chat_room_members SET last_read_at = NOW() WHERE room_id = ? AND user_id = ?`,
        [data.room_id, data.user_id],
      );

      // read_update fan-out
      if (unreadMessages.length > 0) {
        const [members] = await pool.execute(
          'SELECT user_id FROM chat_room_members WHERE room_id = ?',
          [data.room_id],
        );
        for (const m of members) {
          io.to(`user_${m.user_id}`).emit('read_update', {
            reader_id: data.user_id,
            room_id: data.room_id,
            newly_read_message_ids: unreadMessages.map((m) => m.message_id),
          });
        }
        console.log('read_update fan-out 완료');
      } else {
        console.log('이미 읽음 처리됨 — broadcast 생략략');
      }
    } catch (err) {
      console.error('읽음 처리 실패:', err.message);
    }
  });

  // 연결 종료
  socket.on('disconnect', () => {
    console.log('연결 종료 - user_id:' + socket.data.userId);
  });
});

// ═══════════════════════════════════════════════════════════════
//   후기 안내 메시지 자동 발송
//
//   모임 시간(meeting_date) 2시간 후, 채팅방에 "후기작성" 버튼이 달린
//   시스템 메시지(msg_type='review_prompt')를 자동 발송한다.
//   1분마다 실행되며, review_notice_sent 플래그로 중복 발송을 막는다.
// ═══════════════════════════════════════════════════════════════
async function sendReviewNotices() {
  // 1) 후보 조회 — 아직 발송 안 한 모임 중, 종료 2시간이 지난 것
  let candidates;
  try {
    [candidates] = await pool.execute(`
      SELECT m.meeting_id, cr.room_id
      FROM meetings m
      JOIN chat_rooms cr ON cr.meeting_id = m.meeting_id
      WHERE m.review_notice_sent = 0
        AND m.meeting_status != '취소'
        AND m.meeting_date <= NOW() - INTERVAL 2 HOUR
    `);
  } catch (err) {
    console.error('[review-notice] 후보 조회 실패:', err.message);
    return;
  }

  for (const candidate of candidates) {
    const { meeting_id, room_id } = candidate;

    try {
      // 2) 선점 — 이 틱에서 내가 먼저 플래그를 1로 바꿨는지 확인
      //    (틱 겹침/중복 실행에도 한쪽만 성공하도록 원자적 UPDATE 사용)
      const [updateResult] = await pool.execute(
        'UPDATE meetings SET review_notice_sent = 1 WHERE meeting_id = ? AND review_notice_sent = 0',
        [meeting_id],
      );

      if (updateResult.affectedRows !== 1) {
        // 다른 틱/프로세스가 이미 처리함 → 스킵
        continue;
      }

      // 3) chat_messages에 시스템 메시지 INSERT
      const content = '모임은 어떠셨나요? 함께한 멤버들의 후기를 남겨주세요!';

      const [insertResult] = await pool.execute(
        'INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at) VALUES (?, NULL, ?, ?, NOW())',
        [room_id, 'review_prompt', content],
      );

      const [rows] = await pool.execute(
        'SELECT created_at FROM chat_messages WHERE message_id = ?',
        [insertResult.insertId],
      );

      // 4) chat_rooms.last_message / last_msg_time 갱신
      await pool.execute(
        'UPDATE chat_rooms SET last_message = ?, last_msg_time = ? WHERE room_id = ?',
        [content, rows[0].created_at, room_id],
      );

      // 5) 브로드캐스트 payload 구성 (앱 MessageData 형식과 동일)
      const rawDate = rows[0].created_at;
      const utcDate = rawDate instanceof Date ? rawDate : new Date(rawDate);
      const pad = (n) => String(n).padStart(2, '0');
      const created_at = `${utcDate.getUTCFullYear()}-${pad(utcDate.getUTCMonth() + 1)}-${pad(utcDate.getUTCDate())} ${pad(utcDate.getUTCHours())}:${pad(utcDate.getUTCMinutes())}:${pad(utcDate.getUTCSeconds())}`;

      const payload = {
        message_id: insertResult.insertId,
        room_id: room_id,
        user_id: 0, // 시스템 메시지 — 구버전 클라이언트 호환 (optInt 대비 이중 안전장치)
        sender_id: null,
        sender_name: '',
        sender_profile_image: '',
        msg_type: 'review_prompt',
        content: content,
        image_url: null,
        image_urls: [],
        unread_count: 0,
        created_at: created_at,
      };

      const count = await fanOutToRoom(room_id, 'message', payload);

      console.log(
        `[review-notice] meeting=${meeting_id} room=${room_id} → ${count}명 발송`,
      );

      // FCM 푸시 발송 — fire-and-forget (실패해도 system 메시지 발송 자체는 완료된 상태)
      sendPushToRoom(room_id, null, {
        type: 'review_prompt',
        title: '후기 작성',
        body: '모임은 어떠셨나요? 후기를 남겨주세요!',
        data: { room_id, meeting_id },
      }).catch((err) =>
        console.error('[push] review_prompt 발송 실패:', err.message),
      );
    } catch (err) {
      // 한 모임 처리 실패가 전체 틱을 죽이지 않도록
      console.error(
        `[review-notice] meeting=${meeting_id} 처리 실패:`,
        err.message,
      );
    }
  }
}

// 1분마다 실행, 틱 전체 실패해도 프로세스가 죽지 않도록 catch
cron.schedule('* * * * *', () => {
  sendReviewNotices().catch((err) => {
    console.error('[review-notice] 틱 전체 실패:', err.message);
  });
});

// ═══════════════════════════════════════════════════════════════
//   탈퇴 1년 경과 계정 개인정보 파기
//
//   users row 자체는 지우지 않는다 (매너점수/모임 이력/채팅/리뷰 등
//   다른 테이블이 user_id를 참조하고 있어 hard delete하면 그 데이터까지 깨짐).
//   남아있는 개인정보 컬럼만 NULL/임의값으로 덮어써서 파기 요건을 충족한다.
//   매일 새벽 4시 1회 실행.
// ═══════════════════════════════════════════════════════════════
async function purgeWithdrawnPii() {
  let targets;
  try {
    [targets] = await pool.execute(`
      SELECT user_id FROM users
      WHERE user_status = '탈퇴'
        AND user_deleted_at <= (NOW() - INTERVAL 1 YEAR)
        AND user_phone_number IS NOT NULL
    `);
  } catch (err) {
    console.error('[purge-pii] 대상 조회 실패:', err.message);
    return;
  }

  let purged_count = 0;
  for (const { user_id } of targets) {
    try {
      // 로그인은 user_status 체크에서 이미 차단되므로 검증 가능한 해시 형식일 필요는 없음
      const random_password = crypto.randomBytes(32).toString('hex');

      const [result] = await pool.execute(
        `UPDATE users SET
            user_phone_number = NULL,
            user_password = ?,
            user_birthday = NULL,
            user_gender = NULL,
            user_location = NULL,
            user_instagram_id = NULL,
            user_blog_id = NULL,
            user_youtube_id = NULL,
            user_status_message = NULL,
            user_withdrawn_email = NULL,
            user_updated_at = NOW()
         WHERE user_id = ? AND user_phone_number IS NOT NULL`,
        [random_password, user_id],
      );

      if (result.affectedRows === 1) {
        purged_count++;
      }
    } catch (err) {
      console.error(`[purge-pii] user_id=${user_id} 처리 실패:`, err.message);
    }
  }

  if (purged_count > 0) {
    console.log(`[purge-pii] 개인정보 파기 완료: ${purged_count}건`);
  }
}

// 매일 새벽 4시 실행 (KST), 틱 전체 실패해도 프로세스가 죽지 않도록 catch
cron.schedule(
  // '0 4 * * *',
  '*/1 * * * *',
  () => {
    purgeWithdrawnPii().catch((err) => {
      console.error('[purge-pii] 틱 전체 실패:', err.message);
    });
  },
  { timezone: 'Asia/Seoul' },
);

console.log('[review-notice] node-cron 등록 완료 (1분 주기)');