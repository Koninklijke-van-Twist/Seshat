<?php
/**
 * Auth-template voor Seshat. Kopieer naar web/auth.php (niet in git).
 *
 * Mímir heeft voorrang. De BC-gegevens hieronder blijven naast $mimirApi staan:
 * bij een Mímir-storing valt Seshat daar automatisch op terug.
 *
 *   $mimirApi  = 'mimir_…';  // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 *
 * Met $mimirApi gezet gaan fetches eerst naar Mímir en daarna naar de BC-variabelen.
 * Zonder $mimirApi wordt alleen het BC-blok gebruikt.
 */

// --- Mímir (aanbevolen) ---
// $mimirApi  = 'mimir_…';
// $mimirBase = 'https://sleutels.kvt.nl/mimir/api';

// --- Business Central (direct pad, én fallback als Mímir uitvalt) ---
$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => 'USERNAME', 'pass' => 'PASSWORD'],
];
$environment = 'Production';
$auth = $auth_list[$environment];
$baseUrl = 'https://my-bc-domain.com:7148/';

$allowedUsers = [
    'user@domain.nl',
];
