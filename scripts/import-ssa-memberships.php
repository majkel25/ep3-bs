<?php

declare(strict_types=1);

// FILE: scripts/import-ssa-memberships.php

/**
 * SSA membership Excel import script.
 *
 * Reads the club's membership spreadsheet and imports legacy memberships into
 * ssa_user_memberships, matching rows to bs_users accounts using a strict
 * priority-ordered matching strategy.
 *
 * SECURITY RULES (enforced throughout):
 *   - NEVER read, log, store, or output the PIN column
 *   - NEVER read locker, cue-store, or WhatsApp columns
 *   - Read ONLY: Member, Account no., Package, Joined, Email, Mobile
 *   - NEVER use account number as a uid or user identifier — matching only
 *   - DO NOT write the Excel file, SQL dumps, or reconciliation reports to disk
 *
 * Usage:
 *   php scripts/import-ssa-memberships.php --file="/path/to/SSA Memberships.xlsx" --dry-run
 *   php scripts/import-ssa-memberships.php --file="/path/to/SSA Memberships.xlsx" --apply
 */

if (!function_exists('ssaApiJsonResponse')) {
    function ssaApiJsonResponse(int $statusCode, array $payload): void
    {
        throw new RuntimeException(
            'ssaApiJsonResponse called during CLI import: HTTP ' .
            $statusCode . ' ' .
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

// scripts/ is one level below app root
require_once __DIR__ . '/../public/api/ssa/v1/_db.php';

// PhpSpreadsheet lives in scripts/vendor/
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "vendor/autoload.php not found. Run: cd scripts && composer install\n");
    exit(1);
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Cell;

// ─────────────────────────────────────────────────────────────────────────────
// Argument parsing
// ─────────────────────────────────────────────────────────────────────────────

$filePath = null;
$dryRun   = false;
$apply    = false;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $filePath = substr($arg, strlen('--file='));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--apply') {
        $apply = true;
    }
}

if ($filePath === null) {
    fwrite(STDERR, "Usage: php import-ssa-memberships.php --file=\"/path/to/file.xlsx\" [--dry-run|--apply]\n");
    exit(1);
}

if ($dryRun && $apply) {
    fwrite(STDERR, "ERROR: --dry-run and --apply are mutually exclusive.\n");
    exit(1);
}

if (!$dryRun && !$apply) {
    fwrite(STDERR, "ERROR: You must specify either --dry-run or --apply.\n");
    exit(1);
}

if (!file_exists($filePath) || !is_readable($filePath)) {
    fwrite(STDERR, "ERROR: File not found or not readable: $filePath\n");
    exit(1);
}

$mode = $dryRun ? 'DRY RUN' : 'APPLY';
echo "[$mode] SSA Membership Import\n";
echo "File: $filePath\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Constants
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Expected row counts per package description (case-insensitive, trimmed).
 * Used to validate workbook totals before any writes.
 */
const EXPECTED_COUNTS = [
    'all day pass'                                 => 4,
    'all day pass (discounted - senior)'           => 1,
    'all day pass (discounted)'                    => 1,
    'coach'                                        => 1,
    'concession'                                   => 2,
    'daytime pass'                                 => 4,
    'daytime pass (discounted - junior)'           => 1,
    'daytime pass (discounted - senior)'           => 5,
    'daytime pass (upfront to 31/8/26)'            => 1,
    'pay as you play'                              => 46,
    'pay as you play (discounted - nhs)'           => 2,
    'pay as you play (discounted - police)'        => 1,
    'pay as you play (discounted - senior)'        => 2,
    'pay as you play (discounted - student)'       => 3,
    'pay as you play (junior)'                     => 2,
    'pay as you play (upfront - discounted - nhs)' => 1,
    'pay as you play (upfront)'                    => 11,
    'pro-package'                                  => 1,
    'special arrangement'                          => 1,
    'summer package'                               => 8,
];

const EXPECTED_TOTAL = 98;

/**
 * Maps normalised (lowercase, trimmed) package description → plan_key.
 * Descriptions not found here are flagged as unresolved.
 */
const PACKAGE_MAP = [
    'pay as you play'                              => 'red',
    'pay as you play (upfront)'                    => 'RED_STANDARD_UPFRONT',
    'pay as you play (junior)'                     => 'RED_JUNIOR',
    'pay as you play (discounted - nhs)'           => 'RED_NHS',
    'pay as you play (discounted - police)'        => 'RED_POLICE',
    'pay as you play (discounted - senior)'        => 'RED_SENIOR',
    'pay as you play (discounted - student)'       => 'RED_STUDENT',
    'pay as you play (upfront - discounted - nhs)' => 'RED_NHS_UPFRONT',
    'daytime pass'                                 => 'pink',
    'daytime pass (upfront to 31/8/26)'            => 'PINK_STANDARD_UPFRONT',
    'daytime pass (discounted - junior)'           => 'PINK_JUNIOR',
    'daytime pass (discounted - senior)'           => 'PINK_SENIOR',
    'all day pass'                                 => 'black',
    'all day pass (discounted - senior)'           => 'BLACK_SENIOR',
    'all day pass (discounted)'                    => 'BLACK_DISCOUNTED',
    'pro-package'                                  => 'gold',
    'summer package'                               => 'SUMMER_STANDARD',
    'concession'                                   => 'CONCESSION',
    'coach'                                        => 'COACH',
    'special arrangement'                          => 'SPECIAL_ARRANGEMENT',
];

