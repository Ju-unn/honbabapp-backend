const { Server } = require('socket.io');
const mysql = require('mysql2/promise');
require('dotenv').config({ path: '/var/www/html/.env' });

// DB 연결
let db;
async function connectDB() {
    db = await mysql.createConnection({
        host: process.env.DB_HOST,
        user: process.env.DB_USER,
        password: process.env.DB_PASS,
        database: process.env.DB_NAME
    });
    console.log("DB 연결 성공!");
}
connectDB();

const io = new Server(3000, { cors: { origin: '*' } });
console.log("서버 켜짐! 포트 3000");

io.on('connection', (socket) => {
    console.log("접속 User SocketID:", socket.id);

    socket.emit('welcome', { content: '안녕하세요!' });

    socket.on('ping', (data) => {
        console.log("앱에서 왔어요:", data.text);
    });

    socket.on('echo', (data) => {
        console.log("echo 요청:", data.text);
        socket.emit('echo', data);
    });

    socket.on('join', (data) => {
        const roomId = String(data.room_id);
        socket.join(roomId);
        console.log("room:" + roomId + " 입장 (user_id:" + data.user_id + ")");
        socket.emit('system', { content: roomId + "번 채팅방 입장 완료!" });
    });

    // 메시지 수신 → DB 저장 → 방 전체에 전달
    socket.on('message', async (data) => {
        const roomId = String(data.room_id);
        console.log("room:" + roomId + " 메시지:", data.content);

        try {
            // 현재 시간
            const now = new Date(Date.now() + 9 * 60 * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ');

            // DB 저장
            const [result] = await db.execute(
                'INSERT INTO chat_messages (room_id, sender_id, msg_type, content , created_at) VALUES (?, ?, ?, ?, ?)',
                [data.room_id, data.user_id, 'text', data.content, now]
            );

            // message_id, created_at 추가해서 방 전체에 전달
            data.message_id = result.insertId;
            data.created_at = now;

            console.log("DB 저장 완료! message_id:", result.insertId);
            io.to(roomId).emit('message', data);

        } catch (err) {
            console.error("DB 저장 실패:", err.message);
        }
    });

    // 읽음 처리
    socket.on('read', async (data) => {
        console.log("읽음 처리 room:" + data.room_id + " user:" + data.user_id);

    try {
        // 안읽은 메시지 읽음 처리 - chat_room_members의 last_read_at 업데이트
        const now = new Date(Date.now() + 9 * 60 * 60 * 1000)
            .toISOString().slice(0, 19).replace('T', ' ');

        await db.execute(
            `UPDATE chat_room_members 
             SET last_read_at = ? 
             WHERE room_id = ? AND user_id = ?`,
            [now, data.room_id, data.user_id]
        );

        // 방 전체에 "누가 읽었다"만 알림 (ID 목록 아님!)
        io.to(String(data.room_id)).emit('read_update', {
            reader_id: data.user_id,
            room_id: data.room_id
        });

        console.log("읽음 처리 완료!");

    } catch (err) {
        console.error("읽음 처리 실패:", err.message);
    }
});

    // 연결 종료 - Room 자동 정리됨
    socket.on('disconnect', () => {
        console.log("🔌 연결 종료 - user_id:" + socket.data.userId 
            + " room:" + socket.data.roomId 
            + " (Room 자동 정리됨)");
    });
});
