<?php
// api/invoices.php
// GET    /api/invoices.php             list
// GET    /api/invoices.php?id=N        single + items
// POST   /api/invoices.php             create
// PUT    /api/invoices.php?id=N        update
// PATCH  /api/invoices.php?id=N&action=status  change status
// DELETE /api/invoices.php?id=N        delete draft

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? '';

function findInvoice(PDO $db, int $id): array {
    $s = $db->prepare('SELECT * FROM v_invoices WHERE id = ?');
    $s->execute([$id]);
    $inv = $s->fetch();
    if (!$inv) jsonResponse(['error'=>'Invoice not found.'], 404);
    $li = $db->prepare('SELECT id,description,quantity,unit_price,amount,sort_order FROM invoice_items WHERE invoice_id=? ORDER BY sort_order');
    $li->execute([$id]);
    $inv['items'] = $li->fetchAll();
    return $inv;
}

function nextInvNum(PDO $db): string {
    $y = date('Y');
    $cnt = (int)$db->prepare("SELECT COUNT(*) FROM invoices WHERE YEAR(issue_date)=?")->execute([$y]) ? 0 : 0;
    $s = $db->prepare("SELECT COUNT(*) AS c FROM invoices WHERE YEAR(issue_date)=?");
    $s->execute([$y]);
    return sprintf('INV-%s-%03d', $y, (int)$s->fetch()['c'] + 1);
}

function recalc(PDO $db, int $invId): void {
    $db->prepare('UPDATE invoices SET subtotal=(SELECT COALESCE(SUM(quantity*unit_price),0) FROM invoice_items WHERE invoice_id=?) WHERE id=?')
       ->execute([$invId,$invId]);
}

if ($method === 'GET') {
    if ($id) jsonResponse(findInvoice($db, $id));
    $where=[]; $params=[];
    if (!empty($_GET['status'])) { $where[]='status=?'; $params[]=$_GET['status']; }
    if (!empty($_GET['search'])) { $where[]='(invoice_number LIKE ? OR client_name LIKE ?)'; $t='%'.$_GET['search'].'%'; array_push($params,$t,$t); }
    $sql='SELECT * FROM v_invoices'.($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY created_at DESC';
    $page=max(1,(int)($_GET['page']??1)); $pp=min(100,(int)($_GET['per_page']??20));
    $cs=$db->prepare(str_replace('SELECT *','SELECT COUNT(*) AS c',$sql)); $cs->execute($params);
    $total=(int)$cs->fetch()['c'];
    $sql.=" LIMIT $pp OFFSET ".(($page-1)*$pp);
    $s=$db->prepare($sql); $s->execute($params);
    jsonResponse(['data'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$pp,'last_page'=>(int)ceil($total/$pp)]);
}

if ($method === 'POST') {
    $b = getBody();
    $miss = missingFields($b,['client_id','issue_date','due_date']);
    if ($miss) jsonResponse(['error'=>'Missing: '.implode(', ',$miss)],422);
    $items = $b['items'] ?? [];

    $db->beginTransaction();
    try {
        $num = nextInvNum($db);
        $db->prepare('INSERT INTO invoices (invoice_number,client_id,proposal_id,subtotal,tax_rate,status,issue_date,due_date,notes,created_by) VALUES (?,?,?,0,?,?,?,?,?,?)')
           ->execute([$num,(int)$b['client_id'],$b['proposal_id']??null,(float)($b['tax_rate']??0),$b['status']??'Draft',$b['issue_date'],$b['due_date'],$b['notes']??null,$user['id']]);
        $invId=(int)$db->lastInsertId();
        if ($items) {
            $is=$db->prepare('INSERT INTO invoice_items (invoice_id,description,quantity,unit_price,sort_order) VALUES (?,?,?,?,?)');
            foreach ($items as $i=>$item) $is->execute([$invId,$item['description']??'Service',(float)($item['quantity']??1),(float)($item['unit_price']??0),$i]);
            recalc($db,$invId);
        }
        $db->commit();
        jsonResponse(findInvoice($db,$invId),201);
    } catch(Exception $e) { $db->rollBack(); jsonResponse(['error'=>$e->getMessage()],500); }
}

if ($method === 'PATCH' && $action === 'status') {
    if (!$id) jsonResponse(['error'=>'ID required.'],400);
    findInvoice($db,$id);
    $b=$getBody??getBody();
    $status=$b['status']??'';
    if (!in_array($status,['Draft','Sent','Paid','Overdue','Cancelled'])) jsonResponse(['error'=>'Invalid status.'],422);
    $paid = $status==='Paid' ? date('Y-m-d H:i:s') : null;
    $db->prepare('UPDATE invoices SET status=?,paid_at=? WHERE id=?')->execute([$status,$paid,$id]);
    jsonResponse(findInvoice($db,$id));
}

if ($method === 'DELETE') {
    if (!$id) jsonResponse(['error'=>'ID required.'],400);
    if ($user['role']!=='admin') jsonResponse(['error'=>'Admin only.'],403);
    $inv=findInvoice($db,$id);
    if (!in_array($inv['status'],['Draft','Cancelled'])) jsonResponse(['error'=>'Only Draft/Cancelled invoices can be deleted.'],409);
    $db->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
    jsonResponse(['message'=>'Deleted.']);
}

jsonResponse(['error'=>'Method not allowed.'],405);
