<?php
// api/auth.php
// POST /api/auth.php?action=login
// POST /api/auth.php?action=logout
// GET  /api/auth.php?action=me

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ── LOGIN ─────────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $body  = getBody();
    $email = trim($body['email'] ?? '');
    $pass  = trim($body['password'] ?? '');

    if (!$email || !$pass)
        jsonResponse(['error' => 'Email and password are required.'], 422);

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password']))
        jsonResponse(['error' => 'Invalid credentials.'], 401);

    // Generate token
    $rawToken    = bin2hex(random_bytes(32));
    $hashedToken = hash('sha256', $rawToken);
    $expiresAt   = date('Y-m-d H:i:s', strtotime('+8 hours'));

    $db->prepare(
        'INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $hashedToken,
        $user['id'],
        $_SERVER['REMOTE_ADDR']     ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $expiresAt,
    ]);

    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')
       ->execute([$user['id']]);

    jsonResponse([
        'token'      => $rawToken,
        'expires_at' => $expiresAt,
        'user'       => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ]);
}

// ── LOGOUT ────────────────────────────────────────────────────
if ($action === 'logout' && $method === 'POST') {
    $header = getAuthHeader();
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        getDB()->prepare('DELETE FROM sessions WHERE id = ?')
               ->execute([hash('sha256', $m[1])]);
    }
    jsonResponse(['message' => 'Logged out.']);
}

// ── ME ────────────────────────────────────────────────────────
if ($action === 'me' && $method === 'GET') {
    $user = requireAuth();
    jsonResponse([
        'id'    => $user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ]);
}

jsonResponse(['error' => 'Unknown action.'], 400);
