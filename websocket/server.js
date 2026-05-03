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
console.log('서버 켜짐! 포트 3000');

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

      // [Bug 4 수정] unread_count 계산: 현재 방 멤버 수 - 발신자 1명
      // 실시간 수신 메시지에 unread_count가 없으면 Android에서 항상 0으로 표시됨
      const [memberRows] = await pool.execute(
        'SELECT COUNT(*) as cnt FROM chat_room_members WHERE room_id = ?',
        [data.room_id]
      );

      data.message_id = result.insertId;

      // mysql2는 created_at을 Date 객체로 반환 → JSON 직렬화 시 UTC ISO 형식
      // Android formatTime()은 "yyyy-MM-dd HH:mm:ss" 형식만 파싱 가능하므로 여기서 변환
      const rawDate = rows[0].created_at;
      const kst = new Date(rawDate.getTime ? rawDate.getTime() : new Date(rawDate));
      const pad = n => String(n).padStart(2, '0');
      data.created_at = `${kst.getFullYear()}-${pad(kst.getMonth()+1)}-${pad(kst.getDate())} ${pad(kst.getHours())}:${pad(kst.getMinutes())}:${pad(kst.getSeconds())}`;

      data.sender_name = userRows[0]?.user_nickname ?? '';
      data.sender_profile_image = userRows[0]?.user_profile_image ?? '';
      data.unread_count = memberRows[0].cnt - 1; // 발신자 제외

      console.log('DB 저장 완료! message_id:', result.insertId, 'unread_count:', data.unread_count);
      io.to(roomId).emit('message', data);
    } catch (err) {
      console.error('DB 저장 실패:', err.message);
    }
  });

  // 읽음 처리
  // [신규A] 기존: last_read_at만 UPDATE → messages.php가 chat_message_reads를 COUNT하므로
  //         REST 재로드 시 항상 read_count=0 → unread_count가 원래 값으로 리셋되는 버그
  // 수정: 내가 아직 읽지 않은 메시지를 chat_message_reads에 INSERT + 이미 읽은 경우 broadcast 생략
  socket.on('read', async (data) => {
    console.log('읽음 처리 room:' + data.room_id + ' user:' + data.user_id);

    try {
      // 1단계: 이 방에서 내가 아직 읽지 않은 메시지 조회
      //        - 내가 보낸 메시지(sender_id = 나)는 제외 — 내 메시지를 내가 읽음 처리할 필요 없음
      //        - 시스템 메시지(sender_id IS NULL)도 제외
      //        - chat_message_reads에 이미 있는 것도 제외 → 중복 INSERT 방지
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
        // 2단계: 신규 읽음 레코드 일괄 INSERT
        // INSERT IGNORE → 혹시 중복이 생겨도 에러 없이 무시
        const placeholders = unreadMessages.map(() => '(?, ?, NOW())').join(',');
        const params = unreadMessages.flatMap(m => [m.message_id, data.user_id]);
        await pool.execute(
          `INSERT IGNORE INTO chat_message_reads (message_id, user_id, read_at) VALUES ${placeholders}`,
          params
        );
        console.log('읽음 INSERT 완료! ' + unreadMessages.length + '건');
      }

      // 3단계: last_read_at은 항상 갱신 (채팅방 목록의 뱃지 계산에 사용)
      await pool.execute(
        `UPDATE chat_room_members SET last_read_at = NOW() WHERE room_id = ? AND user_id = ?`,
        [data.room_id, data.user_id]
      );

      // 4단계: 실제로 새로 읽은 메시지가 있을 때만 read_update broadcast
      //        → 이미 읽은 사람이 재진입해도 불필요한 broadcast 없음 → 중복 감소 방지
      if (unreadMessages.length > 0) {
        io.to(String(data.room_id)).emit('read_update', {
          reader_id: data.user_id,
          room_id: data.room_id,
        });
        console.log('read_update broadcast 완료!');
      } else {
        console.log('이미 읽음 처리됨 — broadcast 생략');
      }

    } catch (err) {
      console.error('읽음 처리 실패:', err.message);
    }
  });

  // invite 성공 후 클라이언트가 emit → 방 전체에 브로드캐스트
  // 기존 멤버들이 back/재진입 없이 unread_count 즉시 재계산할 수 있게 함
  socket.on('member_joined', (data) => {
    console.log('member_joined room:' + data.room_id + ' new_member:' + data.user_id);
    io.to(String(data.room_id)).emit('member_joined', {
      room_id: data.room_id,
      new_member_id: data.user_id,
    });
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