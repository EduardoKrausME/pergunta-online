<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$user = require_login();
$id = (int)Request::get('id', Request::INT, 0);

$stmt = db()->prepare('SELECT * FROM questions WHERE id=? AND deleted_at IS NULL LIMIT 1');
$stmt->execute([$id]);
$question = $stmt->fetch();
if (!$question) {
    http_response_code(404);
    render_page('not_found', [], 'Pergunta não encontrada');
    exit;
}

$authorized = ($user['role'] ?? '') === 'admin';
if (!$authorized && ($user['role'] ?? '') === 'respondent' && !empty($question['target_id'])) {
    $check = db()->prepare('SELECT 1 FROM target_users WHERE target_id=? AND user_id=?');
    $check->execute([(int)$question['target_id'], (int)$user['id']]);
    $authorized = (bool)$check->fetchColumn();
}
if (!$authorized) {
    http_response_code(403);
    exit('Esta conta não está autorizada a responder por este destinatário.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string)Request::post('action', Request::STRING, '');
    try {
        if ($action === 'take') {
            question_update_state(
                $id,
                $user,
                'taken',
                (string)($question['answer_text'] ?? ''),
                (int)$question['published'] === 1
            );
            flash('success', 'Pauta assumida e registrada no histórico.');
        } elseif ($action === 'answer') {
            $answer = trim((string)Request::post('answer_text', Request::STRING, ''));
            question_update_state($id, $user, 'answered', $answer, (int)$question['published'] === 1);
            flash('success', 'Resposta registrada e ciclo fechado.');
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    }
    redirect('respond.php?id=' . $id);
}

$stmt->execute([$id]);
$question = $stmt->fetch();

render_page('respond', [
    'question_id' => $id,
    'title' => (string)$question['title'],
    'body' => (string)$question['body'],
    'target_name' => (string)$question['target_name'],
    'status_label' => status_label((string)$question['status']),
    'answer_text' => (string)($question['answer_text'] ?? ''),
    'is_taken' => (string)$question['status'] === 'taken',
    'is_answered' => (string)$question['status'] === 'answered',
    'question_url' => base_url('question.php?id=' . $id),
], 'Responder pergunta');
