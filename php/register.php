<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (!app_installed()) redirect('install.php');
if (current_user()) redirect('index.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = normalize_email((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($name) < 2) {
        $error = 'Informe seu nome.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um e-mail válido.';
    } elseif (strlen($password) < 10) {
        $error = 'Use uma senha com pelo menos 10 caracteres.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'As senhas não conferem.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            flash('success', 'Conta criada. Bem-vindo ao Pergunta.Online.');
            redirect('index.php');
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'Já existe uma conta com este e-mail.' : 'Não foi possível criar a conta.';
        }
    }
}
?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Criar conta · Pergunta.Online</title><link rel="stylesheet" href="<?= h(base_url('assets/style.css')) ?>"></head><body><main class="auth-shell"><section class="auth-card"><a class="brand" href="<?= h(base_url('index.php')) ?>"><span class="brand-dot"></span>pergunta.online</a><div class="eyebrow">AUTO CADASTRO</div><h1>Crie sua conta e participe.</h1><p class="muted">O cadastro é imediato. Novos usuários entram com perfil comum; somente administradores podem conceder acesso administrativo.</p><?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?><form method="post" class="form-grid"><?= csrf_field() ?><label>Nome<input name="name" required maxlength="120" autocomplete="name" value="<?= h($_POST['name'] ?? '') ?>"></label><label>E-mail<input type="email" name="email" required maxlength="190" autocomplete="email" value="<?= h($_POST['email'] ?? '') ?>"></label><label>Senha<input type="password" name="password" required minlength="10" autocomplete="new-password"></label><label>Repita a senha<input type="password" name="confirm_password" required minlength="10" autocomplete="new-password"></label><button class="button primary" type="submit">Criar minha conta</button></form><p class="auth-foot">Já tem conta? <a href="<?= h(base_url('login.php')) ?>">Entre aqui</a>.</p></section></main></body></html>
