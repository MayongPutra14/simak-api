<?php
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Credentialed requests cannot use a wildcard origin, so reflect the
// request origin and let the session cookie travel with the login call.
if ($origin !== '') {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
}
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Tangani Preflight Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/auth.php';

$input = json_decode(file_get_contents('php://input'), true);

$nomor_porsi = $input['nomor_porsi'] ?? $_POST['nomor_porsi'] ?? '';
$password = $input['password'] ?? $_POST['password'] ?? '';

// Tambahkan is_completed di dalam SELECT
$stmt = $conn->prepare("SELECT id, nama_lengkap, nomor_porsi, role, password, zona, gambar, is_completed FROM users WHERE nomor_porsi = ?");
$stmt->execute([$nomor_porsi]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user && password_verify($password, $user['password'])) {
  unset($user['password']);

  // Persist the verified identity for authenticated endpoints.
  startApiSession();
  session_regenerate_id(true);
  $_SESSION['user_id'] = (int) $user['id'];
  $_SESSION['role'] = $user['role'];
  $_SESSION['zona'] = $user['zona'];

  echo json_encode([
    "status" => "success",
    "message" => "Login berhasil",
    "data" => [
      "id" => $user['id'],
      "fullName" => $user['nama_lengkap'],
      "portionNumber" => $user['nomor_porsi'],
      "role" => $user['role'],
      "zone" => $user['zona'],
      "photoUrl" => $user['gambar'],
      "isCompleted" => (bool) $user['is_completed'],
    ],
  ]);
} else {
  http_response_code(400);
  echo json_encode([
    "status" => "failed",
    "status_code" => "Bad Request",
    "message" => "Nomor Porsi atau Password salah"
  ]);
}
?>