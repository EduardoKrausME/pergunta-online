<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

if (!app_installed()) {
    redirect('install');
}
if (current_user()) {
    redirect('/');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = normalize_email((string)Request::post('email', Request::STRING, ''));
    $password = (string)Request::post('password', Request::STRING, '');
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
            $lastLogin = db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?');
            $lastLogin->execute([(int)$user['id']]);
            redirect('/');
        }
        $error = 'E-mail ou senha inválidos.';
    }
}

render_page('login', ['error' => $error], 'Entrar');
