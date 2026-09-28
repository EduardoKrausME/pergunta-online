<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install.php');
}

$id = (int)Request::get('id', Request::INT, 0);
$viewer = current_user();
$viewerId = (int)($viewer['id'] ?? 0);
$isAdmin = (int)(($viewer['role'] ?? '') === 'admin');
$stmt = db()->prepare('SELECT q.*,u.name author_name,(SELECT COUNT(*) FROM question_votes v WHERE v.question_id=q.id) vote_count FROM questions q JOIN users u ON u.id=q.user_id WHERE q.id=? AND (q.published=1 OR q.user_id=? OR ?=1) LIMIT 1');
$stmt->execute([$id, $viewerId, $isAdmin]);
$question = $stmt->fetch();

if (!$question) {
    http_response_code(404);
    render_page('not_found', [], 'Pergunta não encontrada');
    exit;
}

$showSilence = !empty($question['waiting_since']);
$silenceDays = 0;
if ($showSilence) {
    $silenceEnd = $question['status'] === 'answered' && $question['answered_at'] ? strtotime((string)$question['answered_at']) : time();
    $silenceDays = max(0, (int)floor(($silenceEnd - strtotime((string)$question['waiting_since'])) / 86400));
}

render_page('question', [
    'focus_abbr' => (string)$question['focus_abbr'],
    'focus_name' => (string)$question['focus_name'],
    'status_class' => status_class((string)$question['status']),
    'status_label' => status_label((string)$question['status']),
    'category' => (string)$question['category'],
    'title' => (string)$question['title'],
    'body_html' => nl2br(h((string)$question['body'])),
    'author_name' => (string)$question['author_name'],
    'target_name' => (string)$question['target_name'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$question['created_at'])),
    'vote_count' => (int)$question['vote_count'],
    'show_silence' => $showSilence,
    'silence_days' => $silenceDays,
    'has_answer' => !empty($question['answer_text']),
    'answer_html' => !empty($question['answer_text']) ? nl2br(h((string)$question['answer_text'])) : '',
    'answered_at' => !empty($question['answered_at']) ? date('d/m/Y H:i', strtotime((string)$question['answered_at'])) : '',
], (string)$question['title']);
