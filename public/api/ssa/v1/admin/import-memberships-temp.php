<?php
declare(strict_types=1);
// TEMPORARY — remove before production merge
define('IMP_SECRET', 'f7c3a9e2d1b845670e4f1c8a3d2b96e5');
if (($_GET['t'] ?? '') !== IMP_SECRET) { http_response_code(404); exit; }

$mode = $_GET['mode'] ?? 'dry';
if (!in_array($mode, ['dry', 'apply'], true)) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'pass ?mode=dry or ?mode=apply']);
    exit;
}

require_once __DIR__ . '/../_db.php';
header('Content-Type: application/json; charset=utf-8');

// ─── XLSX parser (stdlib only: ZipArchive + SimpleXML) ─────────────────────

function parseXlsxSafe(string $tmpPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new RuntimeException('Cannot open uploaded file as ZIP/XLSX');
    }

    // Shared strings
    $sharedStrings = [];
    $ssData = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssData !== false) {
        $ssXml = new SimpleXMLElement($ssData);
        $ssXml->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($ssXml->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main') as $si) {
            $text = '';
            // Collect all <t> text nodes (handles rich text <r><t>...)
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) {
                $text .= (string)$t;
            }
            $sharedStrings[] = $text;
        }
    }

    // Find first sheet
    $sheetName = 'xl/worksheets/sheet1.xml';
    $sheetData = $zip->getFromName($sheetName);
    if ($sheetData === false) {
        // Try sheet list
        $wbData = $zip->getFromName('xl/workbook.xml');
        if ($wbData !== false) {
            $wb = new SimpleXMLElement($wbData);
            foreach ($wb->xpath('//*[local-name()="sheet"]') ?: [] as $s) {
                $rId = (string)($s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                // find in rels
                $relsData = $zip->getFromName('xl/_rels/workbook.xml.rels');
                if ($relsData !== false) {
                    $rels = new SimpleXMLElement($relsData);
                    foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $rel) {
                        if ((string)$rel['Id'] === $rId) {
                            $sheetName = 'xl/' . ltrim((string)$rel['Target'], '/');
                            break 2;
                        }
                    }
                }
                break;
            }
        }
        $sheetData = $zip->getFromName($sheetName);
        if ($sheetData === false) {
            throw new RuntimeException('Cannot find worksheet in uploaded file');
        }
    }
    $zip->close();

    $sheet = new SimpleXMLElement($sheetData);
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    $rawRows = [];
    foreach ($sheet->xpath('//*[local-name()="row"]') ?: [] as $rowEl) {
        $cells = [];
        foreach ($rowEl->xpath('*[local-name()="c"]') ?: [] as $c) {
            $ref  = (string)($c['r'] ?? '');
            $type = (string)($c['t'] ?? '');
            $vEl  = $c->xpath('*[local-name()="v"]');
            $rawVal = ($vEl && isset($vEl[0])) ? (string)$vEl[0] : null;

            if ($rawVal === null) { $cells[$ref] = ''; continue; }

            if ($type === 's') {
                $cells[$ref] = $sharedStrings[(int)$rawVal] ?? '';
            } elseif ($type === 'inlineStr') {
                $tEl = $c->xpath('*[local-name()="is"]/*[local-name()="t"]');
                $cells[$ref] = $tEl ? (string)$tEl[0] : '';
            } else {
                $cells[$ref] = $rawVal; // number or date serial
            }
        }
        if ($cells) $rawRows[] = $cells;
    }

    return $rawRows;
}

function colLetterFromRef(string $ref): string
{
    return (string)preg_replace('/\d+/', '', $ref);
}

// ─── Constants ─────────────────────────────────────────────────────────────

const ALLOWED_HEADERS  = ['member','account no.','package','joined','email','mobile'];
const FORBIDDEN_HEADERS = ['pin','locker','cue-store','cue store','whatsapp'];

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

const AMBIGUOUS_ACCOUNT_NUMBERS = ['0032'];
const PINK_UPFRONT_END_DATE = '2026-08-31 23:59:59';

// ─── Helpers ────────────────────────────────────────────────────────────────

function normPkg(string $raw): string
{
    return preg_replace('/\s+/', ' ', strtolower(trim($raw)));
}

