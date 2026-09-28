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
    $installed = app_installed();
    $user = $installed ? current_user() : null;
    $isAdmin = ($user['role'] ?? '') === 'admin';
    $isRespondent = ($user['role'] ?? '') === 'respondent';
    $siteName = $installed ? app_setting('site_name', APP_NAME) : APP_NAME;
    $flashes = array_map(static fn(array $flash): array => [
        'type' => (string)($flash['type'] ?? 'info'),
        'message' => (string)($flash['message'] ?? ''),
    ], consume_flashes());

    return [
        'page_title' => $title === APP_NAME ? $siteName : $title . ' · ' . $siteName,
        'site_name' => $siteName,
        'registration_enabled' => !$installed || app_setting('registration_enabled', '1') === '1',
        'stylesheet_url' => base_url('assets/style.css') . '?v=' . APP_VERSION,
        'home_url' => base_url('/'),
        'ask_url' => base_url('ask'),
        'responses_url' => base_url('responses'),
        'admin_url' => base_url('admin/'),
        'logout_url' => base_url('logout'),
        'login_url' => base_url('login'),
        'register_url' => base_url('register'),
        'admin_index_url' => base_url('admin/'),
        'admin_questions_url' => base_url('admin/questions'),
        'admin_users_url' => base_url('admin/users'),
        'admin_focuses_url' => base_url('admin/focuses'),
        'admin_categories_url' => base_url('admin/categories'),
        'admin_targets_url' => base_url('admin/targets'),
        'admin_moderation_url' => base_url('admin/moderation'),
        'admin_reports_url' => base_url('admin/reports'),
        'admin_history_url' => base_url('admin/history'),
        'admin_settings_url' => base_url('admin/settings'),
        'logged_in' => $user !== null,
        'logged_out' => $user === null,
        'user_is_admin' => $isAdmin,
        'user_is_respondent' => $isRespondent,
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
