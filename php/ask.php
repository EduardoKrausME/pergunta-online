<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install.php');
}
$user = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $focus = trim((string)Request::post('focus_name', Request::STRING, ''));
    $abbr = strtoupper(trim((string)Request::post('focus_abbr', Request::STRING, '')));
    $category = trim((string)Request::post('category', Request::STRING, ''));
    $title = trim((string)Request::post('title', Request::STRING, ''));
    $body = trim((string)Request::post('body', Request::STRING, ''));
    $target = trim((string)Request::post('target_name', Request::STRING, ''));
    if (strlen($focus) < 2 || strlen($abbr) < 1 || strlen($category) < 2 || strlen($title) < 15 || strlen($body) < 20 || strlen($target) < 2) {
        $error = 'Preencha todos os campos. A pergunta precisa ter ao menos 15 caracteres e o contexto ao menos 20.';
    } else {
        $stmt = db()->prepare("INSERT INTO questions (user_id,focus_name,focus_abbr,category,title,body,target_name,status) VALUES (?,?,?,?,?,?,?,'open')");
        $stmt->execute([$user['id'], $focus, substr($abbr, 0, 12), $category, $title, $body, $target]);
        $id = (int)db()->lastInsertId();
        flash('success', 'Pergunta publicada. Agora ela pode ganhar relevância e histórico.');
        redirect('question.php?id=' . $id);
    }
}

render_page('ask', [
    'error' => $error,
    'focus_name' => (string)Request::post('focus_name', Request::STRING, ''),
    'focus_abbr' => (string)Request::post('focus_abbr', Request::STRING, ''),
    'category' => (string)Request::post('category', Request::STRING, ''),
    'title' => (string)Request::post('title', Request::STRING, ''),
    'body' => (string)Request::post('body', Request::STRING, ''),
    'target_name' => (string)Request::post('target_name', Request::STRING, ''),
], 'Nova pergunta');
