<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';
api_method(['POST']);
$actor = api_require_auth();

api_handle(static function() use ($actor): array {
    $items = api_payload_items();
    $result = [];
    db()->beginTransaction();
    foreach ($items as $item) {
        $result[] = api_target_upsert($item, $actor);
    }
    db()->commit();
    return $result;
});
