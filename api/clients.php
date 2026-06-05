<?php
// api/clients.php
// GET    /api/clients.php         — list all clients
// GET    /api/clients.php?id=1    — single client + their proposals/invoices
// POST   /api/clients.php         — create client
// PUT    /api/clients.php?id=1    — update client
// DELETE /api/clients.php?id=1    — delete client (admin only)

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$user   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

function findClient(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM clients WHERE id = ?');
    $stmt->execute([$id]);
    $c = $stmt->fetch();
    if (!$c) {
        jsonResponse(['error' => 'Client not found.'], 404);
    }

    // Attach summary counts
    $pStmt = $db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(value),0) AS total FROM proposals WHERE client_id = ?');
    $pStmt->execute([$id]);
    $c['proposals'] = $pStmt->fetch();

    $iStmt = $db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS total FROM invoices WHERE client_id = ?');
    $iStmt->execute([$id]);
    $c['invoices'] = $iStmt->fetch();

    return $c;
}

if ($method === 'GET') {
    if ($id) {
        jsonResponse(findClient($db, $id));
    }

    $search = $_GET['search'] ?? '';
    $params = [];
    $sql    = 'SELECT c.*, COUNT(DISTINCT p.id) AS proposal_count, COUNT(DISTINCT i.id) AS invoice_count
               FROM clients c
               LEFT JOIN proposals p ON p.client_id = c.id
               LEFT JOIN invoices  i ON i.client_id = c.id';

    if ($search) {
        $sql .= ' WHERE c.name LIKE ? OR c.email LIKE ?';
        array_push($params, "%$search%", "%$search%");
    }

    $sql .= ' GROUP BY c.id ORDER BY c.name';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

if ($method === 'POST') {
    $body    = getBody();
    $missing = missingFields($body, ['name']);
    if ($missing) {
        jsonResponse(['error' => 'Client name is required.'], 422);
    }

    $allowed = only($body, ['name','email','phone','address','country']);
    $stmt    = $db->prepare(
        'INSERT INTO clients (name, email, phone, address, country, created_by) VALUES (?,?,?,?,?,?)'
    );
    $stmt->execute([
        $allowed['name'],
        $allowed['email']   ?? null,
        $allowed['phone']   ?? null,
        $allowed['address'] ?? null,
        $allowed['country'] ?? null,
        $user['id'],
    ]);

    jsonResponse(findClient($db, (int)$db->lastInsertId()), 201);
}

if ($method === 'PUT') {
    if (!$id) jsonResponse(['error' => 'Client ID required.'], 400);
    findClient($db, $id);

    $body    = getBody();
    $allowed = only($body, ['name','email','phone','address','country']);

    if (empty($allowed)) {
        jsonResponse(['error' => 'No updatable fields.'], 422);
    }

    $set    = array_map(fn($k) => "$k = ?", array_keys($allowed));
    $params = array_values($allowed);
    $params[] = $id;

    $db->prepare('UPDATE clients SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);
    jsonResponse(findClient($db, $id));
}

if ($method === 'DELETE') {
    if (!$id) jsonResponse(['error' => 'Client ID required.'], 400);
    if ($user['role'] !== 'admin') jsonResponse(['error' => 'Admin access required.'], 403);

    findClient($db, $id);
    $db->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
    jsonResponse(['message' => 'Client deleted.']);
}

jsonResponse(['error' => 'Method not allowed.'], 405);
