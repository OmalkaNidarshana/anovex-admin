<?php
// api/clients.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user=$db=null;
$user = requireAuth();
$db   = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;

function findClient(PDO $db, int $id): array {
    $s=$db->prepare('SELECT * FROM clients WHERE id=?'); $s->execute([$id]);
    $c=$s->fetch(); if(!$c) jsonResponse(['error'=>'Client not found.'],404);
    $p=$db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(value),0) AS total FROM proposals WHERE client_id=?'); $p->execute([$id]); $c['proposals']=$p->fetch();
    $i=$db->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS total FROM invoices WHERE client_id=?'); $i->execute([$id]); $c['invoices']=$i->fetch();
    return $c;
}

if ($method==='GET') {
    if ($id) jsonResponse(findClient($db,$id));
    $search=$_GET['search']??'';
    $params=[];
    $sql='SELECT c.*, COUNT(DISTINCT p.id) AS proposal_count, COUNT(DISTINCT i.id) AS invoice_count FROM clients c LEFT JOIN proposals p ON p.client_id=c.id LEFT JOIN invoices i ON i.client_id=c.id';
    if ($search) { $sql.=' WHERE c.name LIKE ? OR c.email LIKE ?'; array_push($params,"%$search%","%$search%"); }
    $sql.=' GROUP BY c.id ORDER BY c.name';
    $s=$db->prepare($sql); $s->execute($params);
    jsonResponse($s->fetchAll());
}

if ($method==='POST') {
    $b=getBody();
    if (empty($b['name'])) jsonResponse(['error'=>'Name required.'],422);
    $a=only($b,['name','email','phone','address','country']);
    $db->prepare('INSERT INTO clients (name,email,phone,address,country,created_by) VALUES (?,?,?,?,?,?)')
       ->execute([$a['name'],$a['email']??null,$a['phone']??null,$a['address']??null,$a['country']??null,$user['id']]);
    jsonResponse(findClient($db,(int)$db->lastInsertId()),201);
}

if ($method==='PUT') {
    if (!$id) jsonResponse(['error'=>'ID required.'],400);
    findClient($db,$id);
    $b=getBody(); $a=only($b,['name','email','phone','address','country']);
    if (!$a) jsonResponse(['error'=>'Nothing to update.'],422);
    $set=array_map(fn($k)=>"$k=?",array_keys($a));
    $params=array_values($a); $params[]=$id;
    $db->prepare('UPDATE clients SET '.implode(',',$set).' WHERE id=?')->execute($params);
    jsonResponse(findClient($db,$id));
}

if ($method==='DELETE') {
    if (!$id) jsonResponse(['error'=>'ID required.'],400);
    if ($user['role']!=='admin') jsonResponse(['error'=>'Admin only.'],403);
    findClient($db,$id);
    $db->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);
    jsonResponse(['message'=>'Deleted.']);
}

jsonResponse(['error'=>'Method not allowed.'],405);
