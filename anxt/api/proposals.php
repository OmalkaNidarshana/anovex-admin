<?php
// api/proposals.php
// GET    /api/proposals.php             — list all proposals
// GET    /api/proposals.php?id=1        — single proposal
// POST   /api/proposals.php             — create proposal
// PUT    /api/proposals.php?id=1        — update proposal
// PATCH  /api/proposals.php?id=1        — partial update (e.g. progress, status)
// DELETE /api/proposals.php?id=1        — delete proposal

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$user   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

// ─── HELPERS ─────────────────────────────────────────────────
function findProposal(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM v_proposals WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        jsonResponse(['error' => 'Proposal not found.'], 404);
    }
    return $row;
}

function nextRefNumber(PDO $db): string
{
    $stmt = $db->query('SELECT COUNT(*) AS cnt FROM proposals');
    $cnt  = (int) $stmt->fetch()['cnt'];
    return sprintf('PRO-%03d', $cnt + 1);
}

// ─── GET ─────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        jsonResponse(findProposal($db, $id));
    }

    // Filters
    $where  = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[]  = 'status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['client_id'])) {
        $where[]  = 'client_id = ?';    // v_proposals doesn't expose client_id; join on raw table
        $params[] = (int) $_GET['client_id'];
    }
    if (!empty($_GET['search'])) {
        $where[]  = '(title LIKE ? OR client_name LIKE ? OR ref_number LIKE ?)';
        $term     = '%' . $_GET['search'] . '%';
        array_push($params, $term, $term, $term);
    }

    $sql = 'SELECT * FROM v_proposals';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';

    // Pagination
    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    // Total count
    $countStmt = $db->prepare(str_replace('SELECT *', 'SELECT COUNT(*) AS cnt', $sql));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['cnt'];

    $sql .= " LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    jsonResponse([
        'data'       => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'last_page'  => (int) ceil($total / $perPage),
    ]);
}

// ─── POST (create) ───────────────────────────────────────────
if ($method === 'POST') {
    $body    = getBody();
    $missing = missingFields($body, ['client_id', 'title', 'value']);

    if ($missing) {
        jsonResponse(['error' => 'Missing required fields.', 'fields' => $missing], 422);
    }

    $allowed  = only($body, ['client_id','title','description','value','status','deadline','progress']);
    $refNum   = nextRefNumber($db);

    $stmt = $db->prepare(
        'INSERT INTO proposals
           (ref_number, client_id, title, description, value, status, deadline, progress, created_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $refNum,
        (int)   $allowed['client_id'],
                $allowed['title'],
                $allowed['description']   ?? null,
        (float) $allowed['value'],
                $allowed['status']        ?? 'Pending',
                $allowed['deadline']      ?? null,
        (int)  ($allowed['progress']      ?? 0),
        $user['id'],
    ]);

    $newId = (int) $db->lastInsertId();
    jsonResponse(findProposal($db, $newId), 201);
}

// ─── PUT / PATCH (update) ────────────────────────────────────
if (in_array($method, ['PUT', 'PATCH'])) {
    if (!$id) {
        jsonResponse(['error' => 'Proposal ID required.'], 400);
    }

    findProposal($db, $id);   // 404 if missing

    $body    = getBody();
    $allowed = only($body, ['client_id','title','description','value','status','deadline','progress']);

    if (empty($allowed)) {
        jsonResponse(['error' => 'No updatable fields provided.'], 422);
    }

    $setClauses = [];
    $params     = [];

    $castMap = [
        'client_id' => 'int',
        'value'     => 'float',
        'progress'  => 'int',
    ];

    foreach ($allowed as $col => $val) {
        $setClauses[] = "$col = ?";
        $params[]     = isset($castMap[$col]) ? ($castMap[$col] === 'int' ? (int)$val : (float)$val) : $val;
    }
    $params[] = $id;

    $db->prepare('UPDATE proposals SET ' . implode(', ', $setClauses) . ' WHERE id = ?')
       ->execute($params);

    jsonResponse(findProposal($db, $id));
}

// ─── DELETE ──────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) {
        jsonResponse(['error' => 'Proposal ID required.'], 400);
    }

    findProposal($db, $id);   // 404 if missing

    // Only admin can delete
    if ($user['role'] !== 'admin') {
        jsonResponse(['error' => 'Admin access required.'], 403);
    }

    $db->prepare('DELETE FROM proposals WHERE id = ?')->execute([$id]);
    jsonResponse(['message' => 'Proposal deleted.']);
}

jsonResponse(['error' => 'Method not allowed.'], 405);
