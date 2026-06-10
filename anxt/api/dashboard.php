<?php
// api/dashboard.php
// GET /api/dashboard.php — aggregated KPIs for the dashboard overview

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/helpers.php';
require_once __DIR__ . '/../middleware/auth.php';

setCorsHeaders();
header('Content-Type: application/json; charset=utf-8');

requireAuth();

$db = getDB();

// ── Revenue collected (paid invoices) ─────────────────────────
$revStmt = $db->query(
    "SELECT
       COALESCE(SUM(total), 0)                                     AS total_revenue,
       COALESCE(SUM(CASE WHEN MONTH(issue_date)=MONTH(NOW()) AND YEAR(issue_date)=YEAR(NOW()) THEN total END), 0) AS revenue_this_month
     FROM invoices WHERE status = 'Paid'"
);
$rev = $revStmt->fetch();

// ── Active projects ───────────────────────────────────────────
$projStmt = $db->query(
    "SELECT COUNT(*) AS active_projects FROM proposals WHERE status = 'Approved'"
);
$proj = $projStmt->fetch();

// ── Pending invoices ──────────────────────────────────────────
$pendStmt = $db->query(
    "SELECT
       COUNT(*)              AS pending_count,
       COALESCE(SUM(total),0) AS pending_amount
     FROM invoices WHERE status IN ('Sent','Draft')"
);
$pend = $pendStmt->fetch();

// ── Overdue invoices ──────────────────────────────────────────
$overStmt = $db->query(
    "SELECT COUNT(*) AS overdue_count, COALESCE(SUM(total),0) AS overdue_amount
     FROM invoices WHERE status = 'Overdue'"
);
$over = $overStmt->fetch();

// ── Proposal conversion rate ──────────────────────────────────
$convStmt = $db->query(
    "SELECT
       COUNT(*) AS total,
       SUM(status = 'Approved') AS approved
     FROM proposals"
);
$conv = $convStmt->fetch();
$convRate = $conv['total'] > 0
    ? round(($conv['approved'] / $conv['total']) * 100, 1)
    : 0;

// ── Recent proposals ──────────────────────────────────────────
$rPropStmt = $db->query(
    'SELECT id, ref_number, title, client_name, value, status, created_at
     FROM v_proposals ORDER BY created_at DESC LIMIT 5'
);

// ── Recent invoices ───────────────────────────────────────────
$rInvStmt = $db->query(
    'SELECT id, invoice_number, client_name, total, status, issue_date, due_date
     FROM v_invoices ORDER BY created_at DESC LIMIT 5'
);

// ── Proposals by status ───────────────────────────────────────
$statusStmt = $db->query(
    "SELECT status, COUNT(*) AS count, COALESCE(SUM(value),0) AS value
     FROM proposals GROUP BY status"
);

jsonResponse([
    'kpis' => [
        'total_revenue'      => (float) $rev['total_revenue'],
        'revenue_this_month' => (float) $rev['revenue_this_month'],
        'active_projects'    => (int)   $proj['active_projects'],
        'pending_invoices'   => [
            'count'  => (int)   $pend['pending_count'],
            'amount' => (float) $pend['pending_amount'],
        ],
        'overdue_invoices'   => [
            'count'  => (int)   $over['overdue_count'],
            'amount' => (float) $over['overdue_amount'],
        ],
        'conversion_rate'    => $convRate,
    ],
    'proposals_by_status' => $statusStmt->fetchAll(),
    'recent_proposals'    => $rPropStmt->fetchAll(),
    'recent_invoices'     => $rInvStmt->fetchAll(),
]);
