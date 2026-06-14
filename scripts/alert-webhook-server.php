<?php

declare(strict_types=1);

$eventsFile = '/data/events.jsonl';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $path === '/health') {
    respond(['status' => 'ok']);
}

if ($method === 'POST' && $path === '/reset') {
    file_put_contents($eventsFile, '');
    respond(['status' => 'reset']);
}

if ($method === 'POST' && $path === '/alerts') {
    $notification = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
    $receivedAt = gmdate(DATE_ATOM);

    foreach ($notification['alerts'] ?? [] as $alert) {
        file_put_contents($eventsFile, json_encode([
            'received_at' => $receivedAt,
            'status' => $alert['status'] ?? $notification['status'] ?? 'unknown',
            'labels' => $alert['labels'] ?? [],
            'annotations' => $alert['annotations'] ?? [],
        ], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    respond(['status' => 'accepted'], 202);
}

if ($method === 'GET' && $path === '/events') {
    $events = [];

    if (is_file($eventsFile)) {
        foreach (file($eventsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $events[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    respond(['events' => $events]);
}

respond(['message' => 'Not found.'], 404);

/**
 * @param  array<string, mixed>  $payload
 */
function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}
