<?php
function logIncomingRequest()
{
  $logDir = dirname(__DIR__) . '/logs';
  $logFile = $logDir . '/api_request_' . date('Y-m-d') . '.log';

  if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
  }

  $time = date('Y-m-d H:i:s');
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
  $method = $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN';
  $uri = $_SERVER['REQUEST_URI'] ?? 'UNKNOWN';

  // 1. Ambil data input (JSON atau Form-Data)
  $rawInput = file_get_contents('php://input');
  $payload = json_decode($rawInput, true);

  if (!$payload) {
    $payload = $_POST; // Fallback ke $_POST jika bukan JSON
  }

  // 2. Sensor data sensitif (keamanan)
  $sensitiveKeys = ['password', 'secret', 'token', 'pin'];
  array_walk_recursive($payload, function (&$value, $key) use ($sensitiveKeys) {
    if (in_array(strtolower($key), $sensitiveKeys)) {
      $value = '********';
    }
  });

  // 3. Catat informasi file jika ada upload file
  $fileInfo = [];
  if (!empty($_FILES)) {
    foreach ($_FILES as $key => $file) {
      $fileInfo[$key] = [
        'name' => $file['name'] ?? '',
        'type' => $file['type'] ?? '',
        'size' => ($file['size'] ?? 0) . ' bytes'
      ];
    }
  }

  // 4. Susun struktur Log (Format JSON agar mudah dibaca/di-parsing nantinya)
  $logData = [
    'timestamp' => $time,
    'ip' => $ip,
    'method' => $method,
    'uri' => $uri,
    'payload' => $payload,
    'files' => $fileInfo
  ];

  // Tulis ke file log
  file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT) . PHP_EOL . "=========================================================" . PHP_EOL, FILE_APPEND);
}
