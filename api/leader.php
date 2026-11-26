<?php
// DISABLE ERROR DISPLAY FOR PRODUCTION API
error_reporting(0);
ini_set('display_errors', 0);

// SETUP CORS HEADERS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Credentialed requests cannot use a wildcard origin. Reflect the request
// origin so the session cookie (PHPSESSID) travels with the call.
if ($origin !== '') {
  header("Access-Control-Allow-Origin: $origin");
  header('Vary: Origin');
}

header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

// HANDLE PREFLIGHT REQUEST (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(200);
  exit();
}

// LOGGER / HISTORY REQUEST
require_once __DIR__ . '/logger.php';
logIncomingRequest();

// SESSION HELPERS
require_once __DIR__ . '/auth.php';

// LOAD DATABASE CONNECTION & CONTROLLER
require_once __DIR__ . '/koneksi.php';
require_once __DIR__ . '/../controllers/LeaderController.php';

// VALIDATE DATABASE CONNECTION
if (!isset($conn)) {
  http_response_code(500);
  echo json_encode([
    "status" => "error",
    "message" => "Koneksi database (\$conn) tidak ditemukan di koneksi.php"
  ]);
  exit();
}

// INITIALIZE CONTROLLER
$leaderController = new LeaderController($conn);

// READ REQUEST PAYLOAD (JSON & FORM-DATA)
$json = file_get_contents('php://input');
$requestData = json_decode($json, true) ?? [];

if (empty($requestData)) {
  $requestData = $_POST;
}

// EXTRACT ACTION FROM GET QUERY OR REQUEST BODY
$action = $_GET['action'] ?? $requestData['action'] ?? '';

// ROUTING ENDPOINT ADMIN
switch ($action) {
  case 'update_password':
    $leaderController->updatePassword($requestData);
    break;

  // CRUD USER
  case 'create_user':
    $leaderController->createUser($requestData);
    break;

  case 'get_users_list':
    $leaderController->getUsers($requestData);
    break;

  case 'get_user_detail':
    $leaderController->getUserDetail($requestData);
    break;

  case 'update_user_profile':
    $leaderController->updateUserProfile($requestData);
    break;

  case 'update_document':
    $leaderController->updateDocument($requestData);
    break;

  case 'edit_user':
    $leaderController->editUsers($requestData);
    break;


  case 'delete_user':
    $leaderController->deleteUser($requestData);
    break;

  // CRUD EVENT
  case 'create_event':
    $leaderController->createEvent($requestData, $_FILES);
    break;

  case 'update_event':
    $leaderController->updateEvent($requestData, $_FILES);
    break;

  case 'get_events':
    $leaderController->getEvents($requestData);
    break;

  case 'get_event_detail':
    $leaderController->getEventDetail($requestData);
    break;

  case 'delete_event':
    $leaderController->deleteEvent($requestData);
    break;

  // ATTENDANCE RECAP
  case 'get_attendance_recap':
    $leaderController->getAttendanceRecap($requestData);
    break;

  case 'get_event_attendance_detail':
    $leaderController->getEventAttendanceDetail($requestData);
    break;

  case 'get_jamaah_attendance_history':
    $leaderController->getJamaahAttendanceHistory($requestData);
    break;

  default:
    http_response_code(404);
    echo json_encode([
      "status" => "failed",
      "message" => "Endpoint tidak ditemukan atau Action salah."
    ]);
    break;

}