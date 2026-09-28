<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install.php');
}

$user = current_user();
$q = trim((string)Request::get('q', Request::STRING, ''));
$focus = trim((string)Request::get('focus', Request::STRING, ''));
$category = trim((string)Request::get('category', Request::STRING, ''));
$tab = (string)Request::get('tab', Request::STRING, 'hot');

$where = ['q.published = 1', "q.status <> 'archived'"];
$params = [];
if ($q !== '') {
    $where[] = '(q.title LIKE ? OR q.body LIKE ? OR q.focus_name LIKE ? OR q.target_name LIKE ?)';
    $term = '%' . $q . '%';
    array_push($params, $term, $term, $term, $term);
}
if ($focus !== '') {
    $where[] = 'q.focus_name = ?';
    $params[] = $focus;
}
if ($category !== '') {
    $where[] = 'q.category = ?';
    $params[] = $category;
}
if ($tab === 'answered') {
    $where[] = "q.status = 'answered'";
}

$order = $tab === 'recent' ? 'q.created_at DESC' : 'vote_count DESC, q.created_at DESC';
$sql = 'SELECT q.*, u.name AS author_name, COUNT(DISTINCT v.user_id) AS vote_count '
    . 'FROM questions q JOIN users u ON u.id=q.user_id '
    . 'LEFT JOIN question_votes v ON v.question_id=q.id '
    . 'WHERE ' . implode(' AND ', $where) . ' GROUP BY q.id ORDER BY ' . $order . ' LIMIT 60';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$questions = $stmt->fetchAll();

$categories = db()->query("SELECT DISTINCT category FROM questions WHERE published=1 AND status <> 'archived' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$focuses = db()->query("SELECT focus_name, focus_abbr, COUNT(*) qty FROM questions WHERE published=1 AND status <> 'archived' GROUP BY focus_name, focus_abbr ORDER BY qty DESC, focus_name LIMIT 8")->fetchAll();

$voted = [];
$saved = [];
if ($user) {
    $s = db()->prepare('SELECT question_id FROM question_votes WHERE user_id = ?');
    $s->execute([$user['id']]);
    $voted = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));

    $s = db()->prepare('SELECT question_id FROM question_saves WHERE user_id = ?');
    $s->execute([$user['id']]);
    $saved = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
}

$questionContext = [];
foreach ($questions as $question) {
    $id = (int)$question['id'];
    $isVoted = in_array($id, $voted, true);
    $isSaved = in_array($id, $saved, true);
    $showSilence = $question['status'] === 'waiting' && !empty($question['waiting_since']);
    $questionContext[] = [
        'id' => $id,
        'focus_abbr' => (string)$question['focus_abbr'],
        'focus_name' => (string)$question['focus_name'],
        'status_class' => status_class((string)$question['status']),
        'status_label' => status_label((string)$question['status']),
        'category' => (string)$question['category'],
        'title' => (string)$question['title'],
        'body_html' => nl2br(h((string)$question['body'])),
        'target_name' => (string)$question['target_name'],
        'author_name' => (string)$question['author_name'],
        'question_url' => base_url('question.php?id=' . $id),
        'action_url' => base_url('action.php'),
        'vote_count' => (int)$question['vote_count'],
        'vote_active_class' => $isVoted ? 'active' : '',
        'save_active_class' => $isSaved ? 'active' : '',
        'save_label' => $isSaved ? 'Salva' : 'Salvar pergunta',
        'show_silence' => $showSilence,
        'silence_days' => $showSilence ? (int)floor((time() - strtotime((string)$question['waiting_since'])) / 86400) : 0,
    ];
}

$focusContext = [];
$focusCount = count($focuses);
foreach ($focuses as $index => $item) {
    $focusContext[] = [
        'focus_abbr' => (string)$item['focus_abbr'],
        'focus_name' => (string)$item['focus_name'],
        'url' => '?' . http_build_query(['focus' => $item['focus_name']]),
        'first_focus' => $index === 0,
        'last_focus' => $index === $focusCount - 1,
    ];
}

$categoryContext = array_map(static fn(string $item): array => [
    'name' => $item,
    'selected' => $category === $item,
], $categories);

render_page('home', [
    'search_query' => $q,
    'focuses' => $focusContext,
    'questions' => $questionContext,
    'categories' => $categoryContext,
    'result_count' => count($questionContext),
    'hot_active' => $tab === 'hot' ? 'active' : '',
    'recent_active' => $tab === 'recent' ? 'active' : '',
    'answered_active' => $tab === 'answered' ? 'active' : '',
    'has_filters' => $q !== '' || $focus !== '' || $category !== '',
    'waiting_count' => (int)db()->query("SELECT COUNT(*) FROM questions WHERE status='waiting' AND published=1")->fetchColumn(),
]);
