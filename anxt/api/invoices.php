<?php
// api/invoices.php
// GET    /api/invoices.php                       — list invoices
// GET    /api/invoices.php?id=1                  — single invoice with line items
// POST   /api/invoices.php                       — create invoice + line items
// PUT    /api/invoices.php?id=1                  — full update
// PATCH  /api/invoices.php?id=1&action=status    — change status (e.g. mark paid)
// DELETE /api/invoices.php?id=1                  — delete draft invoice

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

$user   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$action = $_GET['action'] ?? '';

// ─── HELPERS ─────────────────────────────────────────────────
function findInvoice(PDO $db, int $id): array
{
    $stmt = $db->prepare('SELECT * FROM v_invoices WHERE id = ?');
    $stmt->execute([$id]);
    $inv = $stmt->fetch();
    if (!$inv) {
        jsonResponse(['error' => 'Invoice not found.'], 404);
    }

    // Attach line items
    $lStmt = $db->prepare(
        'SELECT id, description, quantity, unit_price, amount, sort_order
         FROM invoice_items WHERE invoice_id = ? ORDER BY sort_order'
    );
    $lStmt->execute([$id]);
    $inv['items'] = $lStmt->fetchAll();

    return $inv;
}

function nextInvoiceNumber(PDO $db): string
{
    $year = date('Y');
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM invoices WHERE YEAR(issue_date) = ?");
    $stmt->execute([$year]);
    $cnt = (int) $stmt->fetch()['cnt'];
    return sprintf('INV-%s-%03d', $year, $cnt + 1);
}

function recalcSubtotal(PDO $db, int $invoiceId): void
{
    $stmt = $db->prepare(
        'UPDATE invoices
         SET subtotal = (SELECT COALESCE(SUM(quantity * unit_price), 0)
                         FROM invoice_items WHERE invoice_id = ?)
         WHERE id = ?'
    );
    $stmt->execute([$invoiceId, $invoiceId]);
}

// ─── GET ─────────────────────────────────────────────────────
if ($method === 'GET') {
    if ($id) {
        jsonResponse(findInvoice($db, $id));
    }

    $where  = [];
    $params = [];

    if (!empty($_GET['status'])) {
        $where[]  = 'status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['client_id'])) {
        $where[]  = 'i.client_id = ?';
        $params[] = (int) $_GET['client_id'];
    }
    if (!empty($_GET['search'])) {
        $where[]  = '(invoice_number LIKE ? OR client_name LIKE ?)';
        $term     = '%' . $_GET['search'] . '%';
        array_push($params, $term, $term);
    }

    $sql = 'SELECT * FROM v_invoices';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY created_at DESC';

    $page    = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset  = ($page - 1) * $perPage;

    $countStmt = $db->prepare(str_replace('SELECT *', 'SELECT COUNT(*) AS cnt', $sql));
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['cnt'];

    $sql .= " LIMIT $perPage OFFSET $offset";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    jsonResponse([
        'data'      => $stmt->fetchAll(),
        'total'     => $total,
        'page'      => $page,
        'per_page'  => $perPage,
        'last_page' => (int) ceil($total / $perPage),
    ]);
}

