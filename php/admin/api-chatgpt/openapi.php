<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['GET']);

$root = api_root_url();
if ($root === '') {
    $root = 'https://SEU-DOMINIO.EXEMPLO';
}
$server = rtrim($root, '/') . '/admin/api-chatgpt';

$evidenceSchema = [
    'type' => 'object',
    'required' => ['title', 'type'],
    'properties' => [
        'title' => ['type' => 'string'],
        'type' => ['type' => 'string', 'enum' => ['url', 'text']],
        'url' => ['type' => 'string', 'format' => 'uri'],
        'text' => ['type' => 'string'],
    ],
];

$questionSchema = [
    'type' => 'object',
    'required' => ['title', 'body', 'focus', 'category', 'target'],
    'properties' => [
        'title' => ['type' => 'string', 'minLength' => 15],
        'body' => ['type' => 'string', 'minLength' => 20],
        'focus' => [
            'type' => 'object',
            'required' => ['name', 'abbr'],
            'properties' => [
                'name' => ['type' => 'string'],
                'abbr' => ['type' => 'string', 'maxLength' => 12],
            ],
        ],
        'category' => ['oneOf' => [['type' => 'string'], ['type' => 'object']]],
        'target' => ['oneOf' => [['type' => 'string'], ['type' => 'object']]],
        'status' => ['type' => 'string', 'enum' => ['open', 'waiting', 'taken', 'answered', 'archived']],
        'published' => ['type' => 'boolean'],
        'moderation_status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected']],
        'answer_text' => ['type' => 'string'],
        'evidence' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Evidence']],
    ],
];

$batchSchema = [
    'type' => 'object',
    'required' => ['items'],
    'properties' => [
        'items' => [
            'type' => 'array',
            'maxItems' => 50,
            'items' => ['$ref' => '#/components/schemas/Question'],
        ],
    ],
];

api_json([
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'Pergunta.Online ChatGPT API',
        'version' => '1.0.0',
        'description' => 'API para pesquisa assistida por ChatGPT e cadastro controlado de focos, categorias, destinatários, perguntas e evidências.',
    ],
    'servers' => [['url' => $server]],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'API token',
            ],
        ],
        'schemas' => [
            'Evidence' => $evidenceSchema,
            'Question' => $questionSchema,
        ],
    ],
    'security' => [['bearerAuth' => []]],
    'paths' => [
        '/catalog' => [
            'get' => [
                'operationId' => 'getCatalog',
                'summary' => 'Lista focos, categorias e destinatários existentes.',
                'responses' => ['200' => ['description' => 'Catálogo atual']],
            ],
        ],
        '/search' => [
            'get' => [
                'operationId' => 'searchQuestions',
                'summary' => 'Procura perguntas existentes antes de cadastrar uma nova.',
                'parameters' => [[
                    'name' => 'q',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 3],
                ]],
                'responses' => ['200' => ['description' => 'Perguntas encontradas']],
            ],
        ],
        '/questions' => [
            'post' => [
                'operationId' => 'createQuestions',
                'summary' => 'Cria uma ou mais perguntas e cria ou reutiliza foco, categoria e destinatário.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/Question'],
                                    $batchSchema,
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => ['200' => ['description' => 'Resultado do cadastro']],
            ],
        ],
        '/import' => [
            'post' => [
                'operationId' => 'importResearch',
                'summary' => 'Importa em lote perguntas pesquisadas na web com suas fontes.',
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => ['schema' => $batchSchema],
                    ],
                ],
                'responses' => ['200' => ['description' => 'Resultado da importação']],
            ],
        ],
    ],
]);
