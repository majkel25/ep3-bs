<?php
require_once __DIR__ . '/_auth0.php';

ssaApiRequireAuth0Claims();

function ssaApiAppRoot(): string
{
    $root = realpath(__DIR__ . '/../../../..');

    if (!$root) {
        ssaApiJsonResponse(500, [
            'error' => 'app_root_not_found',
            'message' => 'Unable to determine application root.',
        ]);
    }

    return $root;
}

function ssaApiLoadLocalConfig(): array
{
    $configFile = ssaApiAppRoot() . '/config/autoload/local.php';

    if (!is_file($configFile)) {
        ssaApiJsonResponse(500, [
            'error' => 'local_config_not_found',
            'message' => 'Database configuration file was not found.',
        ]);
    }

    $config = include $configFile;

    if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
        ssaApiJsonResponse(500, [
            'error' => 'db_config_invalid',
            'message' => 'Database configuration is invalid.',
        ]);
    }

    return $config;
}

function ssaApiCreatePdo(): PDO
{
    $config = ssaApiLoadLocalConfig();
    $db = $config['db'];

    $database = $db['database'] ?? null;
    $username = $db['username'] ?? null;
    $password = $db['password'] ?? null;
    $hostname = $db['hostname'] ?? ($db['host'] ?? 'localhost');
    $port = $db['port'] ?? null;

    if (!$database || !$username) {
        ssaApiJsonResponse(500, [
            'error' => 'db_config_missing',
            'message' => 'Database name or username is missing.',
        ]);
    }

    $dsn = 'mysql:host=' . $hostname . ';dbname=' . $database . ';charset=utf8mb4';

    if ($port) {
        $dsn .= ';port=' . $port;
    }

    try {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (Throwable $exception) {
        error_log('SSA API DB connection failed: ' . $exception->getMessage());

        ssaApiJsonResponse(500, [
            'error' => 'db_connection_failed',
            'message' => 'Unable to connect to the booking database.',
        ]);
    }
}

function ssaApiNormaliseTimeValue($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $seconds = (int)$value;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    $value = (string)$value;

    if (preg_match('/^\d{2}:\d{2}/', $value)) {
        return substr($value, 0, 5);
    }

    return $value;
}

function ssaApiBlockMinutes($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $seconds = (int)$value;

        if ($seconds >= 60) {
            return (int)round($seconds / 60);
        }

        return $seconds;
    }

    return null;
}

function ssaApiGetStatusFilter(): ?string
{
    if (!isset($_GET['status']) || trim((string)$_GET['status']) === '') {
        return null;
    }

    $status = strtolower(trim((string)$_GET['status']));
    $allowedStatuses = ['enabled', 'disabled', 'all'];

    if (!in_array($status, $allowedStatuses, true)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_status_filter',
            'message' => 'The status filter must be one of: enabled, disabled, all.',
        ]);
    }

    if ($status === 'all') {
        return null;
    }

    return $status;
}

try {
    $pdo = ssaApiCreatePdo();
    $statusFilter = ssaApiGetStatusFilter();

    $sql = 'SELECT
            sid,
            name,
            status,
            priority,
            capacity,
            time_start,
            time_end,
            time_block,
            time_block_bookable,
            time_block_bookable_max,
            min_range_book,
            range_book,
            max_active_bookings,
            range_cancel
         FROM bs_squares';

    $params = [];

    if ($statusFilter !== null) {
        $sql .= ' WHERE status = :status';
        $params['status'] = $statusFilter;
    }

    $sql .= ' ORDER BY priority ASC, sid ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $rows = $statement->fetchAll();

    $tables = [];

    foreach ($rows as $row) {
        $tables[] = [
            'id' => isset($row['sid']) ? (int)$row['sid'] : null,
            'name' => $row['name'] ?? null,
            'status' => $row['status'] ?? null,
            'priority' => isset($row['priority']) ? (int)$row['priority'] : null,
            'capacity' => isset($row['capacity']) ? (int)$row['capacity'] : null,
            'timeStart' => ssaApiNormaliseTimeValue($row['time_start'] ?? null),
            'timeEnd' => ssaApiNormaliseTimeValue($row['time_end'] ?? null),
            'timeBlockSeconds' => isset($row['time_block']) && is_numeric($row['time_block']) ? (int)$row['time_block'] : null,
            'timeBlockMinutes' => ssaApiBlockMinutes($row['time_block'] ?? null),
            'timeBlockBookableSeconds' => isset($row['time_block_bookable']) && is_numeric($row['time_block_bookable']) ? (int)$row['time_block_bookable'] : null,
            'timeBlockBookableMaxSeconds' => isset($row['time_block_bookable_max']) && is_numeric($row['time_block_bookable_max']) ? (int)$row['time_block_bookable_max'] : null,
            'minRangeBookSeconds' => isset($row['min_range_book']) && is_numeric($row['min_range_book']) ? (int)$row['min_range_book'] : null,
            'rangeBookSeconds' => isset($row['range_book']) && is_numeric($row['range_book']) ? (int)$row['range_book'] : null,
            'maxActiveBookings' => isset($row['max_active_bookings']) ? (int)$row['max_active_bookings'] : null,
            'rangeCancelSeconds' => isset($row['range_cancel']) && is_numeric($row['range_cancel']) ? (int)$row['range_cancel'] : null,
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
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