// ─── POST (create) ───────────────────────────────────────────
if ($method === 'POST') {
    $body    = getBody();
    $missing = missingFields($body, ['client_id', 'issue_date', 'due_date']);

    if ($missing) {
        jsonResponse(['error' => 'Missing required fields.', 'fields' => $missing], 422);
    }

    $items = $body['items'] ?? [];

    $db->beginTransaction();
    try {
        $invNum = nextInvoiceNumber($db);

        $stmt = $db->prepare(
            'INSERT INTO invoices
               (invoice_number, client_id, proposal_id, subtotal, tax_rate,
                status, issue_date, due_date, notes, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $invNum,
            (int)   $body['client_id'],
                    $body['proposal_id']  ?? null,
            0.00,                                   // recalculated below
            (float)($body['tax_rate']     ?? 0),
                    $body['status']        ?? 'Draft',
                    $body['issue_date'],
                    $body['due_date'],
                    $body['notes']         ?? null,
            $user['id'],
        ]);

        $invoiceId = (int) $db->lastInsertId();

        // Insert line items
        if (!empty($items) && is_array($items)) {
            $iStmt = $db->prepare(
                'INSERT INTO invoice_items
                   (invoice_id, description, quantity, unit_price, sort_order)
                 VALUES (?,?,?,?,?)'
            );
            foreach ($items as $i => $item) {
                $iStmt->execute([
                    $invoiceId,
                    $item['description'] ?? 'Service',
                    (float)($item['quantity']   ?? 1),
                    (float)($item['unit_price']  ?? 0),
                    $i,
                ]);
            }
            recalcSubtotal($db, $invoiceId);
        }

        $db->commit();
        jsonResponse(findInvoice($db, $invoiceId), 201);

    } catch (Exception $e) {
        $db->rollBack();
        jsonResponse(['error' => 'Failed to create invoice.', 'detail' => $e->getMessage()], 500);
    }
}

// ─── PATCH — change status ───────────────────────────────────
if ($method === 'PATCH' && $action === 'status') {
    if (!$id) {
        jsonResponse(['error' => 'Invoice ID required.'], 400);
    }

    findInvoice($db, $id);

    $body   = getBody();
    $status = $body['status'] ?? '';
    $allowed = ['Draft','Sent','Paid','Overdue','Cancelled'];

    if (!in_array($status, $allowed)) {
        jsonResponse(['error' => 'Invalid status. Allowed: ' . implode(', ', $allowed)], 422);
    }

    $paidAt = ($status === 'Paid') ? date('Y-m-d H:i:s') : null;

    $db->prepare('UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?')
       ->execute([$status, $paidAt, $id]);

    jsonResponse(findInvoice($db, $id));
}

// ─── PUT (full update) ───────────────────────────────────────
if ($method === 'PUT') {
    if (!$id) {
        jsonResponse(['error' => 'Invoice ID required.'], 400);
    }

    $inv  = findInvoice($db, $id);

    if ($inv['status'] === 'Paid') {
        jsonResponse(['error' => 'Cannot edit a paid invoice.'], 409);
    }

    $body    = getBody();
    $allowed = only($body, ['client_id','proposal_id','tax_rate','status','issue_date','due_date','notes']);
    $items   = $body['items'] ?? null;

    if (!empty($allowed)) {
        $setClauses = [];
        $params     = [];
        foreach ($allowed as $col => $val) {
            $setClauses[] = "$col = ?";
            $params[]     = in_array($col, ['client_id']) ? (int)$val
                          : (in_array($col, ['tax_rate']) ? (float)$val : $val);
        }
        $params[] = $id;
        $db->prepare('UPDATE invoices SET ' . implode(', ', $setClauses) . ' WHERE id = ?')
           ->execute($params);
    }

    if (is_array($items)) {
        // Replace all line items
        $db->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$id]);
        $iStmt = $db->prepare(
            'INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, sort_order)
             VALUES (?,?,?,?,?)'
        );
        foreach ($items as $i => $item) {
            $iStmt->execute([
                $id,
                $item['description'] ?? 'Service',
                (float)($item['quantity']  ?? 1),
                (float)($item['unit_price'] ?? 0),
                $i,
            ]);
        }
        recalcSubtotal($db, $id);
    }

    jsonResponse(findInvoice($db, $id));
}

// ─── DELETE ──────────────────────────────────────────────────
if ($method === 'DELETE') {
    if (!$id) {
        jsonResponse(['error' => 'Invoice ID required.'], 400);
    }

    $inv = findInvoice($db, $id);

    if (!in_array($inv['status'], ['Draft', 'Cancelled'])) {
        jsonResponse(['error' => 'Only Draft or Cancelled invoices can be deleted.'], 409);
    }

    if ($user['role'] !== 'admin') {
        jsonResponse(['error' => 'Admin access required.'], 403);
    }

    $db->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    jsonResponse(['message' => 'Invoice deleted.']);
}

jsonResponse(['error' => 'Method not allowed.'], 405);
