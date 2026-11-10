<?php
// Restrict execution to CLI (Cron Job) or Localhost environment
$isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

if (php_sapi_name() !== 'cli' && !$isLocalhost) {
  http_response_code(403);
  exit('Forbidden: Direct access not allowed.');
}

require_once __DIR__ . '/api/koneksi.php';

try {

  // 1. Update expired events to 'selesai'
  $stmt1 = $conn->prepare("
        UPDATE events 
        SET status = 'selesai' 
        WHERE status != 'selesai' 
          AND waktu_selesai <= NOW()
    ");
  $stmt1->execute();
  $updatedToSelesai = $stmt1->rowCount();

  // 2. Update upcoming active events to 'live'
  $stmt2 = $conn->prepare("
        UPDATE events 
        SET status = 'live' 
        WHERE status = 'mendatang' 
          AND NOW() BETWEEN waktu_event AND waktu_selesai
    ");
  $stmt2->execute();
  $updatedToLive = $stmt2->rowCount();

  $message = sprintf(
    "[%s] Event Status Sync Completed. Updated to Selesai: %d, Updated to Live: %d\n",
    date('Y-m-d H:i:s'),
    $updatedToSelesai,
    $updatedToLive
  );

  echo $message;

} catch (Exception $e) {
  error_log("Cron Sync Error: " . $e->getMessage());
  echo "Error executing event status sync: " . $e->getMessage();
}