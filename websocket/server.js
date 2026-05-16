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
//   HTTP /emit 엔드포인트 (PHP → Node.js broadcast 요청 수신용)
//
//   사용처: api/utils/socket_emit.php
//   포맷:
//     POST /emit
//     Content-Type: application/json
//     { "room_id": 5, "event": "member_left", "payload": {...} }
//
//   동작: 방의 모든 멤버를 chat_room_members 에서 조회 →
//        각 user_{id} 채널로 event 전송
//
//   응답: { "success": true, "broadcasted_to": 3 }
//
//   구현 노트:
//   socket.io 의 기본 'request' 핸들러를 빼두고, 우리가 라우팅을 책임짐.
//   /emit 이 아니면 socket.io 기본 핸들러로 위임. 이렇게 안 하면 우리가
//   res.end() 한 뒤 socket.io 가 같은 res 에 또 쓰려다 ERR_HTTP_HEADERS_SENT
//   터지면서 프로세스가 죽음.
// ═══════════════════════════════════════════════════════════════

// 1) socket.io 가 등록해둔 'request' 리스너들을 빼서 보관
const socketIoRequestListeners = io.httpServer.listeners('request').slice();
io.httpServer.removeAllListeners('request');

// 2) 우리가 라우팅 통합 담당
io.httpServer.on('request', (req, res) => {

  // ─── /emit 이 아니면 socket.io 기본 핸들러로 위임 ───
  if (req.url !== '/emit' || req.method !== 'POST') {
    for (const listener of socketIoRequestListeners) {
      listener.call(io.httpServer, req, res);
    }
    return;
  }

  // ─── /emit 처리: HTTP body 읽기 (스트림 → 문자열) ───
  let body = '';
  req.on('data', chunk => { body += chunk; });
  req.on('end', async () => {
    try {
      const data = JSON.parse(body);
      const room_id = parseInt(data.room_id);
      const event   = data.event;
      const payload = data.payload || {};

      // 검증
      if (!room_id || !event) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          success: false,
          error: 'room_id 와 event 가 필요합니다'
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

      console.log(`[/emit] room=${room_id} event=${event} → ${members.length}명에게 broadcast`);

      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({
        success: true,
        broadcasted_to: members.length,
      }));

    } catch (err) {
      console.error('[/emit] 처리 실패:', err.message);
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
  socket.on('message', async (data) => {
    console.log('room:' + data.room_id + ' 메시지:', data.content);

    try {
      const [result] = await pool.execute(
        'INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at) VALUES (?, ?, ?, ?, NOW())',
        [data.room_id, data.user_id, 'text', data.content],
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

      data.message_id = result.insertId;

      // 파싱문제로 파싱해서 db로 전달
      const rawDate = rows[0].created_at;
      const utcDate = rawDate instanceof Date ? rawDate : new Date(rawDate);
      const pad = n => String(n).padStart(2, '0');
      data.created_at = `${utcDate.getUTCFullYear()}-${pad(utcDate.getUTCMonth()+1)}-${pad(utcDate.getUTCDate())} ${pad(utcDate.getUTCHours())}:${pad(utcDate.getUTCMinutes())}:${pad(utcDate.getUTCSeconds())}`;
      data.sender_name = userRows[0]?.user_nickname ?? '';
      data.sender_profile_image = userRows[0]?.user_profile_image ?? '';
      data.unread_count = memberRows[0].cnt - 1; // 발신자 제외

      await pool.execute(
        `UPDATE chat_rooms SET last_message = ?, last_msg_time = ? WHERE room_id = ?`,
        [data.content, rows[0].created_at, data.room_id]
      );

      console.log('DB 저장 완료! message_id:', result.insertId, 'unread_count:', data.unread_count);

      // fan-out: room_id 대신 멤버 각자의 user_채널로 전송
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