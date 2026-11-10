<?php
// api/koneksi.php
define('YOUR_NAME_CREDENTIAL', 'YOUR_VALUE');
define('YOUR_NAME_CREDENTIAL', 'YOUR_VALUE');

$host = "YOUR_HOST";
$db = "YOUR_DB";
$user = "YOUR_USER_NAME";
$pass = "YOUR_PASSWORD";
$port = "YOUR_PORT";


try {
  $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
  $conn = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (\PDOException $e) {
  http_response_code(500);
  echo json_encode([
    "status" => "failed",
    "status_code" => "Internal Server Error",
    "message" => "Database Error: " . $e->getMessage()
  ]);
  exit;
}
?>