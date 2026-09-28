<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
require_admin();

$stats = [
    ['label' => 'Usuários', 'value' => (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()],
    ['label' => 'Perguntas', 'value' => (int)db()->query('SELECT COUNT(*) FROM questions')->fetchColumn()],
    ['label' => 'Aguardando resposta', 'value' => (int)db()->query("SELECT COUNT(*) FROM questions WHERE status='waiting'")->fetchColumn()],
    ['label' => 'Respondidas', 'value' => (int)db()->query("SELECT COUNT(*) FROM questions WHERE status='answered'")->fetchColumn()],
];

$latestRows = db()->query('SELECT q.id,q.title,q.status,q.created_at,u.name author_name FROM questions q JOIN users u ON u.id=q.user_id ORDER BY q.created_at DESC LIMIT 8')->fetchAll();
$latest = array_map(static fn(array $question): array => [
    'question_url' => base_url('question.php?id=' . (int)$question['id']),
    'title' => (string)$question['title'],
    'author_name' => (string)$question['author_name'],
    'status_label' => status_label((string)$question['status']),
    'created_at' => date('d/m/Y H:i', strtotime((string)$question['created_at'])),
], $latestRows);

render_page('admin/index', [
    'stats' => $stats,
    'latest' => $latest,
], 'Administração', true);
