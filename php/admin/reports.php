<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$admin = require_admin();

$status = (string)Request::get('status', Request::STRING, 'pending');
if (!in_array($status, ['pending', 'resolved', 'dismissed', 'all'], true)) {
    $status = 'pending';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)Request::post('report_id', Request::INT, 0);
    $action = (string)Request::post('action', Request::STRING, '');
    if (in_array($action, ['resolved', 'dismissed'], true)) {
        $stmt = db()->prepare("
            SELECT r.id,r.question_id,q.status question_status
            FROM question_reports r JOIN questions q ON q.id=r.question_id
            WHERE r.id=? LIMIT 1
        ");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if ($report) {
            $update = db()->prepare('UPDATE question_reports SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?');
            $update->execute([$action, (int)$admin['id'], $id]);
            question_history_add(
                (int)$report['question_id'],
                (int)$admin['id'],
                'report_resolved',
                (string)$report['question_status'],
                (string)$report['question_status'],
                $action
            );
            audit_log((int)$admin['id'], 'report.' . $action, 'report', $id, 'question:' . (int)$report['question_id']);
            flash('success', 'Denúncia analisada.');
        }
    }
    redirect('admin/reports.php?status=' . urlencode($status));
}

$where = $status === 'all' ? '' : 'WHERE r.status=?';
$sql = "
    SELECT r.*,q.title,u.name reporter_name,u.email reporter_email
    FROM question_reports r
    JOIN questions q ON q.id=r.question_id
    JOIN users u ON u.id=r.user_id
    {$where}
    ORDER BY r.created_at DESC
    LIMIT 200
";
$stmt = db()->prepare($sql);
$stmt->execute($status === 'all' ? [] : [$status]);
$rows = $stmt->fetchAll();

$labels = [
    'spam' => 'Spam',
    'offensive' => 'Conteúdo ofensivo',
    'illegal' => 'Conteúdo ilegal',
    'duplicate' => 'Duplicada',
    'other' => 'Outro',
];

$reports = array_map(static fn(array $row): array => [
    'id' => (int)$row['id'],
    'question_id' => (int)$row['question_id'],
    'question_title' => (string)$row['title'],
    'reason' => $labels[(string)$row['reason']] ?? (string)$row['reason'],
    'details' => (string)($row['details'] ?? ''),
    'reporter_name' => (string)$row['reporter_name'],
    'reporter_email' => (string)$row['reporter_email'],
    'status' => (string)$row['status'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$row['created_at'])),
    'question_url' => base_url('admin/question.php?id=' . (int)$row['question_id']),
], $rows);

$filters = [];
foreach (['pending' => 'Pendentes', 'resolved' => 'Resolvidas', 'dismissed' => 'Descartadas', 'all' => 'Todas'] as $value => $label) {
    $filters[] = [
        'label' => $label,
        'url' => base_url('admin/reports.php?status=' . $value),
        'active_class' => $status === $value ? 'active' : '',
    ];
}

render_page('admin/reports', [
    'reports' => $reports,
    'has_reports' => $reports !== [],
    'filters' => $filters,
    'count' => count($reports),
], 'Denúncias', true);
