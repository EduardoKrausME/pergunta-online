<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['question_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $answer = trim((string)($_POST['answer_text'] ?? ''));
    $published = !empty($_POST['published']) ? 1 : 0;
    $allowed = ['open', 'waiting', 'taken', 'answered', 'archived'];
    if (in_array($status, $allowed, true)) {
        $waiting = $status === 'waiting' ? 'COALESCE(waiting_since,NOW())' : 'waiting_since';
        $answered = $status === 'answered' ? 'COALESCE(answered_at,NOW())' : 'answered_at';
        $sql = "UPDATE questions SET status=?,answer_text=?,published=?,waiting_since={$waiting},answered_at={$answered} WHERE id=?";
        $stmt = db()->prepare($sql);
        $stmt->execute([$status, $answer !== '' ? $answer : null, $published, $id]);
        flash('success', 'Pergunta atualizada.');
    }
    redirect('admin/questions.php');
}

$rows = db()->query('SELECT q.*,u.name author_name,(SELECT COUNT(*) FROM question_votes v WHERE v.question_id=q.id) votes FROM questions q JOIN users u ON u.id=q.user_id ORDER BY q.created_at DESC')->fetchAll();
$statusOptions = [
    'open' => 'Aberta',
    'waiting' => 'Aguardando resposta',
    'taken' => 'Pauta assumida',
    'answered' => 'Resposta registrada',
    'archived' => 'Arquivada',
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
    $questions[] = [
        'id' => (int)$question['id'],
        'focus_name' => (string)$question['focus_name'],
        'category' => (string)$question['category'],
        'title' => (string)$question['title'],
        'author_name' => (string)$question['author_name'],
        'votes' => (int)$question['votes'],
        'question_url' => base_url('question.php?id=' . (int)$question['id']),
        'answer_text' => (string)($question['answer_text'] ?? ''),
        'published' => (bool)$question['published'],
        'statuses' => $statuses,
    ];
}

render_page('admin/questions', ['questions' => $questions], 'Perguntas', true);
