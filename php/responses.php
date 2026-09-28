<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$user = require_login();

if (!in_array((string)$user['role'], ['respondent', 'admin'], true)) {
    http_response_code(403);
    exit('Esta área é exclusiva para contas respondentes.');
}

$status = (string)Request::get('status', Request::STRING, '');
if (!in_array($status, ['', 'open', 'waiting', 'taken', 'answered'], true)) {
    $status = '';
}

$where = ["q.deleted_at IS NULL", "q.moderation_status='approved'"];
$params = [];

if ((string)$user['role'] !== 'admin') {
    $where[] = 'EXISTS (SELECT 1 FROM target_users tu WHERE tu.target_id=q.target_id AND tu.user_id=?)';
    $params[] = (int)$user['id'];
}
if ($status !== '') {
    $where[] = 'q.status=?';
    $params[] = $status;
}

$stmt = db()->prepare("
    SELECT q.id,q.title,q.status,q.focus_name,q.category,q.target_name,q.waiting_since,q.taken_at,q.answered_at,
           q.taken_by,u.name author_name,taker.name taken_by_name,
           (SELECT COUNT(*) FROM question_votes v WHERE v.question_id=q.id) votes
    FROM questions q
    JOIN users u ON u.id=q.user_id
    LEFT JOIN users taker ON taker.id=q.taken_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
        CASE q.status WHEN 'waiting' THEN 1 WHEN 'open' THEN 2 WHEN 'taken' THEN 3 ELSE 4 END,
        COALESCE(q.waiting_since,q.created_at) ASC
    LIMIT 200
");
$stmt->execute($params);

$questions = array_map(static function(array $row): array {
    $waitingDays = !empty($row['waiting_since']) && in_array((string)$row['status'], ['waiting','answered'], true)
        ? max(0, (int)floor(((string)$row['status'] === 'answered' && !empty($row['answered_at'])
            ? strtotime((string)$row['answered_at'])
            : time()) - strtotime((string)$row['waiting_since'])) / 86400))
        : 0;

    return [
        'id' => (int)$row['id'],
        'title' => (string)$row['title'],
        'status_label' => status_label((string)$row['status']),
        'status_class' => status_class((string)$row['status']),
        'focus_name' => (string)$row['focus_name'],
        'category' => (string)$row['category'],
        'target_name' => (string)$row['target_name'],
        'author_name' => (string)$row['author_name'],
        'votes' => (int)$row['votes'],
        'has_waiting' => $waitingDays > 0,
        'waiting_days' => $waitingDays,
        'has_owner' => !empty($row['taken_by_name']),
        'taken_by_name' => (string)($row['taken_by_name'] ?? ''),
        'respond_url' => base_url('respond.php?id=' . (int)$row['id']),
        'question_url' => base_url('question.php?id=' . (int)$row['id']),
    ];
}, $stmt->fetchAll());

$filters = [];
foreach (['' => 'Todas', 'open' => 'Abertas', 'waiting' => 'Aguardando', 'taken' => 'Assumidas', 'answered' => 'Respondidas'] as $value => $label) {
    $filters[] = [
        'label' => $label,
        'url' => base_url('responses.php' . ($value !== '' ? '?status=' . $value : '')),
        'active_class' => $status === $value ? 'active' : '',
    ];
}

render_page('responses', [
    'questions' => $questions,
    'has_questions' => $questions !== [],
    'filters' => $filters,
    'count' => count($questions),
], 'Pautas para responder');
