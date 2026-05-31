<?php

define('SSA_API_TIMEZONE', 'Europe/London');

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

function ssaApiValidateDateRange(string $from, string $to, int $maxDays = 62): void
{
    $fromTime = strtotime($from);
    $toTime = strtotime($to);

    if ($toTime < $fromTime) {
        ssaApiJsonResponse(400, [
            'error' => 'invalid_date_range',
            'message' => 'Parameter "to" must be the same as or after "from".',
        ]);
    }

    if (($toTime - $fromTime) > (60 * 60 * 24 * $maxDays)) {
        ssaApiJsonResponse(400, [
            'error' => 'date_range_too_large',
            'message' => 'Date range must not exceed ' . $maxDays . ' days.',
        ]);
    }
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

function ssaApiNormaliseTimeValue($value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return ssaApiSecondsToTime((int)$value);
    }

    $value = (string)$value;

    if (preg_match('/^\d{2}:\d{2}/', $value)) {
        return substr($value, 0, 5);
    }

    return $value;
}

function ssaApiTimeToSeconds($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        return (int)$value;
    }

    $value = (string)$value;

    if (!preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
        return null;
    }

    return ((int)$matches[1] * 3600) + ((int)$matches[2] * 60);
}

function ssaApiSecondsToTime(int $seconds): string
{
    $seconds = max(0, $seconds);

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return sprintf('%02d:%02d', $hours, $minutes);
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

function ssaApiDateTimeLocalIso(string $date, $time): ?string
{
    $timeValue = ssaApiNormaliseTimeValue($time);

    if (!$timeValue) {
        return null;
    }

    try {
        $timezone = new DateTimeZone(SSA_API_TIMEZONE);
        $dateTime = new DateTimeImmutable($date . ' ' . $timeValue . ':00', $timezone);

        return $dateTime->format(DateTimeInterface::ATOM);
    } catch (Throwable $exception) {
        return null;
    }
}

function ssaApiGetStatusFilter(?string $default = null): ?string
{
    if (!isset($_GET['status']) || trim((string)$_GET['status']) === '') {
        return $default;
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

function ssaApiFetchSquares(PDO $pdo, ?string $statusFilter = null): array
{
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

    return $statement->fetchAll();
}

function ssaApiFormatSquareRow(array $row): array
{
    return [
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
