<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (!app_installed()) redirect('install.php');
if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $ip = client_ip();
    $rate = db()->prepare("SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip_address = ? AND success = 0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
    $rate->execute([$email, $ip]);
    if ((int)$rate->fetchColumn() >= 8) {
        $error = 'Muitas tentativas recentes. Aguarde alguns minutos antes de tentar novamente.';
    } else {
        $stmt = db()->prepare('SELECT id, password_hash, active FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        $ok = $user && (int)$user['active'] === 1 && password_verify($password, $user['password_hash']);
        $log = db()->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?, ?, ?)');
        $log->execute([$email, $ip, $ok ? 1 : 0]);
        if ($ok) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            redirect('index.php');
        }
        $error = 'E-mail ou senha inválidos.';
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Entrar · Pergunta.Online</title><link rel="stylesheet" href="<?= h(base_url('assets/style.css')) ?>"></head><body><main class="auth-shell"><section class="auth-card"><a class="brand" href="<?= h(base_url('index.php')) ?>"><span class="brand-dot"></span>pergunta.online</a><div class="eyebrow">ENTRAR</div><h1>Continue de onde parou.</h1><?php foreach (consume_flashes() as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?><?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?><form method="post" class="form-grid"><?= csrf_field() ?><label>E-mail<input type="email" name="email" required autocomplete="email"></label><label>Senha<input type="password" name="password" required autocomplete="current-password"></label><button class="button primary" type="submit">Entrar</button></form><p class="auth-foot">Ainda não tem conta? <a href="<?= h(base_url('register.php')) ?>">Cadastre-se</a>.</p></section></main></body></html>
