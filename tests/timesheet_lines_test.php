<?php
/**
 * Regressie: $select dekt filter + normalize, en een lege Urenstaatregels-week
 * (HTTP 400 "geen urenstaatregels") laat het datumbereik niet stuklopen.
 * Run: php tests/timesheet_lines_test.php
 */

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$bcBehavior = static function (string $url): array {
    return [];
};
$GLOBALS['SESHAT_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls, &$bcBehavior): array {
    unset($auth, $ttl);
    $calls[] = $url;
    return $bcBehavior($url);
};

require dirname(__DIR__) . '/web/timesheet_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' expected=' . json_encode($expected) . ' actual=' . json_encode($actual));
    }
}

function assert_true(bool $value, string $message): void
{
    if (!$value) {
        fail($message);
    }
}

/**
 * @return array{0:int,1:string}|null
 */
function function_body_span(string $source, string $name): ?array
{
    $needle = 'function ' . $name;
    $start = strpos($source, $needle);
    if ($start === false) {
        return null;
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return null;
    }
    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === '{') {
            $depth++;
        } elseif ($char === '}') {
            $depth--;
            if ($depth === 0) {
                return [$brace, substr($source, $brace, $i - $brace + 1)];
            }
        }
    }
    return null;
}

function lines_source(): string
{
    $source = file_get_contents(dirname(__DIR__) . '/web/timesheet_data.php');
    if (!is_string($source) || $source === '') {
        fail('timesheet_data.php niet leesbaar');
    }
    return $source;
}

/**
 * Velden die het $filter en seshat_normalize_line_row echt lezen.
 *
 * @return list<string>
 */
function fields_used_by_filter_and_normalize(): array
{
    $source = lines_source();
    $fields = [];

    $normalize = function_body_span($source, 'seshat_normalize_line_row');
    if ($normalize === null) {
        fail('seshat_normalize_line_row niet gevonden');
    }
    if (preg_match_all("/\\\$row\\['([A-Za-z0-9_]+)'\\]/", $normalize[1], $matches) !== false) {
        foreach ($matches[1] as $field) {
            $fields[$field] = $field;
        }
    }
    if (preg_match("/\\\$row\\[\\s*'Field'\\s*\\.\\s*\\\$day\\s*\\]/", $normalize[1]) === 1) {
        for ($day = 1; $day <= 7; $day++) {
            $fields['Field' . $day] = 'Field' . $day;
        }
    }

    foreach (['seshat_approved_status_filter', 'seshat_fetch_week_lines_from_bc'] as $function) {
        $body = function_body_span($source, $function);
        if ($body === null) {
            fail($function . ' niet gevonden');
        }
        if (preg_match_all('/\b([A-Z][A-Za-z0-9_]*)\s+(?:eq|ne|gt|ge|lt|le)\b/', $body[1], $matches) !== false) {
            foreach ($matches[1] as $field) {
                $fields[$field] = $field;
            }
        }
    }

    $list = array_values($fields);
    sort($list, SORT_STRING);
    return $list;
}

function select_fields(): array
{
    $fields = [];
    foreach (explode(',', SESHAT_LINES_SELECT) as $field) {
        $field = trim($field);
        if ($field !== '') {
            $fields[$field] = $field;
        }
    }
    $list = array_values($fields);
    sort($list, SORT_STRING);
    return $list;
}

function query_from_url(string $url): array
{
    $parts = parse_url($url);
    $query = [];
    if (is_array($parts) && isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }
    return $query;
}

function reset_bc(callable $behavior): void
{
    global $calls, $bcBehavior;
    $calls = [];
    $bcBehavior = $behavior;
    odata_mimir_circuit_reset();
    $GLOBALS['demeter_company_environment_map'] = [
        'Hunter van Twist' => 'Production',
    ];
}

function delete_company_cache(string $company): void
{
    $dir = dirname(__DIR__) . '/web/cache/seshat/' . seshat_company_slug($company);
    if (is_dir($dir)) {
        seshat_delete_directory($dir);
    }
}

$used = fields_used_by_filter_and_normalize();
$selected = select_fields();
if ($used === []) {
    fail('geen velden uit filter/normalize gehaald');
}
foreach (['Status', 'Header_Starting_Date', 'Line_No', 'Header_Ending_Date', 'Type', 'Description', 'Job_Task_No', 'Field1', 'Field7', 'Total_Quantity'] as $required) {
    if (!in_array($required, $used, true)) {
        fail('bronscan mist veld ' . $required . ': ' . json_encode($used));
    }
}
$missing = array_values(array_diff($used, $selected));
if ($missing !== []) {
    fail('SESHAT_LINES_SELECT mist velden uit filter/normalize: ' . implode(',', $missing));
}

$filter = seshat_approved_status_filter();
if (strpos($filter, "Status eq 'Approved'") === false || strpos($filter, "Status eq 'Goedgekeurd'") === false) {
    fail('goedgekeurd-filter is weg: ' . $filter);
}

$emptyRow = seshat_normalize_line_row([]);
assert_same(0, $emptyRow['line_no'], 'ontbrekende Line_No');
assert_same('', $emptyRow['week_end'], 'ontbrekende Header_Ending_Date');
assert_same('', $emptyRow['type'], 'ontbrekend Type');
assert_same('', $emptyRow['status'], 'ontbrekende Status');
assert_same('', $emptyRow['description'], 'ontbrekende Description');
assert_same('', $emptyRow['job_task_no'], 'ontbrekend Job_Task_No');
assert_same('', $emptyRow['work_type_code'], 'ontbrekende Work_Type_Code');
assert_same(0.0, $emptyRow['total_hours'], 'ontbrekende Total_Quantity');
assert_same([1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0, 7 => 0.0], $emptyRow['hours'], 'ontbrekende Field1-7');

