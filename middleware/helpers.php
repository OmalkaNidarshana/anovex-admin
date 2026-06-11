<?php
// middleware/helpers.php

function jsonResponse(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getBody(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function only(array $data, array $keys): array
{
    return array_intersect_key($data, array_flip($keys));
}

function missingFields(array $data, array $required): array
{
    return array_filter($required, fn($f) => empty($data[$f]) && $data[$f] !== 0);
}
