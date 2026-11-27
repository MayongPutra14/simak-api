<?php
error_reporting(0);
ini_set('display_errors', 0);

// Setup Header CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Credentialed requests cannot use a wildcard origin. Reflect the request
// origin so session cookies work for the allowed frontends.
if ($origin !== '') {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// Handle Preflight Request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

// LOGGER/HISTORY REQUEST
require_once __DIR__ . '/logger.php';
logIncomingRequest();

// SESSION HELPERS
require_once __DIR__ . '/auth.php';

// Panggil file koneksi dan controller
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/../controllers/UserController.php';

// Validasi koneksi database
if (!isset($conn)) {
  http_response_code(500);
  echo json_encode([
    "status" => "error",
    "message" => "Koneksi database (\$conn) tidak ditemukan di koneksi.php"
  ]);
  exit();
}

// Inisialisasi Controller
$userController = new UserController($conn);

// Pembacaan payload JSON & Form-Data
$json = file_get_contents('php://input');
$requestData = json_decode($json, true) ?? [];

if (empty($requestData)) {
  $requestData = $_POST;
}

// Ambil aksi dari parameter query URL (?action=...) atau dari body request
$action = $_GET['action'] ?? $requestData['action'] ?? '';

// Routing Endpoint User
switch ($action) {
  case 'update_password':
    $userController->updatePassword($requestData);
    break;

  case 'get_profile':
    $userController->getProfile($requestData);
    break;

  case 'get_my_attendance':
    $userController->getMyAttendance($requestData);
    break;

  case 'update_user_profile':
    $adminController->updateUserProfile($requestData);
    break;

  case 'get_event_dashboard':
    $userController->getDashboardEvent($requestData);
    break;

  case "get_events":
    $userController->getEvents($requestData);
    break;

  case "submit_attendance":
    $userController->submitAttendance($requestData);
    break;

  case 'search_hadith':
    $userController->searchHadith($requestData);
    break;

  case 'get_hadith_books':
    $userController->getHadithBooks($requestData);
    break;

  case 'get_hadith_by_books':
    $userController->getHadithsByKitab($requestData);
    break;

  case 'search_doa_by_query':
    $userController->searchDoa($requestData);
    break;

  case 'get_doa_categories':
    $userController->getDoaCategories($requestData);
    break;

  case 'get_doa_by_category':
    $userController->getDoaByCategory($requestData);
    break;

  default:
    http_response_code(404);
    echo json_encode([
      "status" => "failed",
      "message" => "Endpoint tidak ditemukan atau Action salah."
    ]);
    break;
}