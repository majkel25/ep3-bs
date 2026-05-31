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

function ssaApiDateParam(string $name, ?string $default = null): string
{
    $value = isset($_GET[$name]) ? trim((string)$_GET[$name]) : $default;

    if (!$value || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" must be in YYYY-MM-DD format.',
        ]);
    }

    $parts = explode('-', $value);

    if (!checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0])) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" is not a valid date.',
        ]);
    }

    return $value;
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

function ssaApiDateTimeIso(string $date, $time): ?string
{
    $normalisedTime = ssaApiNormaliseTimeValue($time);

    if (!$normalisedTime) {
        return null;
    }

    return $date . 'T' . $normalisedTime . ':00+00:00';
}

function ssaApiGetOptionalIntParam(string $name): ?int
{
    if (!isset($_GET[$name]) || trim((string)$_GET[$name]) === '') {
        return null;
    }

    $value = trim((string)$_GET[$name]);

    if (!ctype_digit($value)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_' . $name,
            'message' => 'Parameter "' . $name . '" must be a positive integer.',
        ]);
    }

    return (int)$value;
}

try {
    $today = gmdate('Y-m-d');
    $defaultTo = gmdate('Y-m-d', strtotime('+7 days'));

    $from = ssaApiDateParam('from', $today);
    $to = ssaApiDateParam('to', $defaultTo);
    $tableId = ssaApiGetOptionalIntParam('tableId');

    if (strtotime($to) < strtotime($from)) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_date_range',
            'message' => 'Parameter "to" must be the same as or after "from".',
        ]);
    }

    $maxRangeSeconds = 60 * 60 * 24 * 62;

    if ((strtotime($to) - strtotime($from)) > $maxRangeSeconds) {
        ssaApiJsonResponse(400, [
            'error' => 'date_range_too_large',
            'message' => 'Date range must not exceed 62 days.',
        ]);
    }

    $pdo = ssaApiCreatePdo();

    $sql = 'SELECT
            r.rid,
            r.bid,
            r.date,
            r.time_start,
            r.time_end,
            b.uid,
            b.sid,
            b.status AS booking_status,
            b.status_billing,
            b.visibility,
            b.quantity,
            b.created,
            s.name AS table_name,
            s.status AS table_status
        FROM bs_reservations r
        INNER JOIN bs_bookings b ON b.bid = r.bid
        LEFT JOIN bs_squares s ON s.sid = b.sid
        WHERE r.date >= :from
          AND r.date <= :to
          AND b.status <> :cancelledStatus';

    $params = [
        'from' => $from,
        'to' => $to,
        'cancelledStatus' => 'cancelled',
    ];

    if ($tableId !== null) {
        $sql .= ' AND b.sid = :tableId';
        $params['tableId'] = $tableId;
    }

    $sql .= ' ORDER BY r.date ASC, r.time_start ASC, b.sid ASC, r.rid ASC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $rows = $statement->fetchAll();

    $reservations = [];

    foreach ($rows as $row) {
        $date = (string)$row['date'];

        $reservations[] = [
            'reservationId' => isset($row['rid']) ? (int)$row['rid'] : null,
            'bookingId' => isset($row['bid']) ? (int)$row['bid'] : null,
            'userId' => isset($row['uid']) ? (int)$row['uid'] : null,
            'tableId' => isset($row['sid']) ? (int)$row['sid'] : null,
            'tableName' => $row['table_name'] ?? null,
            'tableStatus' => $row['table_status'] ?? null,
            'date' => $date,
            'timeStart' => ssaApiNormaliseTimeValue($row['time_start'] ?? null),
            'timeEnd' => ssaApiNormaliseTimeValue($row['time_end'] ?? null),
            'start' => ssaApiDateTimeIso($date, $row['time_start'] ?? null),
            'end' => ssaApiDateTimeIso($date, $row['time_end'] ?? null),
            'status' => 'occupied',
            'bookingStatus' => $row['booking_status'] ?? null,
            'billingStatus' => $row['status_billing'] ?? null,
            'visibility' => $row['visibility'] ?? null,
            'quantity' => isset($row['quantity']) ? (int)$row['quantity'] : null,
            'created' => $row['created'] ?? null,
        ];
    }

    ssaApiJsonResponse(200, [
        'status' => 'ok',
        'source' => 'live_database',
        'mode' => 'occupied_reservations_only',
        'from' => $from,
        'to' => $to,
        'filter' => [
            'tableId' => $tableId,
        ],
        'count' => count($reservations),
        'reservations' => $reservations,
    ]);
} catch (Throwable $exception) {
    error_log('SSA API bookings endpoint failed: ' . $exception->getMessage());

    ssaApiJsonResponse(500, [
        'error' => 'bookings_query_failed',
        'message' => 'Unable to read bookings from the booking database.',
    ]);
}