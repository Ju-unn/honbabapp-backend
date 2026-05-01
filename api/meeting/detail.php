<?php
/**
 * 모임 상세 조회 API
 * GET /api/meeting/detail.php
 * 
 * 쿼리 파라미터:
 * - meeting_id: 모임 ID (필수)
 * 
 * 예시:
 * /api/meeting/detail.php?meeting_id=1
 * 
 * 응답:
 * {
 *   "success": true,
 *   "message": "모임 상세 조회 성공",
 *   "data": {
 *     "meeting_id": 1,
 *     "meeting_title": "오늘 저녁 삼겹살 드실 분!",
 *     "meeting_description": "회사 근처에서...",
 *     "meeting_category": "한식",
 *     "meeting_date": "2025-12-22 19:00:00",
 *     "meeting_max_participants": 4,
 *     "meeting_current_participants": 2,
 *     "meeting_price_range": "20,000원",
 *     "meeting_status": "모집중",
 *     "meeting_is_hot": true,
 *     "meeting_view_count": 46,
 *     "meeting_created_at": "2025-12-21 10:00:00",
 *     "location": {
 *       "location_id": 1,
 *       "place_name": "신림 삼겹살집",
 *       "address": "서울특별시 관악구 신림동 123-45",
 *       "road_address": "서울특별시 관악구 신림로 340",
 *       "sigungu": "관악구",
 *       "dong": "신림동",
 *       "map_x": "126.929810",
 *       "map_y": "37.484089",
 *       "phone_number": "02-1234-5678"
 *     },
 *     "host": {
 *       "user_id": 1,
 *       "user_nickname": "김철수",
 *       "user_profile_image": "https://...",
 *       "user_manner_score": 4.5,
 *       "user_gender": "남성"
 *     },
 *     "participants": [
 *       {
 *         "user_id": 1,
 *         "user_nickname": "김철수",
 *         "user_profile_image": "https://...",
 *         "user_manner_score": 4.5,
 *         "joined_at": "2025-12-21 10:00:00"
 *       },
 *       {
 *         "user_id": 2,
 *         "user_nickname": "이영희",
 *         "user_profile_image": "https://...",
 *         "user_manner_score": 4.2,
 *         "joined_at": "2025-12-21 11:30:00"
 *       }
 *     ]
 *   }
 * }
 */

 require_once '../config/database.php';
 require_once '../utils/response.php';
 
 if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
     sendError('GET 요청만 허용됩니다', 405);
 }
 
 if (empty($_GET['meeting_id'])) {
     sendError('meeting_id는 필수입니다');
 }
 
 $meeting_id = intval($_GET['meeting_id']);
 $user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;
 
 $database = new Database();
 $db = $database->getConnection();
 
 try {
     // 모임 기본 정보 조회
     $query = "SELECT 
                 m.*,
                 l.*,
                 u.user_id as host_user_id,
                 u.user_nickname as host_nickname,
                 u.user_profile_image as host_profile_image,
                 u.user_manner_score as host_manner_score,
                 u.user_gender as host_gender
               FROM meetings m
               JOIN locations l ON m.location_id = l.location_id
               JOIN users u ON m.meeting_host_id = u.user_id
               WHERE m.meeting_id = :meeting_id";
     
     $stmt = $db->prepare($query);
     $stmt->bindParam(':meeting_id', $meeting_id);
     $stmt->execute();
     
     if ($stmt->rowCount() === 0) {
         sendError('존재하지 않는 모임입니다', 404);
     }
     
     $row = $stmt->fetch(PDO::FETCH_ASSOC);
     
     // 조회수 증가
     $update_view_query = "UPDATE meetings 
                           SET meeting_view_count = meeting_view_count + 1 
                           WHERE meeting_id = :meeting_id";
     $update_view_stmt = $db->prepare($update_view_query);
     $update_view_stmt->bindParam(':meeting_id', $meeting_id);
     $update_view_stmt->execute();
     
     // 참여자 목록 조회 (승인된 사람만 - 화면 표시용)
     $participant_query = "SELECT 
                             u.user_id,
                             u.user_nickname,
                             u.user_profile_image,
                             u.user_manner_score,
                             mp.applied_at as joined_at
                           FROM meeting_participants mp
                           JOIN users u ON mp.user_id = u.user_id
                           WHERE mp.meeting_id = :meeting_id
                           AND mp.participation_status = '승인'
                           ORDER BY mp.applied_at ASC";
     
     $participant_stmt = $db->prepare($participant_query);
     $participant_stmt->bindParam(':meeting_id', $meeting_id);
     $participant_stmt->execute();
     $participants = $participant_stmt->fetchAll(PDO::FETCH_ASSOC);
     
     $my_participation_status = null;
     
     if ($user_id !== null) {
         $my_status_query = "SELECT participation_status 
                            FROM meeting_participants 
                            WHERE meeting_id = :meeting_id 
                            AND user_id = :user_id";
         
         $my_status_stmt = $db->prepare($my_status_query);
         $my_status_stmt->bindParam(':meeting_id', $meeting_id);
         $my_status_stmt->bindParam(':user_id', $user_id);
         $my_status_stmt->execute();
         
         if ($my_status_stmt->rowCount() > 0) {
             $my_status_row = $my_status_stmt->fetch(PDO::FETCH_ASSOC);
             $my_participation_status = $my_status_row['participation_status'];
         }
     }
     
     // 응답 데이터 구성
     $response_data = [
         'meeting_id' => (int)$row['meeting_id'],
         'meeting_title' => $row['meeting_title'],
         'meeting_description' => $row['meeting_description'],
         'meeting_category' => $row['meeting_category'],
         'meeting_date' => $row['meeting_date'],
         'meeting_max_participants' => (int)$row['meeting_max_participants'],
         'meeting_current_participants' => (int)$row['meeting_current_participants'],
         'meeting_price_range' => $row['meeting_price_range'],
         'meeting_status' => $row['meeting_status'],
         'meeting_image' => $row['meeting_image'] ?? null,
         'meeting_view_count' => (int)$row['meeting_view_count'],
         'meeting_is_hot' => (bool)$row['meeting_is_hot'],
         'meeting_created_at' => $row['meeting_created_at'],
         'my_participation_status' => $my_participation_status,  
         'location' => [
             'location_id' => (int)$row['location_id'],
             'place_name' => $row['place_name'],
             'address' => $row['address'],
             'road_address' => $row['road_address'],
             'sigungu' => $row['sigungu'],
             'dong' => $row['dong'],
             'map_x' => $row['map_x'],
             'map_y' => $row['map_y'],
             'category' => $row['category'],
             'phone_number' => $row['phone_number']
         ],
         'host' => [
             'user_id' => (int)$row['host_user_id'],
             'user_nickname' => $row['host_nickname'],
             'user_profile_image' => $row['host_profile_image'],
             'user_manner_score' => (float)$row['host_manner_score'],
             'user_gender' => $row['host_gender']
         ],
         'participants' => $participants  // 승인된 사람만
     ];
     
     sendSuccess('모임 상세 조회 성공', $response_data);
     
 } catch(PDOException $e) {
     sendError('모임 상세 조회 실패: ' . $e->getMessage(), 500);
 }
 ?>