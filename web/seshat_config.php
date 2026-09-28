<?php

/**
 * Constants
 */
const SESHAT_CACHE_VERSION = 1;

const SESHAT_LINES_SELECT = 'Time_Sheet_No,Header_Resource_No,Header_Starting_Date,Job_No,Work_Type_Code,Service_Order_No,Field1,Field2,Field3,Field4,Field5,Field6,Field7,Total_Quantity';
const SESHAT_HEADERS_SELECT = 'No,Resource_No,LVS_Resource_Name,Resource_Name';
const SESHAT_RESOURCES_SELECT = 'No,Name';

/**
 * Functies
 */

function seshat_config_path(string $filename): string
{
    return __DIR__ . '/' . $filename;
}

function seshat_read_string_list(string $filename): array
{
    $path = seshat_config_path($filename);
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }

    $result = [];
    foreach ($decoded as $item) {
        $code = strtoupper(trim((string) $item));
        if ($code !== '') {
            $result[] = $code;
        }
    }

    return array_values(array_unique($result));
}

function seshat_productive_work_types(): array
{
    return seshat_read_string_list('seshat_productive_work_types.json');
}

function seshat_leave_work_types(): array
{
    return seshat_read_string_list('seshat_leave_work_types.json');
}

function seshat_ignored_work_types(): array
{
    return seshat_read_string_list('seshat_ignored_work_types.json');
}

function seshat_default_date_from(): string
{
    $today = new DateTimeImmutable('today');
    $dayOfMonth = (int) $today->format('j');
    $daysInMonth = (int) $today->format('t');
    $half = (int) ceil($daysInMonth / 2);

    if ($dayOfMonth <= $half) {
        return $today->modify('first day of previous month')->format('Y-m-d');
    }

    return $today->modify('first day of this month')->format('Y-m-d');
}

function seshat_default_date_to(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function seshat_approved_status_values(): array
{
    return ['Approved', 'Goedgekeurd'];
}