/**
 * Account numbers with known ambiguity — skip account-no matching for these.
 */
const AMBIGUOUS_ACCOUNT_NUMBERS = ['0032'];

/**
 * Fixed end date for PINK_STANDARD_UPFRONT memberships.
 */
const PINK_UPFRONT_END_DATE = '2026-08-31 23:59:59';

// ─────────────────────────────────────────────────────────────────────────────
// Normalisation helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Normalise a name from "Surname, Firstname" → "Firstname Surname".
 * Trims, collapses spaces, lowercases.
 */
function normaliseName(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '';
    }
    $name = trim($raw);
    // Handle "Surname, Firstname" format
    if (str_contains($name, ',')) {
        [$surname, $firstname] = explode(',', $name, 2);
        $name = trim($firstname) . ' ' . trim($surname);
    }
    // Collapse multiple spaces
    $name = preg_replace('/\s+/', ' ', $name);
    return strtolower(trim($name));
}

/**
 * Normalise a UK mobile number for comparison.
 * Strips spaces, dashes, brackets; converts leading 07 to +447; drops country code prefix for matching.
 * Returns normalised string (e.g. "07911123456" → "447911123456") or empty string if invalid.
 */
function normaliseMobile(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '';
    }
    // Strip all non-digit characters except leading +
    $stripped = preg_replace('/[^\d+]/', '', trim($raw));
    // Remove leading +
    $stripped = ltrim($stripped, '+');
    // Convert leading 447 (i.e. +447...) — already stripped, so starts with 447
    // Convert leading 07 → 447
    if (str_starts_with($stripped, '07') && strlen($stripped) === 11) {
        $stripped = '44' . substr($stripped, 1);
    }
    // Accept 447xxxxxxxxx (12 digits) or 07xxxxxxxxx (11 digits, now converted)
    if (!preg_match('/^447\d{9}$/', $stripped)) {
        return '';
    }
    return $stripped;
}

/**
 * Normalise an account number for comparison: zero-pad to 4 digits.
 */
function normaliseAccountNumber(?string $raw): string
{
    if ($raw === null || trim($raw) === '') {
        return '';
    }
    $stripped = trim($raw);
    // Remove any leading zeroes first, then re-pad to 4 digits
    $stripped = ltrim($stripped, '0');
    if ($stripped === '') {
        return '0000';
    }
    return str_pad($stripped, 4, '0', STR_PAD_LEFT);
}

/**
 * Attempt to parse a joined date from the spreadsheet cell value.
 * Returns 'Y-m-d' string or null if unparseable.
 */
function parseJoinedDate(mixed $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    // PhpSpreadsheet may return a float (Excel serial date) or a string.
    if (is_float($raw) || is_int($raw)) {
        try {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$raw);
            return $date->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
    $str = trim((string)$raw);
    if ($str === '') {
        return null;
    }
    // Try common date formats
    $formats = ['d/m/Y', 'Y-m-d', 'd-m-Y', 'd/m/y', 'j/n/Y', 'j/n/y'];
    foreach ($formats as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $str);
        if ($dt !== false) {
            return $dt->format('Y-m-d');
        }
    }
    // Last resort: strtotime
    $ts = strtotime($str);
    if ($ts !== false) {
        return date('Y-m-d', $ts);
    }
    return null;
}

/**
 * Simple Levenshtein-based fuzzy name match. Returns similarity ratio 0.0–1.0.
 */
function nameSimilarity(string $a, string $b): float
{
    if ($a === '' || $b === '') {
        return 0.0;
    }
    $distance = levenshtein($a, $b);
    $maxLen = max(strlen($a), strlen($b));
    return 1.0 - ($distance / $maxLen);
}

// ─────────────────────────────────────────────────────────────────────────────
// DB connection
// ─────────────────────────────────────────────────────────────────────────────

try {
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "DB connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
// Check for bs_users_meta table
// ─────────────────────────────────────────────────────────────────────────────

$hasUsersMeta = (bool)(int)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bs_users_meta'"
)->fetchColumn();

echo "bs_users_meta table: " . ($hasUsersMeta ? "found" : "NOT FOUND — account_no and meta phone matching disabled") . "\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Check for duplicate import (by SHA-256 hash)
// ─────────────────────────────────────────────────────────────────────────────

$fileHash = hash_file('sha256', $filePath);
if ($fileHash === false) {
    fwrite(STDERR, "ERROR: Could not hash file.\n");
    exit(1);
}
echo "File SHA-256: $fileHash\n";

$existingBatch = $pdo->prepare(
    'SELECT id, status, imported_rows, created_at
     FROM ssa_membership_import_batches
     WHERE source_sha256 = :hash
     LIMIT 1'
);
$existingBatch->execute(['hash' => $fileHash]);
$existingBatchRow = $existingBatch->fetch(PDO::FETCH_ASSOC);

