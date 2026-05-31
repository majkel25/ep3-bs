<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

http_response_code(200);

echo json_encode([
    'ok' => true,
    'service' => 'ssa-booking-api',
    'version' => '1.0.0',
    'environment' => 'api-development',
    'timestamp' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
