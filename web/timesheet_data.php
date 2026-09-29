<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';
require_once __DIR__ . '/bc_data.php';
require_once __DIR__ . '/seshat_config.php';

/**
 * Functies
 */

function seshat_cache_base_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'seshat';
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    return $dir;
}

function seshat_company_slug(string $company): string
{
    $slug = strtolower(trim($company));
    $slug = preg_replace('/[^a-z0-9]+/', '_', $slug) ?? '';
    $slug = trim((string) $slug, '_');

    return $slug !== '' ? $slug : 'company';
}

function seshat_week_info(DateTimeImmutable $date): array
{
    $weekStart = $date->modify('monday this week');
    $weekEnd = $weekStart->modify('+6 days');

    return [
        'year' => (int) $weekStart->format('o'),
        'week' => (int) $weekStart->format('W'),
        'start' => $weekStart->format('Y-m-d'),
        'end' => $weekEnd->format('Y-m-d'),
    ];
}

function seshat_weeks_in_range(string $dateFrom, string $dateTo): array
{
    $from = new DateTimeImmutable($dateFrom);
    $to = new DateTimeImmutable($dateTo);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $weeks = [];
    $cursor = seshat_week_info($from);
    $endWeek = seshat_week_info($to);

    while ($cursor['start'] <= $endWeek['end']) {
        $key = $cursor['year'] . '-W' . str_pad((string) $cursor['week'], 2, '0', STR_PAD_LEFT);
        $weeks[$key] = $cursor;
        $cursor = seshat_week_info((new DateTimeImmutable($cursor['start']))->modify('+7 days'));
    }

    return array_values($weeks);
}

function seshat_week_cache_path(string $company, int $year, int $week): string
{
    $companyDir = seshat_cache_base_dir()
        . DIRECTORY_SEPARATOR
        . seshat_company_slug($company)
        . DIRECTORY_SEPARATOR
        . 'v' . SESHAT_CACHE_VERSION;

    if (!is_dir($companyDir)) {
        @mkdir($companyDir, 0777, true);
    }

    return $companyDir . DIRECTORY_SEPARATOR . sprintf('week_%d-W%02d.json', $year, $week);
}

function seshat_read_week_cache(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $version = (int) ($payload['cache_version'] ?? 0);
    if ($version !== SESHAT_CACHE_VERSION) {
        return null;
    }

    $lines = $payload['lines'] ?? null;
    if (!is_array($lines)) {
        return null;
    }

    return $lines;
}

function seshat_write_week_cache(string $path, array $lines, array $meta = []): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $payload = [
        'cache_version' => SESHAT_CACHE_VERSION,
        'cached_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        'meta' => $meta,
        'lines' => array_values($lines),
    ];

    $tmp = $path . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Kon timesheet-cache niet serialiseren.');
    }

    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $path);
}

function seshat_invalidate_old_cache_versions(string $company): void
{
    $companyRoot = seshat_cache_base_dir() . DIRECTORY_SEPARATOR . seshat_company_slug($company);
    if (!is_dir($companyRoot)) {
        return;
    }

    $entries = @scandir($companyRoot);
    if (!is_array($entries)) {
        return;
    }

    $current = 'v' . SESHAT_CACHE_VERSION;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === $current) {
            continue;
        }

        if (preg_match('/^v\d+$/', $entry) !== 1) {
            continue;
        }

        seshat_delete_directory($companyRoot . DIRECTORY_SEPARATOR . $entry);
    }
}

function seshat_delete_directory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $entries = @scandir($dir);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            seshat_delete_directory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

function seshat_approved_status_filter(): string
{
    $values = array_map(
        static fn(string $value): string => "Status eq '" . bc_escape_odata_string($value) . "'",
        seshat_approved_status_values()
    );

    return '(' . implode(' or ', $values) . ')';
}

function seshat_odata_error_is_empty_lines(Throwable $error): bool
{
    return str_contains(strtolower($error->getMessage()), 'geen urenstaatregels');
}

