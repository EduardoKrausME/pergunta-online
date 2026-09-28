<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $id = (int)($_POST['user_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($id === (int)$admin['id']) {
        flash('warning', 'Você não pode desativar ou remover seu próprio acesso administrativo aqui.');
        redirect('admin/users.php');
    }
    $stmt = db()->prepare('SELECT id,role,active FROM users WHERE id=?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    if ($target) {
        if ($action === 'toggle_active') {
            $s = db()->prepare('UPDATE users SET active=? WHERE id=?');
            $s->execute([(int)!((int)$target['active']), $id]);
        } elseif ($action === 'toggle_role') {
            $role = $target['role'] === 'admin' ? 'user' : 'admin';
            $s = db()->prepare('UPDATE users SET role=? WHERE id=?');
            $s->execute([$role, $id]);
        }
    }
    redirect('admin/users.php');
}

$rows = db()->query('SELECT id,name,email,role,active,created_at FROM users ORDER BY created_at DESC')->fetchAll();
$users = array_map(static function(array $user) use ($admin): array {
    return [
        'id' => (int)$user['id'],
        'name' => (string)$user['name'],
        'email' => (string)$user['email'],
        'role' => (string)$user['role'],
        'status' => (int)$user['active'] === 1 ? 'Ativo' : 'Desativado',
        'created_at' => date('d/m/Y', strtotime((string)$user['created_at'])),
        'can_manage' => (int)$user['id'] !== (int)$admin['id'],
        'role_action_label' => $user['role'] === 'admin' ? 'Tornar usuário' : 'Tornar admin',
        'active_action_label' => (int)$user['active'] === 1 ? 'Desativar' : 'Ativar',
    ];
}, $rows);

render_page('admin/users', ['users' => $users], 'Usuários', true);