if ($existingBatchRow) {
    echo "WARNING: This file has already been imported.\n";
    echo "  Batch ID: {$existingBatchRow['id']}\n";
    echo "  Status:   {$existingBatchRow['status']}\n";
    echo "  Imported: {$existingBatchRow['imported_rows']} rows\n";
    echo "  Date:     {$existingBatchRow['created_at']}\n";
    if ($apply) {
        fwrite(STDERR, "\nERROR: Refusing to re-import a file that has already been processed. Use --dry-run to re-inspect.\n");
        exit(1);
    }
    echo "  Continuing in dry-run mode for inspection only.\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// Load plan_key → plan row map from DB
// ─────────────────────────────────────────────────────────────────────────────

$plansByKey = [];
$allPlansStmt = $pdo->query(
    'SELECT id, plan_key, display_name, monthly_price_pence, currency
     FROM ssa_membership_plans'
);
foreach ($allPlansStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $plansByKey[$row['plan_key']] = $row;
}

// ─────────────────────────────────────────────────────────────────────────────
// Read spreadsheet — SECURITY: only touch allowed columns
// ─────────────────────────────────────────────────────────────────────────────

echo "Reading spreadsheet...\n";

try {
    $spreadsheet = IOFactory::load($filePath);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: Could not open spreadsheet: " . $e->getMessage() . "\n");
    exit(1);
}

$sheet = $spreadsheet->getActiveSheet();
$highestRow = $sheet->getHighestRow();
$highestCol = $sheet->getHighestColumn();

// Read header row to find allowed column indices.
// SECURITY: we only map these exact column names; all others are ignored.
const ALLOWED_HEADERS = ['member', 'account no.', 'package', 'joined', 'email', 'mobile'];
const FORBIDDEN_HEADERS = ['pin', 'locker', 'cue-store', 'cue store', 'whatsapp'];

$headerMap = []; // lower-trimmed header name → column letter

$colIterator = $sheet->getColumnIterator('A', $highestCol);
foreach ($colIterator as $col) {
    $colLetter = $col->getColumnIndex();
    $cellValue = $sheet->getCell($colLetter . '1')->getValue();
    if ($cellValue === null) {
        continue;
    }
    $normalised = strtolower(trim((string)$cellValue));

    // Explicitly refuse to map forbidden columns.
    foreach (FORBIDDEN_HEADERS as $forbidden) {
        if ($normalised === $forbidden || str_contains($normalised, $forbidden)) {
            // Do NOT map — silently skip.
            continue 2;
        }
    }

    if (in_array($normalised, ALLOWED_HEADERS, true)) {
        $headerMap[$normalised] = $colLetter;
    }
}

$requiredColumns = ['member', 'account no.', 'package', 'joined', 'email', 'mobile'];
foreach ($requiredColumns as $req) {
    if (!isset($headerMap[$req])) {
        fwrite(STDERR, "ERROR: Required column '$req' not found in spreadsheet.\n");
        fwrite(STDERR, "Columns detected (allowed only): " . implode(', ', array_keys($headerMap)) . "\n");
        exit(1);
    }
}

echo "Columns mapped: " . implode(', ', array_keys($headerMap)) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Parse all data rows
// ─────────────────────────────────────────────────────────────────────────────

$rows = []; // array of parsed row arrays

for ($rowNum = 2; $rowNum <= $highestRow; $rowNum++) {
    // Read only allowed columns.
    $memberName   = trim((string)($sheet->getCell($headerMap['member']     . $rowNum)->getValue() ?? ''));
    $accountNoRaw = trim((string)($sheet->getCell($headerMap['account no.'] . $rowNum)->getValue() ?? ''));
    $packageRaw   = trim((string)($sheet->getCell($headerMap['package']    . $rowNum)->getValue() ?? ''));
    $joinedRaw    = $sheet->getCell($headerMap['joined'] . $rowNum)->getValue();
    $emailRaw     = trim((string)($sheet->getCell($headerMap['email']      . $rowNum)->getValue() ?? ''));
    $mobileRaw    = trim((string)($sheet->getCell($headerMap['mobile']     . $rowNum)->getValue() ?? ''));

    // Skip completely empty rows.
    if ($memberName === '' && $accountNoRaw === '' && $packageRaw === '') {
        continue;
    }

    $rows[] = [
        'row_number'         => $rowNum,
        'member_name'        => $memberName !== '' ? $memberName : null,
        'account_no_raw'     => $accountNoRaw !== '' ? $accountNoRaw : null,
        'package_raw'        => $packageRaw !== '' ? $packageRaw : null,
        'joined_raw'         => $joinedRaw,
        'email_raw'          => $emailRaw !== '' ? $emailRaw : null,
        'mobile_raw'         => $mobileRaw !== '' ? $mobileRaw : null,
    ];
}

echo "Data rows read: " . count($rows) . "\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Validate workbook totals
// ─────────────────────────────────────────────────────────────────────────────

echo "Validating workbook totals...\n";

$actualCounts = [];
foreach ($rows as $row) {
    $normKey = preg_replace('/\s+/', ' ', strtolower(trim((string)($row['package_raw'] ?? ''))));
    $actualCounts[$normKey] = ($actualCounts[$normKey] ?? 0) + 1;
}

$validationErrors = [];

foreach (EXPECTED_COUNTS as $description => $expectedCount) {
    $actual = $actualCounts[$description] ?? 0;
    if ($actual !== $expectedCount) {
        $validationErrors[] = sprintf(
            "  MISMATCH: '%s' — expected %d, got %d",
            $description,
            $expectedCount,
            $actual
        );
    }
}

$actualTotal = count($rows);
if ($actualTotal !== EXPECTED_TOTAL) {
    $validationErrors[] = sprintf(
        "  TOTAL MISMATCH: expected %d rows, got %d",
        EXPECTED_TOTAL,
        $actualTotal
    );
}

if (!empty($validationErrors)) {
    echo "VALIDATION FAILED:\n";
    foreach ($validationErrors as $err) {
        echo $err . "\n";
    }
    // Show unexpected descriptions that aren't in the expected set
    $unexpectedDescriptions = array_diff_key($actualCounts, EXPECTED_COUNTS);
    if (!empty($unexpectedDescriptions)) {
        echo "  Unexpected package descriptions found:\n";
        foreach ($unexpectedDescriptions as $desc => $cnt) {
            if ($desc !== '') {
                echo "    '$desc' ($cnt rows)\n";
            }
        }
    }
    fwrite(STDERR, "\nAborting: workbook totals do not match expected values.\n");
    exit(1);
}

echo "OK: all " . EXPECTED_TOTAL . " rows validated.\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Pre-load bs_users for matching (email + phone + alias, no PIN)
// ─────────────────────────────────────────────────────────────────────────────

echo "Loading user data for matching...\n";

// Index by normalised email
$usersByEmail  = []; // normEmail → row
$usersByPhone  = []; // normPhone → row
$usersByName   = []; // normName  → row (for name-exact suggestions)
$allUsers      = []; // uid → row

$usersStmt = $pdo->query(
    'SELECT uid, alias, email, phone FROM bs_users'
);
foreach ($usersStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $uid = (int)$u['uid'];
    $allUsers[$uid] = $u;

    $normEmail = strtolower(trim((string)($u['email'] ?? '')));
    if ($normEmail !== '') {
        $usersByEmail[$normEmail] = $u;
    }

    $normPhone = normaliseMobile($u['phone'] ?? '');
    if ($normPhone !== '') {
        $usersByPhone[$normPhone] = $u;
    }

    $normName = normaliseName($u['alias'] ?? '');
    if ($normName !== '') {
        $usersByName[$normName] = $u;
    }
}

// Also load meta phone values into the phone index if table exists
$metaPhones = []; // normPhone → uid
if ($hasUsersMeta) {
    $metaPhoneStmt = $pdo->prepare(
        "SELECT uid, value FROM bs_users_meta WHERE `key` = 'phone'"
    );
    $metaPhoneStmt->execute();
    foreach ($metaPhoneStmt->fetchAll(PDO::FETCH_ASSOC) as $mp) {
        $normPhone = normaliseMobile($mp['value'] ?? '');
        if ($normPhone !== '') {
            $metaPhones[$normPhone] = (int)$mp['uid'];
        }
    }
}

// Load account number meta if table exists
$metaAccountNos = []; // normalised account no → uid
if ($hasUsersMeta) {
    $metaAccStmt = $pdo->prepare(
        "SELECT uid, value FROM bs_users_meta WHERE `key` = 'ssa_account_no'"
    );
    $metaAccStmt->execute();
    foreach ($metaAccStmt->fetchAll(PDO::FETCH_ASSOC) as $ma) {
        $normAcc = normaliseAccountNumber($ma['value'] ?? '');
        if ($normAcc !== '') {
            $metaAccountNos[$normAcc] = (int)$ma['uid'];
        }
    }
}

echo "Loaded " . count($allUsers) . " users.\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Match each row
// ─────────────────────────────────────────────────────────────────────────────

echo "Matching rows...\n";

$results = []; // array of result rows for report + apply

foreach ($rows as $row) {
    $result = [
        'row'           => $row['row_number'],
        'member_name'   => $row['member_name'],
        'account_no'    => $row['account_no_raw'],
        'email'         => $row['email_raw'],
        'mobile'        => $row['mobile_raw'],
        'joined_raw'    => $row['joined_raw'],
        'package_raw'   => $row['package_raw'],
        'plan_key'      => null,
        'plan_id'       => null,
        'joined_date'   => null,
        'matched_uid'   => null,
        'matched_alias' => null,
        'match_method'  => 'unresolved',
        'status'        => 'unresolved',
        'reason'        => null,
        'is_suggestion' => false,
    ];

    // Resolve plan_key
    $normPackage = preg_replace('/\s+/', ' ', strtolower(trim((string)($row['package_raw'] ?? ''))));
    if (isset(PACKAGE_MAP[$normPackage])) {
        $planKey = PACKAGE_MAP[$normPackage];
        if (isset($plansByKey[$planKey])) {
            $result['plan_key'] = $planKey;
            $result['plan_id']  = (int)$plansByKey[$planKey]['id'];
        } else {
            $result['status'] = 'error';
            $result['reason'] = "plan_key '$planKey' not found in ssa_membership_plans — run migration first";
        }
    } else {
        $result['status'] = 'unresolved';
        $result['reason'] = "Unknown package description: '$normPackage'";
    }

    // Parse joined date
    $result['joined_date'] = parseJoinedDate($row['joined_raw']);

    // ── Matching priority 1: email ─────────────────────────────────────────
    $normEmail = strtolower(trim((string)($row['email_raw'] ?? '')));
    if ($normEmail !== '' && isset($usersByEmail[$normEmail])) {
        $u = $usersByEmail[$normEmail];
        $result['matched_uid']   = (int)$u['uid'];
        $result['matched_alias'] = $u['alias'];
        $result['match_method']  = 'email';
        goto matched;
    }

    // ── Matching priority 2: account number ───────────────────────────────
    if ($hasUsersMeta && $row['account_no_raw'] !== null) {
        $normAcc = normaliseAccountNumber($row['account_no_raw']);
        // Skip ambiguous account numbers
        if (!in_array($normAcc, AMBIGUOUS_ACCOUNT_NUMBERS, true) && isset($metaAccountNos[$normAcc])) {
            $uid = $metaAccountNos[$normAcc];
            $u   = $allUsers[$uid] ?? null;
            if ($u !== null) {
                $result['matched_uid']   = $uid;
                $result['matched_alias'] = $u['alias'];
                $result['match_method']  = 'account_no';
                goto matched;
            }
        }
    }

    // ── Matching priority 3: mobile ───────────────────────────────────────
    $normMobile = normaliseMobile($row['mobile_raw'] ?? '');
    if ($normMobile !== '') {
        // Check bs_users.phone
        if (isset($usersByPhone[$normMobile])) {
            $u = $usersByPhone[$normMobile];
            $result['matched_uid']   = (int)$u['uid'];
            $result['matched_alias'] = $u['alias'];
            $result['match_method']  = 'mobile';
            goto matched;
        }
        // Check bs_users_meta phone
        if ($hasUsersMeta && isset($metaPhones[$normMobile])) {
            $uid = $metaPhones[$normMobile];
            $u   = $allUsers[$uid] ?? null;
            if ($u !== null) {
                $result['matched_uid']   = $uid;
                $result['matched_alias'] = $u['alias'];
                $result['match_method']  = 'mobile';
                goto matched;
            }
        }
    }

    // ── Matching priority 4: exact name (suggestion only) ─────────────────
    $normName = normaliseName($row['member_name']);
    if ($normName !== '' && isset($usersByName[$normName])) {
        $u = $usersByName[$normName];
        $result['matched_uid']   = (int)$u['uid'];
        $result['matched_alias'] = $u['alias'];
        $result['match_method']  = 'name_exact';
        $result['is_suggestion'] = true;
        $result['reason']        = 'Name-only match — requires manual confirmation before applying';
        goto matched;
    }

    // ── Matching priority 5: fuzzy name (suggestion only) ─────────────────
    if ($normName !== '') {
        $bestUid        = null;
        $bestAlias      = null;
        $bestSimilarity = 0.0;

        foreach ($usersByName as $candidateName => $candidateUser) {
            $similarity = nameSimilarity($normName, $candidateName);
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestUid        = (int)$candidateUser['uid'];
                $bestAlias      = $candidateUser['alias'];
            }
        }

        if ($bestSimilarity >= 0.8 && $bestUid !== null) {
            $result['matched_uid']   = $bestUid;
            $result['matched_alias'] = $bestAlias;
            $result['match_method']  = 'name_fuzzy_suggestion';
            $result['is_suggestion'] = true;
            $result['reason']        = sprintf(
                'Fuzzy name match (%.0f%% similarity) — requires manual confirmation before applying',
                $bestSimilarity * 100
            );
            goto matched;
        }
    }

    // No match found.
    $result['match_method'] = 'unresolved';
    if ($result['status'] !== 'error') {
        $result['status'] = 'unresolved';
        $result['reason'] = $result['reason'] ?? 'No matching user found via email, account_no, mobile, or name';
    }
    goto done;

    matched:
    // If status hasn't been set to error, mark as resolved (status will be
    // finalised to imported/skipped/conflict during apply).
    if ($result['status'] !== 'error' && !$result['is_suggestion']) {
        $result['status'] = 'resolved';
    }

    done:
    $results[] = $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reconciliation report (stdout only — NEVER written to disk)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 120) . "\n";
