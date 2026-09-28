<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install.php');
}
if (current_user()) {
    redirect('/');
}
if (app_setting('registration_enabled', '1') !== '1') {
    flash('warning', 'O cadastro público está desativado.');
    redirect('login.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim((string)Request::post('name', Request::STRING, ''));
    $email = normalize_email((string)Request::post('email', Request::STRING, ''));
    $password = (string)Request::post('password', Request::STRING, '');
    $confirm = (string)Request::post('confirm_password', Request::STRING, '');
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
            redirect('/');
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'Já existe uma conta com este e-mail.' : 'Não foi possível criar a conta.';
        }
    }
}

render_page('register', [
    'error' => $error,
    'name' => (string)Request::post('name', Request::STRING, ''),
    'email' => (string)Request::post('email', Request::STRING, ''),
], 'Criar conta');
