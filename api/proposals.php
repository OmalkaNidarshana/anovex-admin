<?php
// api/proposals.php
// GET    /api/proposals.php          list
// GET    /api/proposals.php?id=N     single
// POST   /api/proposals.php          create
// PUT    /api/proposals.php?id=N     update
// DELETE /api/proposals.php?id=N     delete

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user   = requireAuth();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

function findProposal(PDO $db, int $id): array {
    $s = $db->prepare('SELECT * FROM v_proposals WHERE id = ?');
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) jsonResponse(['error' => 'Proposal not found.'], 404);
    return $r;
}

function nextRef(PDO $db): string {
    $cnt = (int)$db->query('SELECT COUNT(*) FROM proposals')->fetchColumn();
    return sprintf('PRO-%03d', $cnt + 1);
}

if ($method === 'GET') {
    if ($id) jsonResponse(findProposal($db, $id));

    $where = []; $params = [];
    if (!empty($_GET['status']))  { $where[] = 'status = ?';  $params[] = $_GET['status']; }
    if (!empty($_GET['search']))  {
        $where[] = '(title LIKE ? OR client_name LIKE ? OR ref_number LIKE ?)';
        $t = '%'.$_GET['search'].'%';
        array_push($params, $t, $t, $t);
    }

    $sql = 'SELECT * FROM v_proposals' . ($where ? ' WHERE '.implode(' AND ',$where) : '') . ' ORDER BY created_at DESC';
    $page = max(1,(int)($_GET['page']??1)); $pp = min(100,(int)($_GET['per_page']??20));
    $total = (int)$db->prepare(str_replace('SELECT *','SELECT COUNT(*)',$sql))->execute($params) ? $db->prepare(str_replace('SELECT *','SELECT COUNT(*) AS c',$sql))->execute($params) : 0;

    $cs = $db->prepare(str_replace('SELECT *','SELECT COUNT(*) AS c',$sql));
    $cs->execute($params);
    $total = (int)$cs->fetch()['c'];

    $sql .= " LIMIT $pp OFFSET ".(($page-1)*$pp);
    $s = $db->prepare($sql); $s->execute($params);

    jsonResponse(['data'=>$s->fetchAll(),'total'=>$total,'page'=>$page,'per_page'=>$pp,'last_page'=>(int)ceil($total/$pp)]);
}

if ($method === 'POST') {
    $b = getBody();
    $miss = missingFields($b, ['client_id','title','value']);
    if ($miss) jsonResponse(['error'=>'Missing: '.implode(', ',$miss)], 422);

    $db->prepare('INSERT INTO proposals (ref_number,client_id,title,description,value,status,deadline,progress,created_by) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([nextRef($db),(int)$b['client_id'],$b['title'],$b['description']??null,(float)$b['value'],$b['status']??'Pending',$b['deadline']??null,(int)($b['progress']??0),$user['id']]);

    jsonResponse(findProposal($db,(int)$db->lastInsertId()), 201);
}

if (in_array($method,['PUT','PATCH'])) {
    if (!$id) jsonResponse(['error'=>'ID required.'], 400);
    findProposal($db, $id);
    $b = getBody();
    $allowed = only($b, ['client_id','title','description','value','status','deadline','progress']);
    if (!$allowed) jsonResponse(['error'=>'Nothing to update.'], 422);

    $set = []; $params = [];
    foreach ($allowed as $k=>$v) { $set[] = "$k=?"; $params[] = in_array($k,['client_id','progress'])?(int)$v:(($k==='value')?(float)$v:$v); }
    $params[] = $id;
    $db->prepare('UPDATE proposals SET '.implode(',',$set).' WHERE id=?')->execute($params);
    jsonResponse(findProposal($db, $id));
}

if ($method === 'DELETE') {
    if (!$id) jsonResponse(['error'=>'ID required.'], 400);
    if ($user['role'] !== 'admin') jsonResponse(['error'=>'Admin only.'], 403);
    findProposal($db, $id);
    $db->prepare('DELETE FROM proposals WHERE id=?')->execute([$id]);
    jsonResponse(['message'=>'Deleted.']);
}

jsonResponse(['error'=>'Method not allowed.'], 405);