function seshat_fetch_week_lines_from_bc(string $company, string $weekStart, string $weekEnd): array
{
    $filter = seshat_approved_status_filter()
        . " and Header_Starting_Date ge " . $weekStart
        . " and Header_Starting_Date le " . $weekEnd;

    try {
        $rows = bc_fetch_rows($company, 'Urenstaatregels', [
            '$select' => SESHAT_LINES_SELECT,
            '$filter' => $filter,
        ], 300);
    } catch (Throwable $error) {
        // Mímir logt HTTP 400 "geen urenstaatregels" als een week geen goedgekeurde
        // regels heeft. bc_fetch_rows heeft de BC-fallback dan al geprobeerd.
        // Een lege week mag de rest van het datumbereik niet laten mislukken.
        if (seshat_odata_error_is_empty_lines($error)) {
            return [];
        }
        throw $error;
    }

    return array_map('seshat_normalize_line_row', $rows);
}

function seshat_normalize_line_row(array $row): array
{
    $hours = [];
    for ($day = 1; $day <= 7; $day++) {
        $hours[$day] = round((float) ($row['Field' . $day] ?? 0), 4);
    }

    $serviceOrder = trim((string) ($row['Service_Order_No'] ?? ''));
    $jobNo = trim((string) ($row['Job_No'] ?? ''));

    return [
        'time_sheet_no' => trim((string) ($row['Time_Sheet_No'] ?? '')),
        'line_no' => (int) ($row['Line_No'] ?? 0),
        'resource_no' => trim((string) ($row['Header_Resource_No'] ?? '')),
        'week_start' => trim((string) ($row['Header_Starting_Date'] ?? '')),
        'week_end' => trim((string) ($row['Header_Ending_Date'] ?? '')),
        'type' => trim((string) ($row['Type'] ?? '')),
        'status' => trim((string) ($row['Status'] ?? '')),
        'description' => trim((string) ($row['Description'] ?? '')),
        'job_no' => $jobNo,
        'job_task_no' => trim((string) ($row['Job_Task_No'] ?? '')),
        'work_type_code' => strtoupper(trim((string) ($row['Work_Type_Code'] ?? ''))),
        'work_order_no' => $serviceOrder !== '' ? $serviceOrder : $jobNo,
        'hours' => $hours,
        'total_hours' => round((float) ($row['Total_Quantity'] ?? 0), 4),
    ];
}

function seshat_line_in_range(array $line, string $dateFrom, string $dateTo): bool
{
    $weekStart = trim((string) ($line['week_start'] ?? ''));
    if ($weekStart === '') {
        return false;
    }

    return $weekStart >= $dateFrom && $weekStart <= $dateTo;
}

function seshat_load_timesheet_lines(string $company, string $dateFrom, string $dateTo): array
{
    seshat_invalidate_old_cache_versions($company);

    $weeks = seshat_weeks_in_range($dateFrom, $dateTo);
    $allLines = [];

    foreach ($weeks as $week) {
        $cachePath = seshat_week_cache_path($company, $week['year'], $week['week']);
        $cachedLines = seshat_read_week_cache($cachePath);

        if ($cachedLines === null) {
            $cachedLines = seshat_fetch_week_lines_from_bc($company, $week['start'], $week['end']);
            seshat_write_week_cache($cachePath, $cachedLines, [
                'company' => $company,
                'week_year' => $week['year'],
                'week_number' => $week['week'],
                'week_start' => $week['start'],
                'week_end' => $week['end'],
            ]);
        }

        foreach ($cachedLines as $line) {
            if (seshat_line_in_range($line, $dateFrom, $dateTo)) {
                $allLines[] = $line;
            }
        }
    }

    return $allLines;
}

function seshat_fetch_resource_map(string $company): array
{
    static $cache = [];

    if (isset($cache[$company])) {
        return $cache[$company];
    }

    $rows = bc_try_fetch_rows($company, 'AppResources', [
        '$select' => SESHAT_RESOURCES_SELECT,
    ], 3600);

    $map = [];
    foreach ($rows as $row) {
        $no = trim((string) ($row['No'] ?? ''));
        if ($no === '') {
            continue;
        }

        $map[$no] = [
            'name' => trim((string) ($row['Name'] ?? '')),
        ];
    }

    $cache[$company] = $map;
    return $map;
}

