<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function render_header(string $title = APP_NAME, bool $admin = false): void {
    $user = current_user();
    $flashes = consume_flashes();
    $fullTitle = $title === APP_NAME ? APP_NAME : $title . ' · ' . APP_NAME;
    ?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f3ec">
    <title><?= h($fullTitle) ?></title>
    <link rel="stylesheet" href="<?= h(base_url('assets/style.css')) ?>?v=<?= APP_VERSION ?>">
</head>
<body>
<header class="site-header">
    <div class="wrap header-inner">
        <a class="brand" href="<?= h(base_url('index.php')) ?>"><span class="brand-dot"></span>pergunta.online</a>
        <nav class="main-nav" aria-label="Navegação principal">
            <a href="<?= h(base_url('index.php')) ?>">Explorar</a>
            <?php if ($user): ?>
                <a href="<?= h(base_url('ask.php')) ?>">Nova pergunta</a>
                <?php if (($user['role'] ?? '') === 'admin'): ?><a href="<?= h(base_url('admin/index.php')) ?>">Administração</a><?php endif; ?>
                <span class="user-name"><?= h($user['name']) ?></span>
                <a class="button ghost" href="<?= h(base_url('logout.php')) ?>">Sair</a>
            <?php else: ?>
                <a href="<?= h(base_url('login.php')) ?>">Entrar</a>
                <a class="button primary small" href="<?= h(base_url('register.php')) ?>">Criar conta</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php if ($admin): ?>
<div class="admin-bar"><div class="wrap"><strong>Administração</strong><a href="<?= h(base_url('admin/index.php')) ?>">Resumo</a><a href="<?= h(base_url('admin/questions.php')) ?>">Perguntas</a><a href="<?= h(base_url('admin/users.php')) ?>">Usuários</a><a href="<?= h(base_url('index.php')) ?>">Ver site</a></div></div>
<?php endif; ?>
<main class="wrap page">
<?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type'] ?? 'info') ?>"><?= h($flash['message'] ?? '') ?></div><?php endforeach; ?>
<?php
}

function render_footer(): void {
    ?>
</main>
<footer class="site-footer"><div class="wrap"><span>© <?= date('Y') ?> Pergunta.Online · Perguntas públicas. Memória coletiva.</span><span>A pergunta não desaparece mais.</span></div></footer>
</body>
</html>
<?php
}
