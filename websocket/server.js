const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
const cron = require('node-cron');
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
//   HTTP /emit, /emit_user 엔드포인트 (PHP → Node.js broadcast 요청 수신용)
//
//   사용처: api/utils/socket_emit.php
//
//   /emit        : 방 전체 fan-out (chat_room_members 기준)
//                  POST { "room_id": 5, "event": "member_left", "payload": {...} }
//
//   /emit_user   : 특정 user 1명에게 직접 (강퇴 알림 등)
//                  POST { "user_id": 7, "event": "kicked", "payload": {...} }
//
//   응답: { "success": true, "broadcasted_to": 3 }  (room fan-out)
//        { "success": true, "target_user_id": 7 }  (user 직접)
//
// ═══════════════════════════════════════════════════════════════

// 1) socket.io 가 등록해둔 'request' 리스너들을 빼서 보관
const socketIoRequestListeners = io.httpServer.listeners('request').slice();
io.httpServer.removeAllListeners('request');

// 2) 우리가 라우팅 통합 담당
io.httpServer.on('request', (req, res) => {
  const isEmitRoom = req.url === '/emit' && req.method === 'POST';
  const isEmitUser = req.url === '/emit_user' && req.method === 'POST';

  // ─── 우리 라우트가 아니면 socket.io 기본 핸들러로 위임 ───
  if (!isEmitRoom && !isEmitUser) {
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
    socket.data.userId = data.user_id;
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

      const [userRows] = await pool.execute(
        'SELECT user_nickname, user_profile_image FROM users WHERE user_id = ?',
        [data.user_id],
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
    } catch (err) {
      console.error('DB 저장 실패:', err.message);
    }
  });

  // 읽음 처리
  socket.on('read', async (data) => {
    console.log('읽음 처리 room:' + data.room_id + ' user:' + data.user_id);

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

  // member_joined fan-out
  socket.on('member_joined', async (data) => {
    console.log(
      'member_joined room:' + data.room_id + ' new_member:' + data.user_id,
    );
    try {
      const [members] = await pool.execute(
        'SELECT user_id FROM chat_room_members WHERE room_id = ?',
        [data.room_id],
      );
      for (const m of members) {
        io.to(`user_${m.user_id}`).emit('member_joined', {
          room_id: data.room_id,
          new_member_id: data.user_id,
        });
      }
    } catch (err) {
      console.error('member_joined fan-out 실패:', err.message);
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

console.log('[review-notice] node-cron 등록 완료 (1분 주기)');