function seshat_fetch_header_name_map(string $company, array $timeSheetNos): array
{
    $timeSheetNos = array_values(array_filter(array_unique(array_map('strval', $timeSheetNos))));
    if ($timeSheetNos === []) {
        return [];
    }

    $chunks = array_chunk($timeSheetNos, 40);
    $map = [];

    foreach ($chunks as $chunk) {
        $filters = [];
        foreach ($chunk as $no) {
            $filters[] = "No eq '" . bc_escape_odata_string($no) . "'";
        }

        $rows = bc_try_fetch_rows($company, 'Urenstaten', [
            '$select' => SESHAT_HEADERS_SELECT,
            '$filter' => implode(' or ', $filters),
        ], 3600);

        foreach ($rows as $row) {
            $no = trim((string) ($row['No'] ?? ''));
            if ($no === '') {
                continue;
            }

            $name = trim((string) ($row['LVS_Resource_Name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($row['Resource_Name'] ?? ''));
            }

            $map[$no] = [
                'resource_no' => trim((string) ($row['Resource_No'] ?? '')),
                'resource_name' => $name,
            ];
        }
    }

    return $map;
}

function seshat_classify_line(array $line, array $productiveTypes, array $leaveTypes, array $ignoredTypes): string
{
    $code = strtoupper(trim((string) ($line['work_type_code'] ?? '')));
    if ($code === '' || in_array($code, $ignoredTypes, true)) {
        return 'ignored';
    }
    if (in_array($code, $leaveTypes, true)) {
        return 'leave';
    }
    if (in_array($code, $productiveTypes, true)) {
        return 'productive';
    }

    return 'unproductive';
}

function seshat_calc_productivity(float $productiveHours, float $unproductiveHours): float
{
    $workTotal = $productiveHours + $unproductiveHours;
    if ($workTotal <= 0) {
        return 0.0;
    }

    return round(($productiveHours / $workTotal) * 100, 1);
}

function seshat_category_hours(array $hoursByCategory, array $hiddenCategories): array
{
    $hidden = array_flip($hiddenCategories);
    $productive = in_array('productive', $hiddenCategories, true) ? 0.0 : (float) ($hoursByCategory['productive'] ?? 0);
    $unproductive = in_array('unproductive', $hiddenCategories, true) ? 0.0 : (float) ($hoursByCategory['unproductive'] ?? 0);
    $leave = in_array('leave', $hiddenCategories, true) ? 0.0 : (float) ($hoursByCategory['leave'] ?? 0);

    return [
        'productive' => $productive,
        'unproductive' => $unproductive,
        'leave' => $leave,
        'total' => $productive + $unproductive + $leave,
        'productivity' => seshat_calc_productivity($productive, $unproductive),
    ];
}

function seshat_parse_hidden_categories(string $value): array
{
    $allowed = ['productive', 'unproductive', 'leave'];
    $parts = preg_split('/[\s,;]+/', strtolower(trim($value))) ?: [];
    $hidden = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part !== '' && in_array($part, $allowed, true)) {
            $hidden[] = $part;
        }
    }

    return array_values(array_unique($hidden));
}

function seshat_filter_modal_work_orders(array $workOrders, array $hiddenCategories): array
{
    $hidden = array_flip($hiddenCategories);
    $filtered = [];

    foreach ($workOrders as $workOrder) {
        $lines = is_array($workOrder['lines'] ?? null) ? $workOrder['lines'] : [];
        $visibleLines = [];
        $hoursByCategory = [
            'productive' => 0.0,
            'unproductive' => 0.0,
            'leave' => 0.0,
        ];

        foreach ($lines as $line) {
            $classification = (string) ($line['classification'] ?? 'unproductive');
            if (isset($hidden[$classification])) {
                continue;
            }

            $visibleLines[] = $line;
            $hoursByCategory[$classification] = ($hoursByCategory[$classification] ?? 0) + (float) ($line['total_hours'] ?? 0);
        }

        if ($visibleLines === []) {
            continue;
        }

        $summary = seshat_category_hours($hoursByCategory, []);
        $filtered[] = [
            'work_order_no' => (string) ($workOrder['work_order_no'] ?? ''),
            'total_hours' => round((float) $summary['total'], 2),
            'productivity' => (float) $summary['productivity'],
            'lines' => $visibleLines,
        ];
    }

    usort($filtered, static fn(array $a, array $b): int => strcmp($a['work_order_no'], $b['work_order_no']));

    return $filtered;
}

