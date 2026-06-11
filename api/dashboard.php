<?php
// api/dashboard.php — GET /api/dashboard.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

requireAuth();
$db = getDB();

$rev  = $db->query("SELECT COALESCE(SUM(total),0) AS total_revenue,
    COALESCE(SUM(CASE WHEN MONTH(issue_date)=MONTH(NOW()) AND YEAR(issue_date)=YEAR(NOW()) THEN total END),0) AS this_month
    FROM invoices WHERE status='Paid'")->fetch();

$proj = $db->query("SELECT COUNT(*) AS cnt FROM proposals WHERE status='Approved'")->fetch();

$pend = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS amt
    FROM invoices WHERE status IN ('Sent','Draft')")->fetch();

$over = $db->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS amt
    FROM invoices WHERE status='Overdue'")->fetch();

$conv = $db->query("SELECT COUNT(*) AS total, SUM(status='Approved') AS approved FROM proposals")->fetch();
$rate = $conv['total'] > 0 ? round(($conv['approved'] / $conv['total']) * 100, 1) : 0;

$recent_proposals = $db->query(
    'SELECT id, ref_number, title, client_name, value, status, progress, created_at
     FROM v_proposals ORDER BY created_at DESC LIMIT 5')->fetchAll();

$recent_invoices = $db->query(
    'SELECT id, invoice_number, client_name, total, status, issue_date, due_date
     FROM v_invoices ORDER BY created_at DESC LIMIT 5')->fetchAll();

jsonResponse([
    'kpis' => [
        'total_revenue'    => (float) $rev['total_revenue'],
        'revenue_month'    => (float) $rev['this_month'],
        'active_projects'  => (int)   $proj['cnt'],
        'pending_invoices' => ['count' => (int)$pend['cnt'], 'amount' => (float)$pend['amt']],
        'overdue_invoices' => ['count' => (int)$over['cnt'], 'amount' => (float)$over['amt']],
        'conversion_rate'  => $rate,
    ],
    'recent_proposals' => $recent_proposals,
    'recent_invoices'  => $recent_invoices,
]);
