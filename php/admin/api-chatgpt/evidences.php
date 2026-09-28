<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['POST']);
$actor = api_require_auth();

api_handle(static function() use ($actor): array {
    $payload = api_payload();
    $questionId = (int)($payload['question_id'] ?? 0);
    if ($questionId <= 0) {
        throw new InvalidArgumentException('question_id é obrigatório.');
    }

    $check = db()->prepare('SELECT id FROM questions WHERE id=? AND deleted_at IS NULL');
    $check->execute([$questionId]);
    if (!$check->fetchColumn()) {
        throw new InvalidArgumentException('Pergunta não encontrada.');
    }

    $items = $payload['items'] ?? ($payload['evidence'] ?? []);
    if (!is_array($items) || !array_is_list($items) || $items === []) {
        throw new InvalidArgumentException('Envie items com ao menos uma evidência.');
    }
    if (count($items) > 50) {
        throw new InvalidArgumentException('Envie no máximo 50 evidências por requisição.');
    }

    $result = [];
    db()->beginTransaction();
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Cada evidência precisa ser um objeto.');
        }
        $result[] = api_evidence_add($questionId, $item, $actor);
    }
    db()->commit();

    return ['question_id' => $questionId, 'evidence' => $result];
});