$partial = seshat_normalize_line_row([
    'Time_Sheet_No' => 'TS-1',
    'Header_Starting_Date' => '2026-09-07',
    'Header_Resource_No' => 'R1',
    'Work_Type_Code' => 'dir',
    'Field1' => 4,
    'Total_Quantity' => 4,
]);
assert_same('TS-1', $partial['time_sheet_no'], 'time_sheet_no');
assert_same('2026-09-07', $partial['week_start'], 'week_start');
assert_same('DIR', $partial['work_type_code'], 'work_type_code');
assert_same(4.0, $partial['hours'][1], 'Field1');
assert_same(0, $partial['line_no'], 'optionele Line_No blijft 0');
assert_same('', $partial['status'], 'optionele Status blijft leeg');

reset_bc(static function (string $url): array {
    if (strpos($url, 'Urenstaatregels') === false) {
        return [];
    }
    return [[
        'Time_Sheet_No' => 'TS-H',
        'Header_Starting_Date' => '2026-09-07',
        'Header_Resource_No' => 'R9',
        'Status' => 'Approved',
        'Work_Type_Code' => 'DIR',
        'Total_Quantity' => 8,
        'Field1' => 8,
    ]];
});
$fetched = seshat_fetch_week_lines_from_bc('Hunter van Twist', '2026-09-07', '2026-09-13');
assert_same(1, count($fetched), 'BC-fallback na Mímir gaf geen regel');
assert_same('TS-H', $fetched[0]['time_sheet_no'], 'genormaliseerde regel');
assert_same('Approved', $fetched[0]['status'], 'Status uit de regel');
$bcCall = $calls[0] ?? '';
if (!is_string($bcCall) || strpos($bcCall, "https://bc.example:7148/Production/ODataV4/Company('Hunter%20van%20Twist')/Urenstaatregels?") !== 0) {
    fail('company moet exact Hunter van Twist zijn: ' . json_encode($bcCall));
}
$query = query_from_url($bcCall);
$sentSelect = array_values(array_filter(array_map('trim', explode(',', (string) ($query['$select'] ?? '')))));
sort($sentSelect, SORT_STRING);
if ($sentSelect !== $selected) {
    fail('$select in de aanroep wijkt af: ' . json_encode($sentSelect));
}
$sentFilter = (string) ($query['$filter'] ?? '');
if (strpos($sentFilter, "Status eq 'Approved'") === false || strpos($sentFilter, "Status eq 'Goedgekeurd'") === false || strpos($sentFilter, 'Header_Starting_Date ge 2026-09-07') === false) {
    fail('filter in de aanroep klopt niet: ' . $sentFilter);
}

reset_bc(static function (string $url): array {
    unset($url);
    throw new Exception('Mímir HTTP 400: geen urenstaatregels');
});
$emptyWeek = seshat_fetch_week_lines_from_bc('Hunter van Twist', '2026-09-28', '2026-10-04');
assert_same([], $emptyWeek, 'HTTP 400 geen urenstaatregels moet een lege week zijn');
if (count($calls) < 1 || strpos((string) $calls[0], 'Urenstaatregels') === false) {
    fail('lege week sloeg de BC-fallback over: ' . json_encode($calls));
}

reset_bc(static function (string $url): array {
    unset($url);
    throw new Exception("HTTP 400 from OData: The property 'Status' is not present in the selected properties.");
});
$realError = null;
try {
    seshat_fetch_week_lines_from_bc('Hunter van Twist', '2026-09-07', '2026-09-13');
    fail('een echte HTTP 400 moet blijven gooien');
} catch (Throwable $error) {
    $realError = $error;
}
if (!$realError instanceof Throwable || strpos($realError->getMessage(), 'Status') === false) {
    fail('echte fout werd gemaskeerd: ' . ($realError instanceof Throwable ? $realError->getMessage() : 'geen'));
}

delete_company_cache('Hunter van Twist');
reset_bc(static function (string $url): array {
    if (strpos($url, 'Header_Starting_Date') !== false && strpos($url, '2026-09-07') !== false) {
        throw new Exception('HTTP 400 from OData: geen urenstaatregels');
    }
    if (strpos($url, '2026-09-14') !== false) {
        return [[
            'Time_Sheet_No' => 'TS-14',
            'Header_Starting_Date' => '2026-09-14',
            'Header_Resource_No' => 'R2',
            'Work_Type_Code' => 'IND',
            'Field1' => 3,
            'Total_Quantity' => 3,
        ]];
    }
    return [];
});
$range = seshat_load_timesheet_lines('Hunter van Twist', '2026-09-07', '2026-09-20');
assert_same(1, count($range), 'een lege week mag het bereik niet legen');
assert_same('TS-14', $range[0]['time_sheet_no'] ?? '', 'regel uit de week mét data');
assert_same(0, $range[0]['line_no'] ?? -1, 'ontbrekende Line_No in een opgehaalde regel');
assert_same('', $range[0]['description'] ?? 'x', 'ontbrekende Description in een opgehaalde regel');
assert_same('', $range[0]['job_task_no'] ?? 'x', 'ontbrekend Job_Task_No in een opgehaalde regel');
delete_company_cache('Hunter van Twist');

echo "OK\n";