function normaliseName(?string $raw): string
{
    if ($raw === null || trim($raw) === '') return '';
    $name = trim($raw);
    if (str_contains($name, ',')) {
        [$surname, $firstname] = explode(',', $name, 2);
        $name = trim($firstname) . ' ' . trim($surname);
    }
    return strtolower(preg_replace('/\s+/', ' ', trim($name)));
}

function normaliseMobile(?string $raw): string
{
    if ($raw === null || trim($raw) === '') return '';
    $stripped = preg_replace('/[^\d+]/', '', trim($raw));
    $stripped = ltrim($stripped, '+');
    if (str_starts_with($stripped, '07') && strlen($stripped) === 11) {
        $stripped = '44' . substr($stripped, 1);
    }
    if (!preg_match('/^447\d{9}$/', $stripped)) return '';
    return $stripped;
}

function normaliseAccountNumber(?string $raw): string
{
    if ($raw === null || trim($raw) === '') return '';
    $stripped = ltrim(trim($raw), '0');
    if ($stripped === '') return '0000';
    return str_pad($stripped, 4, '0', STR_PAD_LEFT);
}

function parseJoinedDate(mixed $raw): ?string
{
    if ($raw === null || $raw === '') return null;
    // Excel serial date (float/int)
    if (is_numeric($raw)) {
        $serial = (float)$raw;
        // Excel epoch: 1900-01-01 = serial 1 (with leap-year bug: 1900-02-29 = 60)
        $days = (int)$serial;
        if ($days >= 60) $days--; // correct for Excel's phantom Feb 29 1900
        $ts = mktime(0,0,0,1,1,1900) + ($days - 1) * 86400;
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
    $str = trim((string)$raw);
    if ($str === '') return null;
    foreach (['d/m/Y','Y-m-d','d-m-Y','d/m/y','j/n/Y','j/n/y'] as $fmt) {
        $dt = DateTimeImmutable::createFromFormat($fmt, $str);
        if ($dt !== false) return $dt->format('Y-m-d');
    }
    $ts = strtotime($str);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function nameSimilarity(string $a, string $b): float
{
    if ($a === '' || $b === '') return 0.0;
    $distance = levenshtein($a, $b);
    $maxLen = max(strlen($a), strlen($b));
    return 1.0 - ($distance / $maxLen);
}

// ─── Main ───────────────────────────────────────────────────────────────────

$log = [];
$dryRun = ($mode === 'dry');

try {
    // Read uploaded file
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['file']['error'] ?? 'no file';
        echo json_encode(['error' => "Upload error: $uploadErr"]);
        exit;
    }

    $tmpPath = $_FILES['file']['tmp_name'];
    $rawRows = parseXlsxSafe($tmpPath);

    if (empty($rawRows)) {
        echo json_encode(['error' => 'Spreadsheet appears empty']);
        exit;
    }

    // Map headers from row 1
    $headerMap = [];
    foreach ($rawRows[0] as $ref => $val) {
        $colLetter = colLetterFromRef($ref);
        $norm = strtolower(trim((string)$val));
        $forbidden = false;
        foreach (FORBIDDEN_HEADERS as $f) {
            if ($norm === $f || str_contains($norm, $f)) { $forbidden = true; break; }
        }
        if ($forbidden) { $log[] = "SECURITY: forbidden column ignored at col $colLetter: '$val'"; continue; }
        if (in_array($norm, ALLOWED_HEADERS, true)) {
            $headerMap[$norm] = $colLetter;
        }
    }

    $required = ['member','account no.','package','joined','email','mobile'];
    foreach ($required as $req) {
        if (!isset($headerMap[$req])) {
            echo json_encode(['error' => "Required column '$req' not found. Found: " . implode(', ', array_keys($headerMap)), 'log' => $log]);
            exit;
        }
    }

    // Parse data rows
    $rows = [];
    foreach (array_slice($rawRows, 1) as $cells) {
        $get = function(string $header) use ($cells, $headerMap): string {
            $col = $headerMap[$header];
            // Find cell whose ref starts with this col letter
            foreach ($cells as $ref => $val) {
                if (colLetterFromRef($ref) === $col) return trim((string)$val);
            }
            return '';
        };

        $memberName  = $get('member');
        $accountNoRaw= $get('account no.');
        $packageRaw  = $get('package');
        $joinedRaw   = null;
        $emailRaw    = $get('email');
        $mobileRaw   = $get('mobile');

        // Joined — need raw value
        $jCol = $headerMap['joined'];
        foreach ($cells as $ref => $val) {
            if (colLetterFromRef($ref) === $jCol) { $joinedRaw = $val; break; }
        }

        if ($memberName === '' && $accountNoRaw === '' && $packageRaw === '') continue;

        $rows[] = [
            'member_name'   => $memberName ?: null,
            'account_no_raw'=> $accountNoRaw ?: null,
            'package_raw'   => $packageRaw ?: null,
            'joined_raw'    => $joinedRaw,
            'email_raw'     => $emailRaw ?: null,
            'mobile_raw'    => $mobileRaw ?: null,
        ];
    }

    $log[] = "Rows read: " . count($rows);

    // Validate totals
    $actualCounts = [];
    foreach ($rows as $row) {
        $k = normPkg((string)($row['package_raw'] ?? ''));
        $actualCounts[$k] = ($actualCounts[$k] ?? 0) + 1;
    }

    $validationErrors = [];
    foreach (EXPECTED_COUNTS as $desc => $expected) {
        $actual = $actualCounts[$desc] ?? 0;
        if ($actual !== $expected) {
            $validationErrors[] = "MISMATCH: '$desc' expected=$expected actual=$actual";
        }
    }
    $unexpected = array_diff_key($actualCounts, EXPECTED_COUNTS);
    foreach ($unexpected as $k => $cnt) {
        if ($k !== '') $validationErrors[] = "UNEXPECTED: '$k' ($cnt rows)";
    }
    if (count($rows) !== EXPECTED_TOTAL) {
        $validationErrors[] = "TOTAL: expected " . EXPECTED_TOTAL . " got " . count($rows);
    }

    if (!empty($validationErrors)) {
        echo json_encode(['error' => 'Validation failed', 'validation_errors' => $validationErrors, 'log' => $log]);
        exit;
    }
    $log[] = "Validation: OK (" . EXPECTED_TOTAL . " rows)";

    // Connect to DB
    $pdo = ssaApiCreatePdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SHA-256 of file
    $fileHash = hash_file('sha256', $tmpPath);
    $log[] = "SHA-256: $fileHash";

    // Check duplicate
    if (!$dryRun) {
        $dupStmt = $pdo->prepare('SELECT id, status FROM ssa_membership_import_batches WHERE source_sha256=:h LIMIT 1');
        $dupStmt->execute(['h' => $fileHash]);
        $dup = $dupStmt->fetch(PDO::FETCH_ASSOC);
        if ($dup) {
            echo json_encode(['error' => 'File already imported', 'batch_id' => $dup['id'], 'status' => $dup['status'], 'log' => $log]);
            exit;
        }
    }

    // Load plans
    $plansByKey = [];
    foreach ($pdo->query('SELECT id,plan_key,display_name,monthly_price_pence,currency FROM ssa_membership_plans')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $plansByKey[$r['plan_key']] = $r;
    }
    $log[] = "Plans loaded: " . count($plansByKey);

    // Load users
    $usersByEmail = []; $usersByPhone = []; $usersByName = []; $allUsers = [];
    foreach ($pdo->query('SELECT uid,alias,email,phone FROM bs_users')->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $uid = (int)$u['uid'];
        $allUsers[$uid] = $u;
        $ne = strtolower(trim((string)($u['email'] ?? '')));
        if ($ne !== '') $usersByEmail[$ne] = $u;
        $np = normaliseMobile($u['phone'] ?? '');
        if ($np !== '') $usersByPhone[$np] = $u;
        $nn = normaliseName($u['alias'] ?? '');
        if ($nn !== '') $usersByName[$nn] = $u;
    }
    $log[] = "Users loaded: " . count($allUsers);

    // Check bs_users_meta
    $metaAccountNos = []; $metaPhones = [];
    $hasMeta = (bool)(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='bs_users_meta'")->fetchColumn();
    if ($hasMeta) {
        foreach ($pdo->query("SELECT uid,value FROM bs_users_meta WHERE `key`='ssa_account_no'")->fetchAll(PDO::FETCH_ASSOC) as $ma) {
            $na = normaliseAccountNumber($ma['value'] ?? '');
            if ($na !== '') $metaAccountNos[$na] = (int)$ma['uid'];
        }
        foreach ($pdo->query("SELECT uid,value FROM bs_users_meta WHERE `key`='phone'")->fetchAll(PDO::FETCH_ASSOC) as $mp) {
            $np = normaliseMobile($mp['value'] ?? '');
            if ($np !== '') $metaPhones[$np] = (int)$mp['uid'];
        }
    }

    // Match rows
    $results = [];
    foreach ($rows as $row) {
        $r = [
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

        $normPkg = normPkg((string)($row['package_raw'] ?? ''));
        if (isset(PACKAGE_MAP[$normPkg])) {
            $pk = PACKAGE_MAP[$normPkg];
            if (isset($plansByKey[$pk])) {
                $r['plan_key'] = $pk;
                $r['plan_id']  = (int)$plansByKey[$pk]['id'];
            } else {
                $r['status'] = 'error';
                $r['reason'] = "plan_key '$pk' not in DB — run migration first";
            }
        } else {
            $r['status'] = 'unresolved';
            $r['reason'] = "Unknown package: '$normPkg'";
        }

        $r['joined_date'] = parseJoinedDate($row['joined_raw']);

        // Priority 1: email
        $ne = strtolower(trim((string)($row['email_raw'] ?? '')));
        if ($ne !== '' && isset($usersByEmail[$ne])) {
            $u = $usersByEmail[$ne];
            $r['matched_uid'] = (int)$u['uid']; $r['matched_alias'] = $u['alias']; $r['match_method'] = 'email';
            goto matched;
        }
        // Priority 2: account_no
        if ($hasMeta && $row['account_no_raw'] !== null) {
            $na = normaliseAccountNumber($row['account_no_raw']);
            if (!in_array($na, AMBIGUOUS_ACCOUNT_NUMBERS, true) && isset($metaAccountNos[$na])) {
                $uid = $metaAccountNos[$na]; $u = $allUsers[$uid] ?? null;
                if ($u) { $r['matched_uid'] = $uid; $r['matched_alias'] = $u['alias']; $r['match_method'] = 'account_no'; goto matched; }
            }
        }
        // Priority 3: mobile
        $nm = normaliseMobile($row['mobile_raw'] ?? '');
        if ($nm !== '') {
            if (isset($usersByPhone[$nm])) {
                $u = $usersByPhone[$nm]; $r['matched_uid'] = (int)$u['uid']; $r['matched_alias'] = $u['alias']; $r['match_method'] = 'mobile'; goto matched;
            }
            if ($hasMeta && isset($metaPhones[$nm])) {
                $uid = $metaPhones[$nm]; $u = $allUsers[$uid] ?? null;
                if ($u) { $r['matched_uid'] = $uid; $r['matched_alias'] = $u['alias']; $r['match_method'] = 'mobile'; goto matched; }
            }
        }
        // Priority 4: name exact (suggestion)
        $nn = normaliseName($row['member_name']);
        if ($nn !== '' && isset($usersByName[$nn])) {
            $u = $usersByName[$nn]; $r['matched_uid'] = (int)$u['uid']; $r['matched_alias'] = $u['alias'];
            $r['match_method'] = 'name_exact'; $r['is_suggestion'] = true;
            $r['reason'] = 'Name-only match — manual confirmation required'; goto matched;
        }
        // Priority 5: fuzzy name (suggestion)
        if ($nn !== '') {
            $bestUid = null; $bestAlias = null; $bestSim = 0.0;
            foreach ($usersByName as $cn => $cu) {
                $sim = nameSimilarity($nn, $cn);
                if ($sim > $bestSim) { $bestSim = $sim; $bestUid = (int)$cu['uid']; $bestAlias = $cu['alias']; }
            }
            if ($bestSim >= 0.8 && $bestUid !== null) {
                $r['matched_uid'] = $bestUid; $r['matched_alias'] = $bestAlias;
                $r['match_method'] = 'name_fuzzy_suggestion'; $r['is_suggestion'] = true;
                $r['reason'] = sprintf('Fuzzy name match (%.0f%%) — manual confirmation required', $bestSim * 100);
                goto matched;
            }
        }
        goto done;

        matched:
        if ($r['status'] !== 'error' && !$r['is_suggestion']) $r['status'] = 'resolved';

        done:
        $results[] = $r;
    }

    // Summary counts
    $summary = ['total'=>0,'resolved'=>0,'suggestion'=>0,'unresolved'=>0,'error'=>0];
    foreach ($results as $r) {
        $summary['total']++;
        if ($r['is_suggestion']) $summary['suggestion']++;
        elseif ($r['status'] === 'resolved') $summary['resolved']++;
        elseif ($r['status'] === 'error') $summary['error']++;
        else $summary['unresolved']++;
    }

    if ($dryRun) {
        echo json_encode([
            'dry_run' => true,
            'status'  => 'ok',
            'summary' => $summary,
            'log'     => $log,
            'rows'    => array_map(fn($r) => [
                'name'         => $r['member_name'],
                'plan_key'     => $r['plan_key'],
                'joined'       => $r['joined_date'],
                'matched_uid'  => $r['matched_uid'],
                'alias'        => $r['matched_alias'],
                'match_method' => $r['match_method'],
                'status'       => $r['is_suggestion'] ? 'SUGGESTION' : strtoupper($r['status']),
                'reason'       => $r['reason'],
            ], $results),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ── APPLY ──────────────────────────────────────────────────────────────

    // Gate: no unresolved (pass ?confirm_suggestions=1&skip_unresolved=1 to override)
    $confirmSuggestions = ($_GET['confirm_suggestions'] ?? '') === '1';
    $skipUnresolved     = ($_GET['skip_unresolved'] ?? '') === '1';
    $unresolved = array_filter($results, fn($r) => $r['status'] === 'unresolved' && !($confirmSuggestions && $r['is_suggestion']) && !($skipUnresolved && !$r['is_suggestion'] && $r['matched_uid'] === null));
    if (!empty($unresolved)) {
        echo json_encode(['error' => count($unresolved) . ' unresolved rows', 'rows' => array_values($unresolved), 'log' => $log]);
        exit;
    }
    // Promote confirmed suggestions to resolved so they get imported
    if ($confirmSuggestions) {
        foreach ($results as &$r) {
            if ($r['is_suggestion']) { $r['is_suggestion'] = false; $r['status'] = 'resolved'; }
        }
        unset($r);
    }
    $errors = array_filter($results, fn($r) => $r['status'] === 'error');
    if (!empty($errors)) {
        echo json_encode(['error' => count($errors) . ' error rows', 'rows' => array_values($errors), 'log' => $log]);
        exit;
    }

    // Create batch
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO ssa_membership_import_batches (source_filename,source_sha256,status,total_rows,started_at,created_by) VALUES ('SSA Memberships.xlsx',:sha,'running',:total,UTC_TIMESTAMP(),'web_import')")
        ->execute(['sha' => $fileHash, 'total' => count($results)]);
    $batchId = (int)$pdo->lastInsertId();
    $pdo->commit();
    $log[] = "Batch created: id=$batchId";

    $counters = ['imported'=>0,'skipped'=>0,'conflict'=>0,'unresolved'=>0,'error'=>0];
    $rowResults = [];

    $insRow = $pdo->prepare('INSERT INTO ssa_membership_import_rows (batch_id,source_row_number,source_member_name,source_account_number,source_email,source_mobile,raw_package_description,normalised_package_description,resolved_plan_id,matched_uid,match_method,status,reason) VALUES (:bid,:rn,:mn,:an,:em,:mo,:rp,:np,:pi,:mu,:mm,:st,:rs)');

    $insMem = $pdo->prepare("INSERT INTO ssa_user_memberships (uid,plan_id,status,source,started_at,current_period_starts_at,current_period_ends_at,cancellation_notice_deadline_at,price_snapshot_pence,currency_snapshot,plan_name_snapshot) VALUES (:uid,:planId,'active','legacy_excel_import',:startedAt,NULL,:periodEndsAt,NULL,:pricePence,:currency,:planName)");

    $checkActive = $pdo->prepare("SELECT id,plan_id FROM ssa_user_memberships WHERE uid=:uid AND status='active' LIMIT 1");

    $rowNum = 0;
    foreach ($results as $r) {
        $rowNum++;
        $normPkgStr = normPkg((string)($r['package_raw'] ?? ''));
        $rowStatus = 'unresolved';
        $reason    = $r['reason'];

        if ($r['is_suggestion'] || $r['matched_uid'] === null || $r['plan_id'] === null) {
            $counters['unresolved']++;
            $pdo->beginTransaction();
            try {
                $insRow->execute(['bid'=>$batchId,'rn'=>$rowNum,'mn'=>$r['member_name'],'an'=>$r['account_no'],'em'=>$r['email'],'mo'=>$r['mobile'],'rp'=>$r['package_raw'],'np'=>$normPkgStr,'pi'=>$r['plan_id'],'mu'=>$r['matched_uid'],'mm'=>$r['match_method'],'st'=>'unresolved','rs'=>$reason]);
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); }
            $rowResults[] = ['row'=>$rowNum,'name'=>$r['member_name'],'status'=>'unresolved','reason'=>$reason];
            continue;
        }

        $pdo->beginTransaction();
        try {
            $checkActive->execute(['uid'=>$r['matched_uid']]);
            $existing = $checkActive->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ((int)$existing['plan_id'] === $r['plan_id']) {
                    $rowStatus = 'skipped'; $reason = 'Same active plan already exists'; $counters['skipped']++;
                } else {
                    $rowStatus = 'conflict'; $reason = "Active membership with different plan_id={$existing['plan_id']}"; $counters['conflict']++;
                }
            } else {
                $planRow = $plansByKey[$r['plan_key']];
                $joinedDate = $r['joined_date'];
                $startedAt  = $joinedDate !== null ? ($joinedDate . ' 00:00:00') : date('Y-m-d H:i:s');
                $periodEndsAt = ($r['plan_key'] === 'PINK_STANDARD_UPFRONT') ? PINK_UPFRONT_END_DATE : null;
                $insMem->execute(['uid'=>$r['matched_uid'],'planId'=>$r['plan_id'],'startedAt'=>$startedAt,'periodEndsAt'=>$periodEndsAt,'pricePence'=>(int)$planRow['monthly_price_pence'],'currency'=>(string)$planRow['currency'],'planName'=>(string)$planRow['display_name']]);
                $rowStatus = 'imported'; $reason = null; $counters['imported']++;
            }

            $insRow->execute(['bid'=>$batchId,'rn'=>$rowNum,'mn'=>$r['member_name'],'an'=>$r['account_no'],'em'=>$r['email'],'mo'=>$r['mobile'],'rp'=>$r['package_raw'],'np'=>$normPkgStr,'pi'=>$r['plan_id'],'mu'=>$r['matched_uid'],'mm'=>$r['match_method'],'st'=>$rowStatus,'rs'=>$reason]);
            $pdo->commit();
            $rowResults[] = ['row'=>$rowNum,'name'=>$r['member_name'],'uid'=>$r['matched_uid'],'plan_key'=>$r['plan_key'],'match_method'=>$r['match_method'],'status'=>$rowStatus,'reason'=>$reason];

        } catch (Throwable $e) {
            $pdo->rollBack();
            $counters['error']++;
            try {
                $pdo->beginTransaction();
                $insRow->execute(['bid'=>$batchId,'rn'=>$rowNum,'mn'=>$r['member_name'],'an'=>$r['account_no'],'em'=>$r['email'],'mo'=>$r['mobile'],'rp'=>$r['package_raw'],'np'=>$normPkgStr,'pi'=>$r['plan_id'],'mu'=>$r['matched_uid'],'mm'=>$r['match_method'],'st'=>'error','rs'=>$e->getMessage()]);
                $pdo->commit();
            } catch (Throwable) { $pdo->rollBack(); }
            $rowResults[] = ['row'=>$rowNum,'name'=>$r['member_name'],'status'=>'error','reason'=>$e->getMessage()];
        }
    }

    $finalStatus = $counters['error'] > 0 ? 'failed' : 'completed';
    $pdo->prepare('UPDATE ssa_membership_import_batches SET status=:s,matched_rows=:mr,imported_rows=:ir,skipped_rows=:sr,conflict_rows=:cr,unresolved_rows=:ur,completed_at=UTC_TIMESTAMP() WHERE id=:id')
        ->execute(['s'=>$finalStatus,'mr'=>$counters['imported']+$counters['skipped'],'ir'=>$counters['imported'],'sr'=>$counters['skipped'],'cr'=>$counters['conflict'],'ur'=>$counters['unresolved']+$counters['error'],'id'=>$batchId]);

    echo json_encode([
        'dry_run'  => false,
        'status'   => $finalStatus,
        'batch_id' => $batchId,
        'counters' => $counters,
        'log'      => $log,
        'rows'     => $rowResults,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    echo json_encode(['error' => $e->getMessage(), 'log' => $log ?? []]);
}
