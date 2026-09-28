<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
require_admin();

$userStats = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(active = 1) AS active,
        SUM(role = 'admin') AS admins
    FROM users
")->fetch();

$questionStats = db()->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'open') AS open_count,
        SUM(status = 'waiting') AS waiting_count,
        SUM(status = 'taken') AS taken_count,
        SUM(status = 'answered') AS answered_count,
        SUM(status = 'archived') AS archived_count,
        SUM(published = 0) AS hidden_count
    FROM questions
    WHERE deleted_at IS NULL
")->fetch();

$voteCount = (int)db()->query('SELECT COUNT(*) FROM question_votes')->fetchColumn();
$saveCount = (int)db()->query('SELECT COUNT(*) FROM question_saves')->fetchColumn();

$stats = [
    ['label' => 'Perguntas', 'value' => (int)$questionStats['total'], 'hint' => 'histórico total'],
    ['label' => 'Aguardando', 'value' => (int)$questionStats['waiting_count'], 'hint' => 'cronômetro ativo'],
    ['label' => 'Respondidas', 'value' => (int)$questionStats['answered_count'], 'hint' => 'ciclo fechado'],
    ['label' => 'Endossos', 'value' => $voteCount, 'hint' => 'Boas registradas'],
    ['label' => 'Usuários ativos', 'value' => (int)$userStats['active'], 'hint' => (int)$userStats['total'] . ' cadastrados'],
    ['label' => 'Não publicadas', 'value' => (int)$questionStats['hidden_count'], 'hint' => 'fora do site público'],
];

$statusDefinitions = [
    'open' => ['label' => 'Abertas', 'class' => 'open'],
    'waiting' => ['label' => 'Aguardando resposta', 'class' => 'waiting'],
    'taken' => ['label' => 'Pauta assumida', 'class' => 'taken'],
    'answered' => ['label' => 'Respondidas', 'class' => 'answered'],
    'archived' => ['label' => 'Arquivadas', 'class' => 'archived'],
];

$totalQuestions = max(1, (int)$questionStats['total']);
$statusBreakdown = [];
foreach ($statusDefinitions as $status => $definition) {
    $key = $status . '_count';
    $count = (int)($questionStats[$key] ?? 0);
    $statusBreakdown[] = [
        'label' => $definition['label'],
        'class' => $definition['class'],
        'count' => $count,
        'percent' => (int)round(($count / $totalQuestions) * 100),
    ];
}

$waitingRows = db()->query("
    SELECT q.id, q.title, q.focus_name, q.target_name, q.waiting_since,
           DATEDIFF(NOW(), q.waiting_since) AS waiting_days,
           (SELECT COUNT(*) FROM question_votes v WHERE v.question_id = q.id) AS votes
    FROM questions q
    WHERE q.status = 'waiting' AND q.waiting_since IS NOT NULL AND q.deleted_at IS NULL
    ORDER BY q.waiting_since ASC
    LIMIT 6
")->fetchAll();

$waiting = array_map(static fn(array $question): array => [
    'question_url' => base_url('question.php?id=' . (int)$question['id']),
    'title' => (string)$question['title'],
    'focus_name' => (string)$question['focus_name'],
    'target_name' => (string)$question['target_name'],
    'waiting_days' => max(0, (int)$question['waiting_days']),
    'votes' => (int)$question['votes'],
], $waitingRows);

$latestRows = db()->query("
    SELECT q.id, q.title, q.status, q.focus_name, q.category, q.created_at, q.published,
           u.name AS author_name,
           (SELECT COUNT(*) FROM question_votes v WHERE v.question_id = q.id) AS votes
    FROM questions q
    JOIN users u ON u.id = q.user_id
    WHERE q.deleted_at IS NULL
    ORDER BY q.created_at DESC
    LIMIT 8
")->fetchAll();

$latest = array_map(static fn(array $question): array => [
    'question_url' => base_url('question.php?id=' . (int)$question['id']),
    'title' => (string)$question['title'],
    'focus_name' => (string)$question['focus_name'],
    'category' => (string)$question['category'],
    'author_name' => (string)$question['author_name'],
    'status_label' => status_label((string)$question['status']),
    'status_class' => status_class((string)$question['status']),
    'visibility_label' => (int)$question['published'] === 1 ? 'Pública' : 'Oculta',
    'visibility_class' => (int)$question['published'] === 1 ? 'published' : 'hidden',
    'votes' => (int)$question['votes'],
    'created_at' => date('d/m/Y H:i', strtotime((string)$question['created_at'])),
], $latestRows);


$threshold = min(365, max(1, (int)app_setting('silence_threshold_days', '7')));
$attentionRow = db()->query("
    SELECT
        SUM(moderation_status='pending' AND deleted_at IS NULL) pending_moderation,
        SUM(status='taken' AND taken_at IS NOT NULL AND taken_at < (NOW() - INTERVAL {$threshold} DAY) AND deleted_at IS NULL) stale_taken,
        SUM(status='answered' AND deleted_at IS NULL AND NOT EXISTS (
            SELECT 1 FROM question_evidence e WHERE e.question_id=questions.id
        )) answered_without_evidence
    FROM questions
")->fetch();
$pendingReports = (int)db()->query("SELECT COUNT(*) FROM question_reports WHERE status='pending'")->fetchColumn();

$attention = [
    [
        'label' => 'Aguardando moderação',
        'value' => (int)($attentionRow['pending_moderation'] ?? 0),
        'hint' => 'perguntas ainda fora do site',
        'url' => base_url('admin/moderation.php'),
    ],
    [
        'label' => 'Denúncias pendentes',
        'value' => $pendingReports,
        'hint' => 'itens para revisar',
        'url' => base_url('admin/reports.php?status=pending'),
    ],
    [
        'label' => 'Pautas sem movimento',
        'value' => (int)($attentionRow['stale_taken'] ?? 0),
        'hint' => 'assumidas há mais de ' . $threshold . ' dias',
        'url' => base_url('admin/questions.php?status=taken'),
    ],
    [
        'label' => 'Respostas sem evidência',
        'value' => (int)($attentionRow['answered_without_evidence'] ?? 0),
        'hint' => 'ciclos fechados sem referência',
        'url' => base_url('admin/questions.php?status=answered'),
    ],
];

render_page('admin/index', [
    'stats' => $stats,
    'status_breakdown' => $statusBreakdown,
    'waiting' => $waiting,
    'has_waiting' => $waiting !== [],
    'latest' => $latest,
    'save_count' => $saveCount,
    'admin_count' => (int)$userStats['admins'],
    'attention' => $attention,
], 'Administração', true);
