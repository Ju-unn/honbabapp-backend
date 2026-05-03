const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
require('dotenv').config({ path: '/var/www/html/.env' });

// DB 연결 풀 생성
// createConnection 대신 createPool 사용:
// - 동시 요청을 병렬 처리 가능 (최대 10개 연결)
// - DB 연결이 끊겨도 자동 재연결
const pool = mysql.createPool({
  host: process.env.DB_HOST,
  user: process.env.DB_USER,
  password: process.env.DB_PASS,
  database: process.env.DB_NAME,
  waitForConnections: true,
  connectionLimit: 10,
  timezone: '+09:00', // KST — DB에서 NOW() 사용 시 한국 시간으로 저장
});

const io = new Server(3000, { cors: { origin: '*' } });
console.log('서버 켜짐 포트 3000');

io.on('connection', (socket) => {
  console.log('접속 User SocketID:', socket.id);

  socket.on('join', (data) => {
    socket.data.userId = data.user_id;
    socket.data.roomId = String(data.room_id);

    const roomId = String(data.room_id);
    socket.join(roomId);
    console.log('room:' + roomId + ' 입장 (user_id:' + data.user_id + ')');
    socket.emit('system', { content: roomId + '번 채팅방 입장 완료!' });
  });

  // 메시지 수신 → DB 저장 → 방 전체에 전달
  socket.on('message', async (data) => {
    const roomId = String(data.room_id);
    console.log('room:' + roomId + ' 메시지:', data.content);

    try {
      const [result] = await pool.execute(
        'INSERT INTO chat_messages (room_id, sender_id, msg_type, content, created_at) VALUES (?, ?, ?, ?, NOW())',
        [data.room_id, data.user_id, 'text', data.content],
      );

      // DB에서 저장된 시간 조회
      const [rows] = await pool.execute(
        'SELECT created_at FROM chat_messages WHERE message_id = ?',
        [result.insertId],
      );

      // [Bug #1 수정] sender_name, sender_profile_image를 DB에서 조회해서 broadcast에 포함
      // 수신자 입장에서 누가 보냈는지 바로 알 수 있어야 UI에 프로필/닉네임을 표시할 수 있음
      const [userRows] = await pool.execute(
        'SELECT user_nickname, user_profile_image FROM users WHERE user_id = ?',
        [data.user_id],
      );

      data.message_id = result.insertId;
      data.created_at = rows[0].created_at;
      data.sender_name = userRows[0]?.user_nickname ?? '';
      data.sender_profile_image = userRows[0]?.user_profile_image ?? '';

      console.log('DB 저장 완료! message_id:', result.insertId);
      io.to(roomId).emit('message', data);
    } catch (err) {
      console.error('DB 저장 실패:', err.message);
    }
  });

  // 읽음 처리
  socket.on('read', async (data) => {
    console.log('읽음 처리 room:' + data.room_id + ' user:' + data.user_id);

    try {
      await pool.execute(
        `UPDATE chat_room_members 
                 SET last_read_at = NOW() 
                 WHERE room_id = ? AND user_id = ?`,
        [data.room_id, data.user_id],
      );

      io.to(String(data.room_id)).emit('read_update', {
        reader_id: data.user_id,
        room_id: data.room_id,
      });

      console.log('읽음 처리 완료!');
    } catch (err) {
      console.error('읽음 처리 실패:', err.message);
    }
  });

  // 연결 종료 - Room 자동 정리됨
  socket.on('disconnect', () => {
    console.log(
      '연결 종료 - user_id:' +
        socket.data.userId +
        ' room:' +
        socket.data.roomId +
        ' (Room 자동 정리됨)',
    );
  });
});