echo "RECONCILIATION REPORT\n";
echo str_repeat('─', 120) . "\n";
echo sprintf(
    "%-4s %-30s %-8s %-30s %-15s %-7s %-30s %-26s %-22s %-12s %s\n",
    'Row', 'Member Name', 'Acct', 'Email', 'Mobile', 'UID', 'Alias', 'Plan Key', 'Match Method', 'Status', 'Reason'
);
echo str_repeat('─', 120) . "\n";

$summary = [
    'total'      => 0,
    'resolved'   => 0,
    'suggestion' => 0,
    'unresolved' => 0,
    'error'      => 0,
];

foreach ($results as $r) {
    $summary['total']++;
    if ($r['is_suggestion']) {
        $summary['suggestion']++;
    } elseif ($r['status'] === 'resolved') {
        $summary['resolved']++;
    } elseif ($r['status'] === 'unresolved') {
        $summary['unresolved']++;
    } elseif ($r['status'] === 'error') {
        $summary['error']++;
    }

    $memberName = substr((string)($r['member_name'] ?? '—'), 0, 29);
    $acct       = substr((string)($r['account_no'] ?? ''), 0, 7);
    $email      = substr((string)($r['email'] ?? ''), 0, 29);
    $mobile     = substr((string)($r['mobile'] ?? ''), 0, 14);
    $uid        = $r['matched_uid'] !== null ? (string)$r['matched_uid'] : '—';
    $alias      = substr((string)($r['matched_alias'] ?? '—'), 0, 29);
    $planKey    = substr((string)($r['plan_key'] ?? '—'), 0, 25);
    $matchMethod = substr((string)$r['match_method'], 0, 21);
    $status     = $r['is_suggestion'] ? 'SUGGESTION' : strtoupper($r['status']);
    $reason     = $r['reason'] ?? '';

    echo sprintf(
        "%-4s %-30s %-8s %-30s %-15s %-7s %-30s %-26s %-22s %-12s %s\n",
        $r['row'], $memberName, $acct, $email, $mobile, $uid, $alias, $planKey, $matchMethod, $status, $reason
    );
}

