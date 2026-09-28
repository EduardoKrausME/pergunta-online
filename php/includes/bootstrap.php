<?php

declare(strict_types=1);

const APP_NAME = 'Pergunta.Online';
const APP_VERSION = '1.0.1';

$configfile = dirname(__DIR__) . '/config.php';
if (!is_file($configfile)) {
    http_response_code(500);
    exit('Arquivo config.php não encontrado. Copie/configure php/config.php antes de continuar.');
}

$appconfig = require $configfile;
if (!is_array($appconfig)) {
    http_response_code(500);
    exit('O arquivo config.php precisa retornar um array de configuração.');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $secure,
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function db(): PDO {
    static $pdo = null;
    global $appconfig;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = $appconfig['database'] ?? [];
    $host = (string)($database['host'] ?? '127.0.0.1');
    $port = (int)($database['port'] ?? 3306);
    $name = trim((string)($database['name'] ?? ''));
    $user = trim((string)($database['user'] ?? ''));
    $password = (string)($database['password'] ?? '');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Configure database.name e database.user em config.php.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $pdo;
}

function app_installed(): bool {
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'users'");
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function base_url(string $path = ''): string {
    global $appconfig;

    $base = rtrim((string)($appconfig['app_url'] ?? ''), '/');
    if ($base !== '') {
        return $base . '/' . ltrim($path, '/');
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = preg_replace('~/admin$~', '', dirname($script));
    $dir = $dir === '/' ? '' : rtrim($dir, '/');

    return $dir . '/' . ltrim($path, '/');
}

function h(?string $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Sessão expirada ou token CSRF inválido. Volte e tente novamente.');
    }
}

function redirect(string $path): never {
    header('Location: ' . base_url($path));
    exit;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flashes(): array {
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($items) ? $items : [];
}

function current_user(): ?array {
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }

    $loaded = true;
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0 || !app_installed()) {
        return null;
    }

    $stmt = db()->prepare('SELECT id,name,email,role,active,created_at FROM users WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row || !(int)$row['active']) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user = $row;
    return $user;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        flash('warning', 'Entre na sua conta para continuar.');
        redirect('login.php');
    }
    return $user;
}

function require_admin(): array {
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Acesso restrito à administração.');
    }
    return $user;
}

function normalize_email(string $email): string {
    return strtolower(trim($email));
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function status_label(string $status): string {
    return match ($status) {
        'waiting' => 'Aguardando resposta',
        'answered' => 'Resposta registrada',
        'taken' => 'Pauta assumida',
        'archived' => 'Arquivada',
        default => 'Aberta à comunidade',
    };
}

function status_class(string $status): string {
    return in_array($status, ['waiting', 'answered', 'taken'], true) ? $status : '';
}
