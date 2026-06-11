<?php
// middleware/auth.php
// nginx + PHP-FPM compatible — reads Authorization header via every possible method

require_once __DIR__ . '/../config/db.php';

function getAuthHeader(): string
{
    // 1. $_SERVER['HTTP_AUTHORIZATION'] — works when nginx has:
    //    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    if (!empty($_SERVER['HTTP_AUTHORIZATION']))
        return trim($_SERVER['HTTP_AUTHORIZATION']);

    // 2. REDIRECT variant (some nginx rewrite setups)
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))
        return trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

    // 3. apache_request_headers() — available on some PHP-FPM builds
    if (function_exists('apache_request_headers')) {
        $h = apache_request_headers();
        if (!empty($h['Authorization']))  return trim($h['Authorization']);
        if (!empty($h['authorization']))  return trim($h['authorization']);
    }

    // 4. getallheaders() — case-insensitive scan
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') return trim($v);
        }
    }

    // 5. Full $_SERVER scan as last resort
    foreach ($_SERVER as $k => $v) {
        if (strtolower($k) === 'http_authorization') return trim($v);
    }

    return '';
}

function requireAuth(): array
{
    $header = getAuthHeader();

    if (!$header || !preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No token provided.']);
        exit;
    }

    $token = hash('sha256', $m[1]);
    $db    = getDB();

    $stmt = $db->prepare(
        'SELECT s.expires_at, u.id, u.name, u.email, u.role
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid or expired token.']);
        exit;
    }

    if (strtotime($row['expires_at']) < time()) {
        $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Session expired. Please log in again.']);
        exit;
    }

    return $row;
}

function requireAdmin(): array
{
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
    return $user;
}
