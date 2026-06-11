<?php
// middleware/auth.php — Token-based session guard
// Compatible with nginx + PHP-FPM (uses all header-reading methods)

require_once __DIR__ . '/../config/db.php';

/**
 * Read the Authorization header across all server environments.
 * nginx+PHP-FPM does not always populate getallheaders(), so we
 * check every possible location the header might land.
 */
function getAuthHeader(): string
{
    // Method 1 — most reliable on nginx with fastcgi_param HTTP_AUTHORIZATION
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }

    // Method 2 — Apache mod_rewrite / some nginx setups
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    // Method 3 — apache_request_headers() when available
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        if (!empty($headers['Authorization'])) {
            return $headers['Authorization'];
        }
        if (!empty($headers['authorization'])) {
            return $headers['authorization'];
        }
    }

    // Method 4 — getallheaders() fallback (works on Apache, sometimes nginx)
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                return $value;
            }
        }
    }

    // Method 5 — scan all $_SERVER keys as last resort
    foreach ($_SERVER as $key => $value) {
        if (strtolower($key) === 'http_authorization') {
            return $value;
        }
    }

    return '';
}

/**
 * Require a valid Bearer token.
 * On success returns the authenticated user row.
 * On failure sends 401 JSON and exits.
 */
function requireAuth(): array
{
    $authHeader = getAuthHeader();

    if (empty($authHeader) || !preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        // 'received' helps you debug what header (if any) arrived
        echo json_encode([
            'error'    => 'No token provided.',
            'received' => substr($authHeader, 0, 30) ?: 'empty',
        ]);
        exit;
    }

    $token = hash('sha256', $m[1]);
    $db    = getDB();

    $stmt = $db->prepare(
        'SELECT s.user_id, s.expires_at,
                u.id, u.name, u.email, u.role
         FROM   sessions s
         JOIN   users    u ON u.id = s.user_id
         WHERE  s.id = ?
           AND  u.is_active = 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Invalid or expired token.']);
        exit;
    }

    if (new DateTime($row['expires_at']) < new DateTime()) {
        $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Token expired. Please log in again.']);
        exit;
    }

    return $row;
}

/**
 * Require the authenticated user to have the 'admin' role.
 */
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