<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install');
}
$admin = require_admin();

$statusOptions = [
    'open' => 'Aberta',
    'waiting' => 'Aguardando resposta',
    'taken' => 'Pauta assumida',
    'answered' => 'Resposta registrada',
    'archived' => 'Arquivada',
];

$q = trim((string)Request::get('q', Request::STRING, ''));
$statusFilter = (string)Request::get('status', Request::STRING, '');
$visibilityFilter = (string)Request::get('visibility', Request::STRING, '');
$trashFilter = (string)Request::get('trash', Request::STRING, 'active');
$page = max(1, (int)Request::get('page', Request::INT, 1));
$perPage = min(100, max(10, (int)app_setting('max_questions_per_page', '50')));

if (!array_key_exists($statusFilter, $statusOptions)) {
    $statusFilter = '';
}
if (!in_array($visibilityFilter, ['', 'published', 'hidden'], true)) {
    $visibilityFilter = '';
}
if (!in_array($trashFilter, ['active', 'deleted', 'all'], true)) {
    $trashFilter = 'active';
}

$returnQuery = http_build_query(array_filter([
    'q' => $q,
    'status' => $statusFilter,
    'visibility' => $visibilityFilter,
    'trash' => $trashFilter !== 'active' ? $trashFilter : '',
    'page' => $page > 1 ? (string)$page : '',
], static fn(string $value): bool => $value !== ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)Request::post('question_id', Request::INT, 0);
    $status = (string)Request::post('status', Request::STRING, '');
    $answer = trim((string)Request::post('answer_text', Request::STRING, ''));
    $published = (bool)Request::post('published', Request::BOOL, false);

    try {
        if (question_update_state($id, $admin, $status, $answer, $published)) {
            flash('success', 'Pergunta atualizada e alteração registrada no histórico.');
        } else {
            flash('error', 'Pergunta não encontrada ou estado inválido.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/questions' . ($returnQuery !== '' ? '?' . $returnQuery : ''));
}

$where = [];
$params = [];

if ($trashFilter === 'active') {
    $where[] = 'q.deleted_at IS NULL';
} elseif ($trashFilter === 'deleted') {
    $where[] = 'q.deleted_at IS NOT NULL';
}

if ($q !== '') {
    if (strlen($q) >= 4) {
        $where[] = '(MATCH(q.title,q.body,q.focus_name,q.category,q.target_name) AGAINST (? IN NATURAL LANGUAGE MODE) OR u.name LIKE ?)';
        $params[] = $q;
        $params[] = '%' . $q . '%';
    } else {
        $where[] = '(q.title LIKE ? OR q.body LIKE ? OR q.focus_name LIKE ? OR q.category LIKE ? OR q.target_name LIKE ? OR u.name LIKE ?)';
        $term = '%' . $q . '%';
        array_push($params, $term, $term, $term, $term, $term, $term);
    }
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

$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = db()->prepare('SELECT COUNT(*) FROM questions q JOIN users u ON u.id=q.user_id' . $whereSql);
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "
    SELECT q.*,u.name author_name,
           (SELECT COUNT(*) FROM question_votes v WHERE v.question_id=q.id) votes,
           (SELECT COUNT(*) FROM question_reports r WHERE r.question_id=q.id AND r.status='pending') pending_reports
    FROM questions q
    JOIN users u ON u.id=q.user_id
    {$whereSql}
    ORDER BY q.updated_at DESC,q.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$summaryRow = db()->query("
    SELECT COUNT(*) total,
           SUM(status='waiting') waiting_count,
           SUM(status='answered') answered_count,
           SUM(published=0) hidden_count
    FROM questions
    WHERE deleted_at IS NULL
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

    $hasWaiting = in_array($question['status'], ['waiting', 'answered'], true) && !empty($question['waiting_since']);
    $waitingDays = 0;
    if ($hasWaiting) {
        $silenceEnd = $question['status'] === 'answered' && !empty($question['answered_at'])
            ? strtotime((string)$question['answered_at'])
            : time();
        $waitingDays = max(0, (int)floor(($silenceEnd - strtotime((string)$question['waiting_since'])) / 86400));
    }

    $isDeleted = !empty($question['deleted_at']);
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
        'pending_reports' => (int)$question['pending_reports'],
        'question_url' => base_url('question?id=' . (int)$question['id']),
        'admin_question_url' => base_url('admin/question?id=' . (int)$question['id']),
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
        'deleted' => $isDeleted,
        'moderation_label' => match ((string)$question['moderation_status']) {
            'pending' => 'Pendente',
            'rejected' => 'Rejeitada',
            default => 'Aprovada',
        },
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
$trashFilters = [
    ['value' => 'active', 'label' => 'Ativas', 'selected' => $trashFilter === 'active'],
    ['value' => 'deleted', 'label' => 'Excluídas', 'selected' => $trashFilter === 'deleted'],
    ['value' => 'all', 'label' => 'Todas', 'selected' => $trashFilter === 'all'],
];

$baseQuery = ['q'=>$q,'status'=>$statusFilter,'visibility'=>$visibilityFilter,'trash'=>$trashFilter];
$prevQuery = $baseQuery;
$prevQuery['page'] = max(1, $page - 1);
$nextQuery = $baseQuery;
$nextQuery['page'] = min($totalPages, $page + 1);

render_page('admin/questions', [
    'questions' => $questions,
    'summary' => $summary,
    'result_count' => count($questions),
    'total_filtered' => $totalFiltered,
    'search_query' => $q,
    'status_filters' => $statusFilters,
    'visibility_filters' => $visibilityFilters,
    'trash_filters' => $trashFilters,
    'has_filters' => $q !== '' || $statusFilter !== '' || $visibilityFilter !== '' || $trashFilter !== 'active',
    'page' => $page,
    'total_pages' => $totalPages,
    'has_prev' => $page > 1,
    'has_next' => $page < $totalPages,
    'prev_url' => base_url('admin/questions?' . http_build_query($prevQuery)),
    'next_url' => base_url('admin/questions?' . http_build_query($nextQuery)),
], 'Perguntas', true);
