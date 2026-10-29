<?php

declare(strict_types=1);

// Development-only partner endpoint. It stores no payloads or credentials.
if ($_SERVER['REQUEST_URI'] === '/health') {
    echo 'ok';

    return;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $path !== '/events') {
    http_response_code(404);

    return;
}

header('Content-Type: application/json');
$body = json_decode(file_get_contents('php://input'), true);
if (! is_array($body) || ! isset($body['click_id'], $body['event'], $body['amount'])) {
    http_response_code(422);
    echo json_encode(['status' => 'invalid']);

    return;
}

echo json_encode(['status' => 'ok']);
