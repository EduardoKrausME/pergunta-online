<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/mustache.php';

function template_renderer(): MustacheRenderer {
    static $renderer = null;
    if (!$renderer instanceof MustacheRenderer) {
        $renderer = new MustacheRenderer(dirname(__DIR__) . '/templates');
    }
    return $renderer;
}

function template_base_context(string $title = APP_NAME, bool $admin = false): array {
    $user = app_installed() ? current_user() : null;
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $flashes = array_map(static fn(array $flash): array => [
        'type' => (string)($flash['type'] ?? 'info'),
        'message' => (string)($flash['message'] ?? ''),
    ], consume_flashes());

    return [
        'page_title' => $title === APP_NAME ? APP_NAME : $title . ' · ' . APP_NAME,
        'stylesheet_url' => base_url('assets/style.css') . '?v=' . APP_VERSION,
        'home_url' => base_url('index.php'),
        'ask_url' => base_url('ask.php'),
        'admin_url' => base_url('admin/index.php'),
        'logout_url' => base_url('logout.php'),
        'login_url' => base_url('login.php'),
        'register_url' => base_url('register.php'),
        'admin_index_url' => base_url('admin/index.php'),
        'admin_questions_url' => base_url('admin/questions.php'),
        'admin_users_url' => base_url('admin/users.php'),
        'admin_focuses_url' => base_url('admin/focuses.php'),
        'admin_categories_url' => base_url('admin/categories.php'),
        'admin_targets_url' => base_url('admin/targets.php'),
        'admin_moderation_url' => base_url('admin/moderation.php'),
        'admin_reports_url' => base_url('admin/reports.php'),
        'admin_history_url' => base_url('admin/history.php'),
        'admin_settings_url' => base_url('admin/settings.php'),
        'logged_in' => $user !== null,
        'logged_out' => $user === null,
        'user_is_admin' => $isAdmin,
        'user_name' => (string)($user['name'] ?? ''),
        'show_admin_bar' => $admin,
        'flashes' => $flashes,
        'csrf_token' => csrf_token(),
        'year' => date('Y'),
    ];
}

function render_page(string $template, array $context = [], string $title = APP_NAME, bool $admin = false): void {
    echo template_renderer()->render($template, array_merge(template_base_context($title, $admin), $context));
}
