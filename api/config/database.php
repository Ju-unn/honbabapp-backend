<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

class Database {
  private $host = 'localhost';
  private $db_name = 'honbab_db';
  private $username = 'root';
  private $password = 'sese3210*';
  public $conn;

  public function getConnection() {
    $this->conn = null;

    try {
      $this->conn = new PDO(
        "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8",
        $this->username,
        $this->password
      );
      $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
      // 연결 실패 시 null 반환 (에러는 호출하는 쪽에서 처리)
      error_log('데이터베이스 연결 실패: ' . $e->getMessage());
      $this->conn = null;
    }

    return $this->conn;
  }
}

?>