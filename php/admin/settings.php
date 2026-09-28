<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
if (!app_installed()) {
    redirect('install.php');
}
$admin = require_admin();

$definitions = [
    'site_name' => ['label' => 'Nome do site', 'type' => 'text', 'default' => 'Pergunta.Online'],
    'registration_enabled' => ['label' => 'Permitir cadastro público', 'type' => 'bool', 'default' => '1'],
    'auto_publish_questions' => ['label' => 'Publicar perguntas automaticamente', 'type' => 'bool', 'default' => '1'],
    'silence_threshold_days' => ['label' => 'Dias para destacar silêncio', 'type' => 'int', 'default' => '7'],
    'max_questions_per_page' => ['label' => 'Perguntas por página', 'type' => 'int', 'default' => '50'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $upsert = db()->prepare("
        INSERT INTO app_settings (setting_key,setting_value) VALUES (?,?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)
    ");

    foreach ($definitions as $key => $definition) {
        if ($definition['type'] === 'bool') {
            $value = Request::post($key, Request::BOOL, false) ? '1' : '0';
        } elseif ($definition['type'] === 'int') {
            $value = (string)max(1, (int)Request::post($key, Request::INT, (int)$definition['default']));
            if ($key === 'max_questions_per_page') {
                $value = (string)min(100, max(10, (int)$value));
            }
            if ($key === 'silence_threshold_days') {
                $value = (string)min(365, max(1, (int)$value));
            }
        } else {
            $value = trim((string)Request::post($key, Request::STRING, $definition['default']));
            if ($value === '') {
                $value = $definition['default'];
            }
        }
        $upsert->execute([$key, $value]);
    }
    audit_log((int)$admin['id'], 'settings.updated', 'settings', null);
    flash('success', 'Configurações salvas.');
    redirect('admin/settings.php');
}

$settings = [];
foreach ($definitions as $key => $definition) {
    $value = app_setting($key, $definition['default']);
    $settings[$key] = $value;
}

render_page('admin/settings', [
    'site_name' => $settings['site_name'],
    'registration_enabled' => $settings['registration_enabled'] === '1',
    'auto_publish_questions' => $settings['auto_publish_questions'] === '1',
    'silence_threshold_days' => $settings['silence_threshold_days'],
    'max_questions_per_page' => $settings['max_questions_per_page'],
], 'Configurações', true);
