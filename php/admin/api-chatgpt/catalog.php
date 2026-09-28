<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['GET']);
api_require_auth();

$includeInactive = api_bool($_GET['include_inactive'] ?? null, false);
$where = $includeInactive ? '' : ' WHERE active=1';

$focuses = db()->query("SELECT id,name,abbr,active FROM focuses{$where} ORDER BY name")->fetchAll();
$categories = db()->query("SELECT id,name,active FROM categories{$where} ORDER BY name")->fetchAll();
$targets = db()->query("SELECT id,name,description,website,contact_email,active FROM targets{$where} ORDER BY name")->fetchAll();

api_json([
    'ok' => true,
    'data' => [
        'focuses' => $focuses,
        'categories' => $categories,
        'targets' => $targets,
    ],
]);
