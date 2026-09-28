<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['GET']);
$actor = api_require_auth();

api_json([
    'ok' => true,
    'name' => 'Pergunta.Online ChatGPT API',
    'version' => '1.1',
    'actor' => ['id' => (int)$actor['id'], 'email' => (string)$actor['email']],
    'endpoints' => [
        'GET catalog' => 'catalog',
        'GET search' => 'search?q=texto',
        'POST focuses' => 'focuses',
        'POST categories' => 'categories',
        'POST targets' => 'targets',
        'POST questions' => 'questions',
        'POST evidences' => 'evidences',
        'POST import' => 'import',
        'POST blog' => 'blog',
        'GET OpenAPI' => 'openapi',
    ],
]);
