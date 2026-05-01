<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

class Database {
  private $host = 'localhost';
  private $db_name = '';
  private $username = '';
  private $password = '';
  public $conn;

  public function __construct() {
    $this->loadEnv();

    $this->host = $this->getEnvValue('DB_HOST', $this->host);
    $this->db_name = $this->getEnvValue('DB_NAME', $this->db_name);
    $this->username = $this->getEnvValue('DB_USER', $this->username);
    $this->password = $this->getEnvValue('DB_PASS', $this->password);
  }

  private function getEnvValue($key, $default = '') {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
      return $value;
    }

    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
      return $_ENV[$key];
    }

    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
      return $_SERVER[$key];
    }

    return $default;
  }

  private function loadEnv() {
    $paths = [
      '/var/www/html/.env',
      dirname(__DIR__, 2) . '/.env'
    ];

    foreach ($paths as $path) {
      if (!is_file($path)) {
        continue;
      }

      $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      if ($lines === false) {
        continue;
      }

      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
          continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
          continue;
        }

        $name = trim($parts[0]);
        $value = trim($parts[1]);

        if ($name === '') {
          continue;
        }

        $value = trim($value, "\"'");

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
      }

      break;
    }
  }

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