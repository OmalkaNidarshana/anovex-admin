<?php
// middleware/auth.php — Token-based session guard

require_once __DIR__ . '/../config/db.php';

/**
 * Require a valid Bearer token.
 * On success, returns the user row as an array.
 * On failure, sends 401 and exits.
 */
function requireAuth(): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'No token provided.']);
        exit;
    }

    $token  = hash('sha256', $m[1]);   // hash for DB look-up
    $db     = getDB();

    $stmt = $db->prepare(
        'SELECT s.user_id, s.expires_at, u.id, u.name, u.email, u.role
         FROM sessions s
         JOIN users u ON u.id = s.user_id
         WHERE s.id = ? AND u.is_active = 1'
    );
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token.']);
        exit;
    }

    if (new DateTime($row['expires_at']) < new DateTime()) {
        // Clean up and reject
        $db->prepare('DELETE FROM sessions WHERE id = ?')->execute([$token]);
        http_response_code(401);
        echo json_encode(['error' => 'Token expired. Please log in again.']);
        exit;
    }

    return $row;
}

/**
 * Require the authenticated user to have the admin role.
 */
function requireAdmin(): array
{
    $user = requireAuth();
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required.']);
        exit;
    }
    return $user;
}
