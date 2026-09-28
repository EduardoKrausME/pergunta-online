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
    $focus = trim((string)($_POST['focus_name'] ?? ''));
    $abbr = strtoupper(trim((string)($_POST['focus_abbr'] ?? '')));
    $category = trim((string)($_POST['category'] ?? ''));
    $title = trim((string)($_POST['title'] ?? ''));
    $body = trim((string)($_POST['body'] ?? ''));
    $target = trim((string)($_POST['target_name'] ?? ''));
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
    'focus_name' => (string)($_POST['focus_name'] ?? ''),
    'focus_abbr' => (string)($_POST['focus_abbr'] ?? ''),
    'category' => (string)($_POST['category'] ?? ''),
    'title' => (string)($_POST['title'] ?? ''),
    'body' => (string)($_POST['body'] ?? ''),
    'target_name' => (string)($_POST['target_name'] ?? ''),
], 'Nova pergunta');
