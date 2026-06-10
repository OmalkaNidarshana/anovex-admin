<?php
// api/auth.php
// POST /api/auth.php?action=login   — sign in, receive token
// POST /api/auth.php?action=logout  — invalidate token
// GET  /api/auth.php?action=me      — get current user info

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// ─── LOGIN ───────────────────────────────────────────────────
if ($action === 'login' && $method === 'POST') {
    $body  = getBody();
    $email = trim($body['email'] ?? '');
    $pass  = $body['password'] ?? '';

    if (!$email || !$pass) {
        jsonResponse(['error' => 'Email and password are required.'], 422);
    }

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    print_r($user);
    if (!$user || !password_verify($pass, $user['password'])) {
        jsonResponse(['error' => 'Invalid credentials.'], 401);
    }

    // Generate token
    $rawToken   = bin2hex(random_bytes(32));           // 64-char hex token
    $hashedToken = hash('sha256', $rawToken);
    $expiresAt  = (new DateTime('+8 hours'))->format('Y-m-d H:i:s');

    $db->prepare(
        'INSERT INTO sessions (id, user_id, ip_address, user_agent, expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $hashedToken,
        $user['id'],
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $expiresAt,
    ]);

    // Update last login
    $db->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

    jsonResponse([
        'token'      => $rawToken,
        'expires_at' => $expiresAt,
        'user' => [
            'id'    => $user['id'],
            'name'  => $user['name'],
            'email' => $user['email'],
            'role'  => $user['role'],
        ],
    ]);
}

// ─── LOGOUT ──────────────────────────────────────────────────
if ($action === 'logout' && $method === 'POST') {
    $headers    = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        $hashed = hash('sha256', $m[1]);
        getDB()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$hashed]);
    }

    jsonResponse(['message' => 'Logged out successfully.']);
}

// ─── CURRENT USER ────────────────────────────────────────────
if ($action === 'me' && $method === 'GET') {
    $user = requireAuth();
    jsonResponse([
        'id'         => $user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
    ]);
}

jsonResponse(['error' => 'Unknown action.'], 400);