function seshat_filter_dashboard(array $dashboard, array $hiddenCategories): array
{
    if ($hiddenCategories === []) {
        return $dashboard;
    }

    $hidden = array_flip($hiddenCategories);
    $filteredCards = [];

    foreach ($dashboard['cards'] as $card) {
        $hoursByCategory = [
            'productive' => (float) ($card['productive_hours'] ?? 0),
            'unproductive' => (float) ($card['unproductive_hours'] ?? 0),
            'leave' => (float) ($card['leave_hours'] ?? 0),
        ];
        $summary = seshat_category_hours($hoursByCategory, $hiddenCategories);
        if ($summary['total'] <= 0) {
            continue;
        }

        $modalWorkOrders = seshat_filter_modal_work_orders($card['modal_work_orders'] ?? [], $hiddenCategories);
        $filteredCards[] = array_merge($card, [
            'productive_hours' => round($summary['productive'], 2),
            'unproductive_hours' => round($summary['unproductive'], 2),
            'leave_hours' => round($summary['leave'], 2),
            'total_hours' => round($summary['total'], 2),
            'productivity' => $summary['productivity'],
            'modal_work_orders' => $modalWorkOrders,
        ]);
    }

    usort($filteredCards, static function (array $a, array $b): int {
        $productivityCompare = ((float) ($b['productivity'] ?? 0)) <=> ((float) ($a['productivity'] ?? 0));
        if ($productivityCompare !== 0) {
            return $productivityCompare;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $weeklySummary = [];
    foreach ($dashboard['weekly_summary'] as $row) {
        $summary = seshat_category_hours([
            'productive' => (float) ($row['productive_hours'] ?? 0),
            'unproductive' => (float) ($row['unproductive_hours'] ?? 0),
            'leave' => (float) ($row['leave_hours'] ?? 0),
        ], $hiddenCategories);
        if ($summary['total'] <= 0) {
            continue;
        }

        $weeklySummary[] = array_merge($row, [
            'total_hours' => round($summary['total'], 2),
            'productivity' => $summary['productivity'],
        ]);
    }

    usort($weeklySummary, static function (array $a, array $b): int {
        return [$b['week_year'], $b['week_number'], $a['name']] <=> [$a['week_year'], $a['week_number'], $b['name']];
    });

    $productivityByPerson = [];
    foreach ($weeklySummary as $row) {
        $productivityByPerson[$row['name']][] = (float) $row['productivity'];
    }

    $personSummary = [];
    foreach ($filteredCards as $card) {
        $weeklyValues = $productivityByPerson[$card['name']] ?? [];
        $avgProductivity = $weeklyValues !== []
            ? round(array_sum($weeklyValues) / count($weeklyValues), 1)
            : (float) $card['productivity'];

        $personSummary[] = [
            'name' => $card['name'],
            'total_hours' => $card['total_hours'],
            'avg_productivity' => $avgProductivity,
        ];
    }

    $weekGroups = [];
    foreach ($weeklySummary as $row) {
        $weekGroups[$row['week_label']][] = $row;
    }

    return array_merge($dashboard, [
        'cards' => $filteredCards,
        'person_summary' => $personSummary,
        'weekly_summary' => $weeklySummary,
        'week_groups' => $weekGroups,
    ]);
}

function seshat_line_hours_in_range(array $line, string $dateFrom, string $dateTo): float
{
    $weekStart = trim((string) ($line['week_start'] ?? ''));
    if ($weekStart === '') {
        return 0.0;
    }

    try {
        $start = new DateTimeImmutable($weekStart);
    } catch (Throwable $ignored) {
        return (float) ($line['total_hours'] ?? 0);
    }

    $rangeFrom = new DateTimeImmutable($dateFrom);
    $rangeTo = new DateTimeImmutable($dateTo);
    $total = 0.0;

    for ($day = 1; $day <= 7; $day++) {
        $dayDate = $start->modify('+' . ($day - 1) . ' days');
        $dayKey = $dayDate->format('Y-m-d');
        if ($dayKey >= $rangeFrom->format('Y-m-d') && $dayKey <= $rangeTo->format('Y-m-d')) {
            $total += (float) ($line['hours'][$day] ?? 0);
        }
    }

    return round($total, 4);
}

function seshat_last_week_range(): array
{
    $today = new DateTimeImmutable('today');
    $lastWeekDate = $today->modify('-7 days');
    $week = seshat_week_info($lastWeekDate);

    return [
        'start' => $week['start'],
        'end' => $week['end'],
    ];
}

function seshat_build_dashboard(string $company, string $dateFrom, string $dateTo): array
{
    $productiveTypes = seshat_productive_work_types();
    $leaveTypes = seshat_leave_work_types();
    $ignoredTypes = seshat_ignored_work_types();
    $resourceMap = seshat_fetch_resource_map($company);
    $rawLines = seshat_load_timesheet_lines($company, $dateFrom, $dateTo);

    $headerMap = seshat_fetch_header_name_map(
        $company,
        array_map(static fn(array $line): string => (string) ($line['time_sheet_no'] ?? ''), $rawLines)
    );

    $lastWeek = seshat_last_week_range();
    $people = [];
    $weeklyRows = [];

    foreach ($rawLines as $line) {
        $classification = seshat_classify_line($line, $productiveTypes, $leaveTypes, $ignoredTypes);
        if ($classification === 'ignored') {
            continue;
        }

        $resourceNo = trim((string) ($line['resource_no'] ?? ''));
        if ($resourceNo === '') {
            $header = $headerMap[(string) ($line['time_sheet_no'] ?? '')] ?? null;
            if (is_array($header)) {
                $resourceNo = trim((string) ($header['resource_no'] ?? ''));
            }
        }

        $resource = $resourceMap[$resourceNo] ?? ['name' => ''];
        $resourceName = trim((string) ($resource['name'] ?? ''));
        if ($resourceName === '') {
            $header = $headerMap[(string) ($line['time_sheet_no'] ?? '')] ?? null;
            if (is_array($header)) {
                $resourceName = trim((string) ($header['resource_name'] ?? ''));
            }
        }
        if ($resourceName === '') {
            $resourceName = $resourceNo !== '' ? $resourceNo : 'Onbekend';
        }

        $personKey = $resourceNo !== '' ? $resourceNo : $resourceName;
        if (!isset($people[$personKey])) {
            $people[$personKey] = [
                'resource_no' => $resourceNo,
                'name' => $resourceName,
                'productive_hours' => 0.0,
                'unproductive_hours' => 0.0,
                'leave_hours' => 0.0,
                'total_hours' => 0.0,
                'last_week_refs' => [],
                'work_orders' => [],
            ];
        }

        $hours = seshat_line_hours_in_range($line, $dateFrom, $dateTo);
        if ($hours <= 0) {
            continue;
        }

        $people[$personKey]['total_hours'] += $hours;
        if ($classification === 'productive') {
            $people[$personKey]['productive_hours'] += $hours;
        } elseif ($classification === 'leave') {
            $people[$personKey]['leave_hours'] += $hours;
        } else {
            $people[$personKey]['unproductive_hours'] += $hours;
        }

        $weekStart = trim((string) ($line['week_start'] ?? ''));
        if ($weekStart !== '') {
            $weekMeta = seshat_week_info(new DateTimeImmutable($weekStart));
            $weekKey = $weekMeta['year'] . '-W' . str_pad((string) $weekMeta['week'], 2, '0', STR_PAD_LEFT);
            $weeklyKey = $personKey . '|' . $weekKey;
            if (!isset($weeklyRows[$weeklyKey])) {
                $weeklyRows[$weeklyKey] = [
                    'week_year' => $weekMeta['year'],
                    'week_number' => $weekMeta['week'],
                    'week_label' => 'Week ' . $weekMeta['week'] . ' (' . $weekMeta['year'] . ')',
                    'resource_no' => $resourceNo,
                    'name' => $resourceName,
                    'productive_hours' => 0.0,
                    'unproductive_hours' => 0.0,
                    'leave_hours' => 0.0,
                    'total_hours' => 0.0,
                ];
            }

            if ($classification === 'productive') {
                $weeklyRows[$weeklyKey]['productive_hours'] += $hours;
            } elseif ($classification === 'leave') {
                $weeklyRows[$weeklyKey]['leave_hours'] += $hours;
            } else {
                $weeklyRows[$weeklyKey]['unproductive_hours'] += $hours;
            }
            $weeklyRows[$weeklyKey]['total_hours'] += $hours;
        }

        $lineWeekStart = trim((string) ($line['week_start'] ?? ''));
        if ($lineWeekStart >= $lastWeek['start'] && $lineWeekStart <= $lastWeek['end']) {
            $ref = trim((string) ($line['work_order_no'] ?? ''));
            if ($ref !== '') {
                $people[$personKey]['last_week_refs'][$ref] = true;
            }
        }

        $workOrderNo = trim((string) ($line['work_order_no'] ?? ''));
        if ($workOrderNo === '') {
            $workOrderNo = '(geen werkorder)';
        }

        if (!isset($people[$personKey]['work_orders'][$workOrderNo])) {
            $people[$personKey]['work_orders'][$workOrderNo] = [];
        }

        $detailKey = $workOrderNo . '|' . ($line['work_type_code'] ?? '') . '|' . ($line['week_start'] ?? '');
        if (!isset($people[$personKey]['work_orders'][$workOrderNo][$detailKey])) {
            $people[$personKey]['work_orders'][$workOrderNo][$detailKey] = [
                'work_order_no' => $workOrderNo,
                'work_type_code' => (string) ($line['work_type_code'] ?? ''),
                'week_start' => (string) ($line['week_start'] ?? ''),
                'classification' => $classification,
                'hours' => array_fill(1, 7, 0.0),
                'total_hours' => 0.0,
            ];
        }

        for ($day = 1; $day <= 7; $day++) {
            $people[$personKey]['work_orders'][$workOrderNo][$detailKey]['hours'][$day]
                += (float) ($line['hours'][$day] ?? 0);
        }
        $people[$personKey]['work_orders'][$workOrderNo][$detailKey]['total_hours'] += $hours;
    }

    $cards = [];
    foreach ($people as $personKey => $person) {
        $productive = round((float) $person['productive_hours'], 2);
        $unproductive = round((float) $person['unproductive_hours'], 2);
        $leave = round((float) $person['leave_hours'], 2);
        $total = round((float) $person['total_hours'], 2);
        $productivity = seshat_calc_productivity($productive, $unproductive);

        $modalWorkOrders = [];
        foreach ($person['work_orders'] as $workOrderNo => $detailsMap) {
            $details = array_values($detailsMap);
            usort($details, static function (array $a, array $b): int {
                return [$a['week_start'], $a['work_type_code']] <=> [$b['week_start'], $b['work_type_code']];
            });

            $hoursByCategory = [
                'productive' => 0.0,
                'unproductive' => 0.0,
                'leave' => 0.0,
            ];
            foreach ($details as $detail) {
                $classification = (string) ($detail['classification'] ?? 'unproductive');
                $hoursByCategory[$classification] = ($hoursByCategory[$classification] ?? 0) + (float) ($detail['total_hours'] ?? 0);
            }

            $summary = seshat_category_hours($hoursByCategory, []);

            $modalWorkOrders[] = [
                'work_order_no' => $workOrderNo,
                'total_hours' => round((float) $summary['total'], 2),
                'productivity' => (float) $summary['productivity'],
                'lines' => array_map(static function (array $detail): array {
                    $detail['hours'] = array_map(static fn(float $value): float => round($value, 2), $detail['hours']);
                    $detail['total_hours'] = round((float) $detail['total_hours'], 2);
                    return $detail;
                }, $details),
            ];
        }

        usort($modalWorkOrders, static fn(array $a, array $b): int => strcmp($a['work_order_no'], $b['work_order_no']));

        $cards[] = [
            'key' => $personKey,
            'resource_no' => $person['resource_no'],
            'name' => $person['name'],
            'productive_hours' => $productive,
            'unproductive_hours' => $unproductive,
            'leave_hours' => $leave,
            'total_hours' => $total,
            'productivity' => $productivity,
            'last_week_count' => count($person['last_week_refs']),
            'modal_work_orders' => $modalWorkOrders,
        ];
    }

    usort($cards, static function (array $a, array $b): int {
        $productivityCompare = ((float) ($b['productivity'] ?? 0)) <=> ((float) ($a['productivity'] ?? 0));
        if ($productivityCompare !== 0) {
            return $productivityCompare;
        }

        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    $weeklySummary = [];
    foreach ($weeklyRows as $row) {
        $total = round((float) $row['total_hours'], 2);
        $productive = round((float) $row['productive_hours'], 2);
        $weeklySummary[] = [
            'week_year' => (int) $row['week_year'],
            'week_number' => (int) $row['week_number'],
            'week_label' => (string) $row['week_label'],
            'name' => (string) $row['name'],
            'productive_hours' => $productive,
            'unproductive_hours' => round((float) $row['unproductive_hours'], 2),
            'leave_hours' => round((float) $row['leave_hours'], 2),
            'total_hours' => $total,
            'productivity' => seshat_calc_productivity($productive, (float) $row['unproductive_hours']),
        ];
    }

    usort($weeklySummary, static function (array $a, array $b): int {
        return [$b['week_year'], $b['week_number'], $a['name']] <=> [$a['week_year'], $a['week_number'], $b['name']];
    });

    $productivityByPerson = [];
    foreach ($weeklySummary as $row) {
        $productivityByPerson[$row['name']][] = (float) $row['productivity'];
    }

    $personSummary = array_map(static function (array $card) use ($productivityByPerson): array {
        $weeklyValues = $productivityByPerson[$card['name']] ?? [];
        $avgProductivity = $weeklyValues !== []
            ? round(array_sum($weeklyValues) / count($weeklyValues), 1)
            : (float) $card['productivity'];

        return [
            'name' => $card['name'],
            'total_hours' => $card['total_hours'],
            'avg_productivity' => $avgProductivity,
        ];
    }, $cards);

    $weekGroups = [];
    foreach ($weeklySummary as $row) {
        $weekGroups[$row['week_label']][] = $row;
    }

    return [
        'cards' => $cards,
        'person_summary' => $personSummary,
        'weekly_summary' => $weeklySummary,
        'week_groups' => $weekGroups,
        'productive_types' => $productiveTypes,
        'leave_types' => $leaveTypes,
        'ignored_types' => $ignoredTypes,
        'last_week' => $lastWeek,
    ];
}

function seshat_format_hours(float $hours): string
{
    if (abs($hours - round($hours)) < 0.00001) {
        return number_format($hours, 0, ',', '.');
    }

    return rtrim(rtrim(number_format($hours, 2, ',', '.'), '0'), ',');
}

function seshat_format_percent(float $value): string
{
    return number_format($value, 1, ',', '.') . '%';
}

function seshat_excel_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function seshat_excel_sheet_xml(array $rows): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>';

    $rowIndex = 1;
    foreach ($rows as $row) {
        $xml .= '<row r="' . $rowIndex . '">';
        $colIndex = 0;
        foreach ($row as $cell) {
            $colLetter = seshat_excel_column_name($colIndex);
            $ref = $colLetter . $rowIndex;
            if (is_int($cell) || is_float($cell)) {
                $xml .= '<c r="' . $ref . '"><v>' . seshat_excel_escape((string) $cell) . '</v></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . seshat_excel_escape((string) $cell) . '</t></is></c>';
            }
            $colIndex++;
        }
        $xml .= '</row>';
        $rowIndex++;
    }

    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function seshat_excel_column_name(int $index): string
{
    $name = '';
    $n = $index;
    do {
        $name = chr(65 + ($n % 26)) . $name;
        $n = intdiv($n, 26) - 1;
    } while ($n >= 0);

    return $name;
}

/**
 * Bouwt een week-matrix: newest week eerst, kolommen uren + % direct per week.
 *
 * @return array{weeks: list<array{key:string,label:string}>, rows: list<list<string|float>>}
 */
function seshat_excel_weeks_matrix(array $personSummary, array $weeklySummary): array
{
    $weeksByKey = [];
    foreach ($weeklySummary as $row) {
        $year = (int) ($row['week_year'] ?? 0);
        $week = (int) ($row['week_number'] ?? 0);
        if ($year <= 0 || $week <= 0) {
            continue;
        }

        $key = $year . '-W' . str_pad((string) $week, 2, '0', STR_PAD_LEFT);
        if (!isset($weeksByKey[$key])) {
            $weeksByKey[$key] = [
                'key' => $key,
                'year' => $year,
                'week' => $week,
                'label' => 'wk' . $week,
            ];
        }
    }

    $weeks = array_values($weeksByKey);
    usort($weeks, static function (array $a, array $b): int {
        return [$b['year'], $b['week']] <=> [$a['year'], $a['week']];
    });

    $byPersonWeek = [];
    foreach ($weeklySummary as $row) {
        $name = (string) ($row['name'] ?? '');
        $year = (int) ($row['week_year'] ?? 0);
        $week = (int) ($row['week_number'] ?? 0);
        if ($name === '' || $year <= 0 || $week <= 0) {
            continue;
        }

        $key = $year . '-W' . str_pad((string) $week, 2, '0', STR_PAD_LEFT);
        $byPersonWeek[$name][$key] = [
            'hours' => (float) ($row['total_hours'] ?? 0),
            'productivity' => (float) ($row['productivity'] ?? 0),
        ];
    }

    $header = ['Medewerker'];
    foreach ($weeks as $week) {
        $header[] = 'Uren ' . $week['label'];
        $header[] = '% Direct ' . $week['label'];
    }

    $matrixRows = [$header];
    foreach ($personSummary as $person) {
        $name = (string) ($person['name'] ?? '');
        if ($name === '') {
            continue;
        }

        $row = [$name];
        foreach ($weeks as $week) {
            $values = $byPersonWeek[$name][$week['key']] ?? null;
            if ($values === null) {
                $row[] = '';
                $row[] = '';
                continue;
            }

            $row[] = (float) $values['hours'];
            $row[] = seshat_format_percent((float) $values['productivity']);
        }
        $matrixRows[] = $row;
    }

    return [
        'weeks' => $weeks,
        'rows' => $matrixRows,
    ];
}

function seshat_build_excel_xlsx(array $personSummary, array $weeklySummary): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive is niet beschikbaar voor Excel-export.');
    }

    $overviewRows = [
        ['Medewerker', 'Totaal uren', 'Gem. % direct per week'],
    ];
    foreach ($personSummary as $row) {
        $overviewRows[] = [
            (string) ($row['name'] ?? ''),
            (float) ($row['total_hours'] ?? 0),
            seshat_format_percent((float) ($row['avg_productivity'] ?? 0)),
        ];
    }

    $weeksMatrix = seshat_excel_weeks_matrix($personSummary, $weeklySummary);
    $weeksRows = $weeksMatrix['rows'];

    $tmp = tempnam(sys_get_temp_dir(), 'seshat_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('Kon tijdelijk Excel-bestand niet aanmaken.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Kon Excel-archief niet openen.');
    }

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>'
    );

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>'
    );

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>'
        . '<sheet name="Overzicht" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Weken" sheetId="2" r:id="rId2"/>'
        . '</sheets>'
        . '</workbook>'
    );

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '</Relationships>'
    );

    $zip->addFromString('xl/worksheets/sheet1.xml', seshat_excel_sheet_xml($overviewRows));
    $zip->addFromString('xl/worksheets/sheet2.xml', seshat_excel_sheet_xml($weeksRows));
    $zip->close();

    $binary = file_get_contents($tmp);
    @unlink($tmp);
    if ($binary === false) {
        throw new RuntimeException('Kon Excel-bestand niet lezen.');
    }

    return $binary;
}
