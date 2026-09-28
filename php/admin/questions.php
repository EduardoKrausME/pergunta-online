<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
require_admin();

$statusOptions = [
    'open' => 'Aberta',
    'waiting' => 'Aguardando resposta',
    'taken' => 'Pauta assumida',
    'answered' => 'Resposta registrada',
    'archived' => 'Arquivada',
];

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? '');
$visibilityFilter = (string)($_GET['visibility'] ?? '');

if (!array_key_exists($statusFilter, $statusOptions)) {
    $statusFilter = '';
}
if (!in_array($visibilityFilter, ['', 'published', 'hidden'], true)) {
    $visibilityFilter = '';
}

$returnQuery = http_build_query(array_filter([
    'q' => $q,
    'status' => $statusFilter,
    'visibility' => $visibilityFilter,
], static fn(string $value): bool => $value !== ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['question_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $answer = trim((string)($_POST['answer_text'] ?? ''));
    $published = !empty($_POST['published']) ? 1 : 0;

    if (array_key_exists($status, $statusOptions)) {
        $waiting = $status === 'waiting' ? 'COALESCE(waiting_since,NOW())' : 'waiting_since';
        $answered = $status === 'answered' ? 'COALESCE(answered_at,NOW())' : 'answered_at';
        $sql = "UPDATE questions SET status=?,answer_text=?,published=?,waiting_since={$waiting},answered_at={$answered} WHERE id=?";
        $stmt = db()->prepare($sql);
        $stmt->execute([$status, $answer !== '' ? $answer : null, $published, $id]);
        flash('success', 'Pergunta atualizada.');
    }
    redirect('admin/questions.php' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(q.title LIKE ? OR q.body LIKE ? OR q.focus_name LIKE ? OR q.category LIKE ? OR q.target_name LIKE ? OR u.name LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term, $term, $term, $term, $term);
}
if ($statusFilter !== '') {
    $where[] = 'q.status = ?';
    $params[] = $statusFilter;
}
if ($visibilityFilter === 'published') {
    $where[] = 'q.published = 1';
} elseif ($visibilityFilter === 'hidden') {
    $where[] = 'q.published = 0';
}

$sql = "
    SELECT q.*, u.name AS author_name,
           (SELECT COUNT(*) FROM question_votes v WHERE v.question_id = q.id) AS votes
    FROM questions q
    JOIN users u ON u.id = q.user_id
";
if ($where !== []) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY q.updated_at DESC, q.created_at DESC LIMIT 100';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summaryRow = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'waiting') AS waiting_count,
        SUM(status = 'answered') AS answered_count,
        SUM(published = 0) AS hidden_count
    FROM questions
")->fetch();

$summary = [
    ['label' => 'Total', 'value' => (int)$summaryRow['total']],
    ['label' => 'Aguardando', 'value' => (int)$summaryRow['waiting_count']],
    ['label' => 'Respondidas', 'value' => (int)$summaryRow['answered_count']],
    ['label' => 'Ocultas', 'value' => (int)$summaryRow['hidden_count']],
];

$questions = [];
foreach ($rows as $question) {
    $statuses = [];
    foreach ($statusOptions as $value => $label) {
        $statuses[] = [
            'value' => $value,
            'label' => $label,
            'selected' => $question['status'] === $value,
        ];
    }

    $hasWaiting = !empty($question['waiting_since']);
    $waitingDays = 0;
    if ($hasWaiting) {
        $silenceEnd = $question['status'] === 'answered' && !empty($question['answered_at'])
            ? strtotime((string)$question['answered_at'])
            : time();
        $waitingDays = max(0, (int)floor(($silenceEnd - strtotime((string)$question['waiting_since'])) / 86400));
    }

    $questions[] = [
        'id' => (int)$question['id'],
        'focus_name' => (string)$question['focus_name'],
        'focus_abbr' => (string)$question['focus_abbr'],
        'category' => (string)$question['category'],
        'title' => (string)$question['title'],
        'body' => (string)$question['body'],
        'target_name' => (string)$question['target_name'],
        'author_name' => (string)$question['author_name'],
        'votes' => (int)$question['votes'],
        'question_url' => base_url('question.php?id=' . (int)$question['id']),
        'answer_text' => (string)($question['answer_text'] ?? ''),
        'has_answer' => !empty($question['answer_text']),
        'published' => (bool)$question['published'],
        'visibility_label' => (int)$question['published'] === 1 ? 'Pública' : 'Oculta',
        'visibility_class' => (int)$question['published'] === 1 ? 'published' : 'hidden',
        'status_label' => status_label((string)$question['status']),
        'status_class' => status_class((string)$question['status']),
        'statuses' => $statuses,
        'has_waiting' => $hasWaiting,
        'waiting_days' => $waitingDays,
        'created_at' => date('d/m/Y H:i', strtotime((string)$question['created_at'])),
        'updated_at' => date('d/m/Y H:i', strtotime((string)$question['updated_at'])),
    ];
}

$statusFilters = [['value' => '', 'label' => 'Todos os estados', 'selected' => $statusFilter === '']];
foreach ($statusOptions as $value => $label) {
    $statusFilters[] = ['value' => $value, 'label' => $label, 'selected' => $statusFilter === $value];
}

$visibilityFilters = [
    ['value' => '', 'label' => 'Todas as publicações', 'selected' => $visibilityFilter === ''],
    ['value' => 'published', 'label' => 'Somente públicas', 'selected' => $visibilityFilter === 'published'],
    ['value' => 'hidden', 'label' => 'Somente ocultas', 'selected' => $visibilityFilter === 'hidden'],
];

render_page('admin/questions', [
    'questions' => $questions,
    'summary' => $summary,
    'result_count' => count($questions),
    'search_query' => $q,
    'status_filters' => $statusFilters,
    'visibility_filters' => $visibilityFilters,
    'has_filters' => $q !== '' || $statusFilter !== '' || $visibilityFilter !== '',
], 'Perguntas', true);