echo str_repeat('─', 120) . "\n";
echo sprintf(
    "TOTALS: %d rows | %d auto-matched | %d suggestions (manual review required) | %d unresolved | %d errors\n",
    $summary['total'],
    $summary['resolved'],
    $summary['suggestion'],
    $summary['unresolved'],
    $summary['error']
);
echo str_repeat('─', 120) . "\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// Dry run: stop here
// ─────────────────────────────────────────────────────────────────────────────

if ($dryRun) {
    echo "[DRY RUN] No changes written. Review the report above and re-run with --apply when ready.\n";
    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// --apply: gate checks
// ─────────────────────────────────────────────────────────────────────────────

// Refuse to proceed if any rows are unresolved (not even a suggestion).
$unresolvedRows = array_filter($results, static fn ($r) => $r['status'] === 'unresolved');
if (!empty($unresolvedRows)) {
    echo "UNRESOLVED ROWS (must be resolved before applying):\n";
    foreach ($unresolvedRows as $r) {
        echo "  Row {$r['row']}: {$r['member_name']} — {$r['reason']}\n";
    }
    fwrite(STDERR, "\nERROR: " . count($unresolvedRows) . " unresolved rows remain. Resolve them first.\n");
    exit(1);
}

// Refuse to proceed if any hard errors (e.g. plan not found).
$errorRows = array_filter($results, static fn ($r) => $r['status'] === 'error');
if (!empty($errorRows)) {
    echo "ERROR ROWS:\n";
    foreach ($errorRows as $r) {
        echo "  Row {$r['row']}: {$r['member_name']} — {$r['reason']}\n";
    }
    fwrite(STDERR, "\nERROR: " . count($errorRows) . " rows have unrecoverable errors. Fix them first.\n");
    exit(1);
}

// Warn about suggestion rows — they will be written to import_rows with status 'unresolved'
// (needing manual confirmation) but will NOT get a membership record.
$suggestionRows = array_filter($results, static fn ($r) => $r['is_suggestion']);
if (!empty($suggestionRows)) {
    echo "NOTE: " . count($suggestionRows) . " suggestion rows will be recorded as 'unresolved' (no membership written).\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// --apply: create batch record
// ─────────────────────────────────────────────────────────────────────────────

$sourceFilename = basename($filePath);

$pdo->beginTransaction();
try {
    $createBatchStmt = $pdo->prepare(
        'INSERT INTO ssa_membership_import_batches
            (source_filename, source_sha256, status, total_rows, started_at, created_by)
         VALUES
            (:filename, :sha256, \'running\', :total, UTC_TIMESTAMP(), :createdBy)'
    );
    $createBatchStmt->execute([
        'filename'  => $sourceFilename,
        'sha256'    => $fileHash,
        'total'     => count($results),
        'createdBy' => get_current_user() ?: 'cli',
    ]);
    $batchId = (int)$pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR: Could not create import batch: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Import batch created: id=$batchId\n\n";

// ─────────────────────────────────────────────────────────────────────────────
// --apply: process each row in its own transaction
// ─────────────────────────────────────────────────────────────────────────────

$counters = [
    'imported'   => 0,
    'skipped'    => 0,
    'conflict'   => 0,
    'unresolved' => 0,
    'error'      => 0,
];

$insertImportRowStmt = $pdo->prepare(
    'INSERT INTO ssa_membership_import_rows
        (batch_id, source_row_number, source_member_name, source_account_number,
         source_email, source_mobile, raw_package_description, normalised_package_description,
         resolved_plan_id, matched_uid, match_method, status, reason)
     VALUES
        (:batchId, :rowNum, :memberName, :accountNo,
         :email, :mobile, :rawPackage, :normPackage,
         :planId, :matchedUid, :matchMethod, :status, :reason)'
);

$insertMembershipStmt = $pdo->prepare(
    "INSERT INTO ssa_user_memberships
        (uid, plan_id, status, source, started_at,
         current_period_starts_at, current_period_ends_at,
         cancellation_notice_deadline_at,
         price_snapshot_pence, currency_snapshot, plan_name_snapshot)
     VALUES
        (:uid, :planId, 'active', 'legacy_excel_import', :startedAt,
         NULL, :periodEndsAt,
         NULL,
         :pricePence, :currency, :planName)"
);

$checkActiveStmt = $pdo->prepare(
    "SELECT id, plan_id FROM ssa_user_memberships
     WHERE uid = :uid AND status = 'active'
     LIMIT 1"
);

foreach ($results as $r) {
    $normPackage = preg_replace('/\s+/', ' ', strtolower(trim((string)($r['package_raw'] ?? ''))));
    $rowStatus   = 'unresolved';
    $reason      = $r['reason'];
    $matchedUid  = $r['matched_uid'];
    $planId      = $r['plan_id'];

    // Handle suggestion rows — record as unresolved, no membership insert.
    if ($r['is_suggestion']) {
        $rowStatus = 'unresolved';
        $reason    = $r['reason'];
        $counters['unresolved']++;

        $pdo->beginTransaction();
        try {
            $insertImportRowStmt->execute([
                'batchId'     => $batchId,
                'rowNum'      => $r['row'],
                'memberName'  => $r['member_name'],
                'accountNo'   => $r['account_no'],
                'email'       => $r['email'],
                'mobile'      => $r['mobile'],
                'rawPackage'  => $r['package_raw'],
                'normPackage' => $normPackage,
                'planId'      => $planId,
                'matchedUid'  => $matchedUid,
                'matchMethod' => $r['match_method'],
                'status'      => $rowStatus,
                'reason'      => $reason,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "  ERROR on row {$r['row']}: " . $e->getMessage() . "\n";
        }
        continue;
    }

    if ($matchedUid === null || $planId === null) {
        // Shouldn't reach here after gate checks, but guard anyway.
        $rowStatus = 'unresolved';
        $counters['unresolved']++;
        $pdo->beginTransaction();
        try {
            $insertImportRowStmt->execute([
                'batchId'     => $batchId,
                'rowNum'      => $r['row'],
                'memberName'  => $r['member_name'],
                'accountNo'   => $r['account_no'],
                'email'       => $r['email'],
                'mobile'      => $r['mobile'],
                'rawPackage'  => $r['package_raw'],
                'normPackage' => $normPackage,
                'planId'      => $planId,
                'matchedUid'  => $matchedUid,
                'matchMethod' => $r['match_method'],
                'status'      => $rowStatus,
                'reason'      => 'uid or plan_id null at apply time',
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
        }
        continue;
    }

    $pdo->beginTransaction();
    try {
        // Check for existing active membership.
        $checkActiveStmt->execute(['uid' => $matchedUid]);
        $existingActive = $checkActiveStmt->fetch(PDO::FETCH_ASSOC);

        if ($existingActive) {
            $existingPlanId = (int)$existingActive['plan_id'];
            if ($existingPlanId === $planId) {
                // Same plan — idempotent skip.
                $rowStatus = 'skipped';
                $reason    = "Active membership with same plan already exists (membership id={$existingActive['id']})";
                $counters['skipped']++;
            } else {
                // Different active membership — conflict, do not overwrite.
                $rowStatus = 'conflict';
                $reason    = "Existing active membership with different plan (plan_id=$existingPlanId) — manual review required";
                $counters['conflict']++;
            }
        } else {
            // No active membership — insert.
            $planRow   = $plansByKey[$r['plan_key']];
            // Append time component; fall back to now if no Joined date in spreadsheet.
            $joinedDate = $r['joined_date'];
            $startedAt  = $joinedDate !== null ? ($joinedDate . ' 00:00:00') : date('Y-m-d H:i:s');
            // current_period_ends_at: special case for PINK_STANDARD_UPFRONT
            $periodEndsAt = ($r['plan_key'] === 'PINK_STANDARD_UPFRONT')
                ? PINK_UPFRONT_END_DATE
                : null;

            $insertMembershipStmt->execute([
                'uid'          => $matchedUid,
                'planId'       => $planId,
                'startedAt'    => $startedAt,
                'periodEndsAt' => $periodEndsAt,
                'pricePence'   => (int)$planRow['monthly_price_pence'],
                'currency'     => (string)$planRow['currency'],
                'planName'     => (string)$planRow['display_name'],
            ]);

            $rowStatus = 'imported';
            $reason    = null;
            $counters['imported']++;
        }

        $insertImportRowStmt->execute([
            'batchId'     => $batchId,
            'rowNum'      => $r['row'],
            'memberName'  => $r['member_name'],
            'accountNo'   => $r['account_no'],
            'email'       => $r['email'],
            'mobile'      => $r['mobile'],
            'rawPackage'  => $r['package_raw'],
            'normPackage' => $normPackage,
            'planId'      => $planId,
            'matchedUid'  => $matchedUid,
            'matchMethod' => $r['match_method'],
            'status'      => $rowStatus,
            'reason'      => $reason,
        ]);

        $pdo->commit();
        echo sprintf(
            "Row %3d: %-10s uid=%-6s plan=%-25s method=%-12s %s\n",
            $r['row'],
            strtoupper($rowStatus),
            $matchedUid,
            $r['plan_key'],
            $r['match_method'],
            $reason ?? ''
        );

    } catch (Throwable $e) {
        $pdo->rollBack();
        $counters['error']++;
        echo "  ERROR on row {$r['row']}: " . $e->getMessage() . "\n";

        // Record error in import_rows outside the failed transaction.
        try {
            $pdo->beginTransaction();
            $insertImportRowStmt->execute([
                'batchId'     => $batchId,
                'rowNum'      => $r['row'],
                'memberName'  => $r['member_name'],
                'accountNo'   => $r['account_no'],
                'email'       => $r['email'],
                'mobile'      => $r['mobile'],
                'rawPackage'  => $r['package_raw'],
                'normPackage' => $normPackage,
                'planId'      => $planId,
                'matchedUid'  => $matchedUid,
                'matchMethod' => $r['match_method'],
                'status'      => 'error',
                'reason'      => $e->getMessage(),
            ]);
            $pdo->commit();
        } catch (Throwable) {
            $pdo->rollBack();
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Update batch record with final counts
// ─────────────────────────────────────────────────────────────────────────────

$finalStatus = ($counters['error'] > 0) ? 'failed' : 'completed';

try {
    $pdo->prepare(
        'UPDATE ssa_membership_import_batches
         SET status          = :status,
             matched_rows    = :matched,
             imported_rows   = :imported,
             skipped_rows    = :skipped,
             conflict_rows   = :conflict,
             unresolved_rows = :unresolved,
             completed_at    = UTC_TIMESTAMP()
         WHERE id = :id'
    )->execute([
        'status'     => $finalStatus,
        'matched'    => $counters['imported'] + $counters['skipped'],
        'imported'   => $counters['imported'],
        'skipped'    => $counters['skipped'],
        'conflict'   => $counters['conflict'],
        'unresolved' => $counters['unresolved'] + $counters['error'],
        'id'         => $batchId,
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "WARNING: Could not update batch record: " . $e->getMessage() . "\n");
}

// ─────────────────────────────────────────────────────────────────────────────
// Final summary
// ─────────────────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 60) . "\n";
echo "IMPORT COMPLETE (batch id=$batchId)\n";
echo str_repeat('─', 60) . "\n";
echo sprintf("  Total rows:   %d\n", count($results));
echo sprintf("  Imported:     %d\n", $counters['imported']);
echo sprintf("  Skipped:      %d  (already on same plan)\n", $counters['skipped']);
echo sprintf("  Conflict:     %d  (different active plan — review needed)\n", $counters['conflict']);
echo sprintf("  Unresolved:   %d  (name suggestions or no match — review needed)\n", $counters['unresolved']);
echo sprintf("  Errors:       %d\n", $counters['error']);
echo str_repeat('─', 60) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// Rollback SQL (printed to stdout for reference — NOT executed)
// ─────────────────────────────────────────────────────────────────────────────

if ($counters['imported'] > 0) {
    echo "\nROLLBACK SQL (run manually if needed):\n";
    echo str_repeat('─', 60) . "\n";

    // Collect imported plan IDs for this batch
    $importedPlansStmt = $pdo->prepare(
        "SELECT DISTINCT resolved_plan_id
         FROM ssa_membership_import_rows
         WHERE batch_id = :batchId AND status = 'imported'"
    );
    $importedPlansStmt->execute(['batchId' => $batchId]);
    $importedPlanIds = $importedPlansStmt->fetchAll(PDO::FETCH_COLUMN);

    $importedUidsStmt = $pdo->prepare(
        "SELECT DISTINCT matched_uid
         FROM ssa_membership_import_rows
         WHERE batch_id = :batchId AND status = 'imported' AND matched_uid IS NOT NULL"
    );
    $importedUidsStmt->execute(['batchId' => $batchId]);
    $importedUids = $importedUidsStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($importedPlanIds) && !empty($importedUids)) {
        $planIdList = implode(', ', array_map('intval', $importedPlanIds));
        $uidList    = implode(', ', array_map('intval', $importedUids));
        echo "-- Cancel memberships imported in batch $batchId:\n";
        echo "UPDATE ssa_user_memberships\n";
        echo "   SET status = 'cancelled',\n";
        echo "       cancelled_at = UTC_TIMESTAMP()\n";
        echo " WHERE source = 'legacy_excel_import'\n";
        echo "   AND plan_id IN ($planIdList)\n";
        echo "   AND uid IN ($uidList)\n";
        echo "   AND status = 'active';\n\n";
        echo "-- Mark batch as rolled back:\n";
        echo "UPDATE ssa_membership_import_batches SET status = 'rolled_back' WHERE id = $batchId;\n";
    }
    echo str_repeat('─', 60) . "\n";
}

exit($counters['error'] > 0 ? 1 : 0);
