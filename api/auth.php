<?php
// api/auth.php
// Shared session helpers for endpoints that require a verified login.

/**
 * Start the API session with cookie settings that work for the local
 * frontend (same-site) and for HTTPS deployments (cross-site).
 */
function startApiSession(): void
{
  if (session_status() === PHP_SESSION_ACTIVE) {
    return;
  }

  $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => $isHttps ? 'None' : 'Lax',
  ]);

  session_start();
}

/**
 * Resolve the logged-in user from the session, refreshing role/zone from
 * the database so stale session data cannot grant access.
 *
 * @return array{id:int,nama_lengkap:string,role:?string,zona:?string}|null
 */
function getAuthenticatedUser(PDO $conn): ?array
{
  startApiSession();

  $userId = $_SESSION['user_id'] ?? null;
  if (empty($userId)) {
    return null;
  }

  $stmt = $conn->prepare("SELECT id, nama_lengkap, role, zona FROM users WHERE id = ? LIMIT 1");
  $stmt->execute([$userId]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$user) {
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['zona']);
    return null;
  }

  $_SESSION['role'] = $user['role'];
  $_SESSION['zona'] = $user['zona'];

  return $user;
}
