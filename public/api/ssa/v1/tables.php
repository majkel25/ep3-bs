<?php
require_once __DIR__ . '/_auth0.php';
require_once __DIR__ . '/_db.php';

ssaApiRequireAuth0Claims();

try {
    $pdo = ssaApiCreatePdo();
    $statusFilter = ssaApiGetStatusFilter(null);

    $rows = ssaApiFetchSquares($pdo, $statusFilter);
    $tables = [];

    foreach ($rows as $row) {
        $tables[] = ssaApiFormatSquareRow($row);
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
        'timezone' => SSA_API_TIMEZONE,
        'filter' => [
            'status' => $statusFilter ?? 'all',
        ],
        'count' => count($tables),
        'tables' => $tables,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API tables endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'tables_query_failed',
        'message' => 'Unable to read tables from the booking database.',
    ]);
}