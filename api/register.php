<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

header("Access-Control-Allow-Origin: $origin");
header("Content-Type: application/json");
// header("Access-Control-Allow-Origin: http://localhost:5173");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/koneksi.php';

$input = json_decode(file_get_contents('php://input'), true);

$nama = $input['nama'] ?? $_POST['nama'] ?? '';
$nomor_porsi = $input['nomor_porsi'] ?? $_POST['nomor_porsi'] ?? '';
$whatsapp = $input['whatsapp'] ?? $_POST['whatsapp'] ?? '';
$rawPassword = $input['password'] ?? $_POST['password'] ?? '';
$password = !empty($rawPassword) ? password_hash($rawPassword, PASSWORD_BCRYPT) : '';
$role = 'user';

try {
  $cek = $conn->prepare("SELECT id FROM users WHERE nomor_porsi = ? OR whatsapp = ?");
  $cek->execute([$nomor_porsi, $whatsapp]);

  if ($cek->rowCount() > 0) {
    http_response_code(409);
    echo json_encode([
      "status" => "error",
      "status_code" => "Conflict",
      "message" => "Portion number or WhatsApp already registered!"
    ]);
    exit;
  }

  $stmt = $conn->prepare("INSERT INTO users (nama_lengkap, nomor_porsi, whatsapp, password, role) VALUES (?, ?, ?, ?, ?)");
  if ($stmt->execute([$nama, $nomor_porsi, $whatsapp, $password, $role])) {
    echo json_encode([
      "status" => "success",
      "message" => "Registration successful",
    ]);
  } else {
    http_response_code(500);
    echo json_encode([
      "status" => "failed",
      "message" => "Failed to register. Please try again."
    ]);
  }
} catch (PDOException $e) {
  http_response_code(400);
  echo json_encode([
    "status" => "failed",
    "message" => "Portion number already registered"
  ]);
}
?>