const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
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

const io = new Server(3000, { cors: { origin: '*' } });
console.log('서버 켜짐! 포트 3000');


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

  const isEmitRoom = (req.url === '/emit'      && req.method === 'POST');
  const isEmitUser = (req.url === '/emit_user' && req.method === 'POST');

  // ─── 우리 라우트가 아니면 socket.io 기본 핸들러로 위임 ───
  if (!isEmitRoom && !isEmitUser) {
    for (const listener of socketIoRequestListeners) {
      listener.call(io.httpServer, req, res);
    }
    return;
  }

  // ─── 우리 라우트 처리: HTTP body 읽기 (스트림 → 문자열) ───
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', async () => {
    try {
      const data    = JSON.parse(body);
      const event   = data.event;
      const payload = data.payload || {};

      if (!event) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          success: false,
          error: 'event 가 필요합니다'
        }));
      }

      // ───────────────────────────────────────
      // ROUTE A) /emit — 방 전체 fan-out
      // ───────────────────────────────────────
      if (isEmitRoom) {
        const room_id = parseInt(data.room_id);
        if (!room_id) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({
            success: false,
            error: 'room_id 가 필요합니다'
          }));
        }

        // 방 멤버 조회 → user_{id} 채널로 fan-out
        const [members] = await pool.execute(
          'SELECT user_id FROM chat_room_members WHERE room_id = ?',
          [room_id]
        );

        for (const m of members) {
          io.to(`user_${m.user_id}`).emit(event, payload);
        }

        console.log(`[/emit] room=${room_id} event=${event} → ${members.length}명 broadcast`);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          success: true,
          broadcasted_to: members.length,
        }));
      }

      // ───────────────────────────────────────
      // ROUTE B) /emit_user — 특정 user 1명에게 직접
      // ───────────────────────────────────────
      if (isEmitUser) {
        const user_id = parseInt(data.user_id);
        if (!user_id) {
          res.writeHead(400, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({
            success: false,
            error: 'user_id 가 필요합니다'
          }));
        }

        io.to(`user_${user_id}`).emit(event, payload);

        console.log(`[/emit_user] user=${user_id} event=${event}`);

        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          success: true,
          target_user_id: user_id,
        }));
      }

    } catch (err) {
      console.error('[emit handler] 처리 실패:', err.message);
      // 헤더 아직 안 보냈을 때만 응답 (이미 보냈으면 그냥 스킵 — 프로세스 안 죽게)
      if (!res.headersSent) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({
          success: false,
          error: err.message,
        }));
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
    socket.join(`user_${data.user_id}`);   // "user_5" 같은 개인 채널에 등록
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
    const msg_type  = data.msg_type === 'image' ? 'image' : 'text';
    const image_url = msg_type === 'image' ? (data.image_url || null) : null;

    // 이미지 메시지인데 image_url 없으면 거부
    if (msg_type === 'image' && !image_url) {
      console.error('이미지 메시지인데 image_url 없음, 무시');
      return;
    }

    // last_message 표시용 텍스트
    //   - 이미지: '사진' 으로 고정
    //   - 텍스트: content 그대로
    const lastMessageText = msg_type === 'image' ? '사진' : data.content;

    // chat_messages.content 저장값
    //   - 이미지: '사진'
    //   - 텍스트: data.content 그대로
    const contentForDB = msg_type === 'image' ? '사진' : data.content;

    console.log(`room:${data.room_id} type:${msg_type} ${msg_type === 'image' ? image_url : data.content}`);

    try {
      // 1) INSERT — image_url 컬럼까지 채워넣음
      const [result] = await pool.execute(
        'INSERT INTO chat_messages (room_id, sender_id, msg_type, content, image_url, created_at) VALUES (?, ?, ?, ?, ?, NOW())',
        [data.room_id, data.user_id, msg_type, contentForDB, image_url],
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
        [data.room_id]
      );

      // fan-out payload 구성
      data.message_id = result.insertId;
      data.msg_type   = msg_type;   // 클라이언트가 분기할 수 있게 응답에 명시
      data.image_url  = image_url;  // 텍스트 메시지면 null
      data.content    = contentForDB;

      // 파싱문제로 파싱해서 db로 전달
      const rawDate = rows[0].created_at;
      const utcDate = rawDate instanceof Date ? rawDate : new Date(rawDate);
      const pad = n => String(n).padStart(2, '0');
      data.created_at = `${utcDate.getUTCFullYear()}-${pad(utcDate.getUTCMonth()+1)}-${pad(utcDate.getUTCDate())} ${pad(utcDate.getUTCHours())}:${pad(utcDate.getUTCMinutes())}:${pad(utcDate.getUTCSeconds())}`;
      data.sender_name = userRows[0]?.user_nickname ?? '';
      data.sender_profile_image = userRows[0]?.user_profile_image ?? '';
      data.unread_count = memberRows[0].cnt - 1; // 발신자 제외

      // 2) chat_rooms.last_message 갱신 — 이미지면 '사진', 텍스트면 원문
      await pool.execute(
        `UPDATE chat_rooms SET last_message = ?, last_msg_time = ? WHERE room_id = ?`,
        [lastMessageText, rows[0].created_at, data.room_id]
      );

      console.log(`DB 저장 완료! message_id:${result.insertId} type:${msg_type} unread:${data.unread_count}`);

      // 3) fan-out: room_id 대신 멤버 각자의 user_채널로 전송
      const [members] = await pool.execute(
        'SELECT user_id FROM chat_room_members WHERE room_id = ?',
        [data.room_id]
      );
      for (const m of members) {
        io.to(`user_${m.user_id}`).emit('message', data);
      }

    } catch (err) {
      console.error('DB 저장 실패:', err.message);
    }
  });

  // 읽음 처리
  socket.on('read', async (data) => {
    console.log('읽음 처리 room:' + data.room_id + ' user:' + data.user_id);

    try {
      const [unreadMessages] = await pool.execute(`
        SELECT cm.message_id FROM chat_messages cm
        WHERE cm.room_id = ?
          AND cm.sender_id != ?
          AND cm.sender_id IS NOT NULL
          AND cm.message_id NOT IN (
            SELECT message_id FROM chat_message_reads WHERE user_id = ?
          )
      `, [data.room_id, data.user_id, data.user_id]);

      if (unreadMessages.length > 0) {
        const placeholders = unreadMessages.map(() => '(?, ?, NOW())').join(',');
        const params = unreadMessages.flatMap(m => [m.message_id, data.user_id]);
        await pool.execute(
          `INSERT IGNORE INTO chat_message_reads (message_id, user_id, read_at) VALUES ${placeholders}`,
          params
        );
        console.log('읽음 INSERT 완료 ' + unreadMessages.length + '건');
      }

      await pool.execute(
        `UPDATE chat_room_members SET last_read_at = NOW() WHERE room_id = ? AND user_id = ?`,
        [data.room_id, data.user_id]
      );

      // read_update fan-out
      if (unreadMessages.length > 0) {
        const [members] = await pool.execute(
          'SELECT user_id FROM chat_room_members WHERE room_id = ?',
          [data.room_id]
        );
        for (const m of members) {
          io.to(`user_${m.user_id}`).emit('read_update', {
            reader_id: data.user_id,
            room_id: data.room_id,
            newly_read_message_ids: unreadMessages.map(m => m.message_id),
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
    console.log('member_joined room:' + data.room_id + ' new_member:' + data.user_id);
    try {
      const [members] = await pool.execute(
        'SELECT user_id FROM chat_room_members WHERE room_id = ?',
        [data.room_id]
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