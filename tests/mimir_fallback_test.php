<?php
/**
 * Simuleert een onbereikbare Mímir en controleert de directe BC-fallback.
 * Run: php tests/mimir_fallback_test.php
 */

$logFile = sys_get_temp_dir() . '/seshat-mimir-fallback-test.log';
@unlink($logFile);
ini_set('error_log', $logFile);
ini_set('log_errors', '1');

$mimirApi = 'mimir_test_key_should_not_leak';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];

$calls = [];
$GLOBALS['SESHAT_ODATA_BC_FETCH'] = static function (string $url, array $auth, int $ttl) use (&$calls): array {
    $calls[] = [
        'url' => $url,
        'user' => (string) ($auth['user'] ?? ''),
        'ttl' => $ttl,
    ];
    if (preg_match('#/ODataV4/Company(?:\\?|$)#', $url) === 1) {
        return [
            ['Name' => 'KVT Gas'],
            ['Name' => 'Hunter van Twist'],
            ['name' => 'Koninklijke van Twist'],
        ];
    }
    return [['No' => 'WO-1']];
};

require dirname(__DIR__) . '/web/odata.php';
require dirname(__DIR__) . '/web/bc_data.php';

function fail(string $message): void
{
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function fallback_log(): string
{
    global $logFile;
    $raw = @file_get_contents($logFile);
    return is_string($raw) ? $raw : '';
}

function fallback_count(): int
{
    return substr_count(fallback_log(), '[Seshat] Mímir failed, falling back to direct OData:');
}

if (odata_mimir_connect_timeout_seconds() !== 10) {
    fail('connect-timeout moet 10s zijn');
}
if (odata_mimir_timeout_seconds_for_sapi('cli') !== 600) {
    fail('CLI-timeout moet 600s blijven');
}
if (odata_mimir_timeout_seconds_for_sapi('fpm-fcgi') !== 90 || odata_mimir_timeout_seconds_for_sapi('apache2handler') !== 90) {
    fail('web-timeout moet ongeveer 90s zijn');
}
if (PHP_SAPI === 'cli' && odata_mimir_timeout_seconds() !== 600) {
    fail('huidige CLI-sapi moet de lange timeout gebruiken');
}

$names = odata_mimir_list_companies(null);
$expectedNames = ['Hunter van Twist', 'Koninklijke van Twist', 'KVT Gas'];
if ($names !== $expectedNames) {
    fail('company-fallback gaf ' . json_encode($names) . ' i.p.v. de gesorteerde BC-namen');
}
if (!odata_mimir_circuit_open()) {
    fail('circuit moet open na de eerste Mímir-fout');
}
if (count($calls) !== 1 || strpos($calls[0]['url'], 'https://bc.example:7148/Production/ODataV4/Company') !== 0) {
    fail('company-fallback riep de directe BC-fetch niet aan: ' . json_encode($calls));
}
if ($calls[0]['user'] !== 'bcuser') {
    fail('company-fallback gebruikte niet de BC-credentials');
}

$GLOBALS['demeter_company_environment_map'] = [
    'Hunter van Twist' => 'Production',
    'Koninklijke van Twist' => 'Production',
    'KVT Gas' => 'Production',
];
$startedBc = microtime(true);
$bcRows = bc_fetch_rows('KVT Gas', 'Urenstaatregels', ['$select' => 'No'], 30);
$bcElapsed = microtime(true) - $startedBc;
if ($bcElapsed >= 2.0) {
    fail('bc_fetch_rows sloeg Mímir niet over na de circuit-open (' . round($bcElapsed, 3) . 's)');
}
if (($bcRows[0]['No'] ?? '') !== 'WO-1') {
    fail('bc_fetch_rows gaf niet de gestubde BC-rijen terug');
}
$bcCall = $calls[1] ?? null;
if (!is_array($bcCall) || strpos($bcCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/Urenstaatregels?") !== 0 || $bcCall['user'] !== 'bcuser') {
    fail('bc_fetch_rows gebruikte niet het pre-Mímir BC-pad: ' . json_encode($bcCall));
}
$context = auth_set_current_company_context('KVT Gas');
if (($context['auth']['user'] ?? '') !== 'bcuser' || ($context['environment'] ?? '') !== 'Production') {
    fail('BC-auth werd niet gezet terwijl Mímir uitgevallen is: ' . json_encode($context));
}

$mimirBase = 'http://192.0.2.1:9';
$started = microtime(true);
$rows = odata_get_all(
    "https://mimir.invalid/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No",
    $auth,
    120
);
$elapsed = microtime(true) - $started;
if ($elapsed >= 2.0) {
    fail('circuit breaker sloeg Mímir niet over (' . round($elapsed, 3) . 's)');
}
if (($rows[0]['No'] ?? '') !== 'WO-1') {
    fail('entity-fallback gaf niet de gestubde BC-rijen terug');
}
$entityCall = $calls[2] ?? null;
$expectedEntityUrl = "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/AppWerkorders?\$select=No";
if (!is_array($entityCall) || $entityCall['url'] !== $expectedEntityUrl || $entityCall['user'] !== 'bcuser' || $entityCall['ttl'] !== 120) {
    fail('entity-fallback URL/auth/ttl klopt niet: ' . json_encode($entityCall));
}
if (fallback_count() !== 1) {
    fail('alleen de eerste Mímir-fout mag een fallback loggen, log=' . fallback_log());
}
$log = fallback_log();
if (strpos($log, 'mimir_test_key_should_not_leak') !== false || strpos($log, 'bc-secret') !== false) {
    fail('log bevat een geheim');
}
if (strpos($log, '[Seshat] Mímir failed, falling back to direct OData:') === false) {
    fail('logregel mist het verwachte prefix');
}

odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$beforeQuery = count($calls);
$queryRows = odata_mimir_query('KVT Gas', 'AppResource', ['$select' => 'No,Name'], 60);
if (($queryRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_query viel niet terug op de stub');
}
$queryCall = $calls[$beforeQuery] ?? null;
if (!is_array($queryCall) || strpos($queryCall['url'], "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppResource?") !== 0) {
    fail('query-fallback bouwde niet de pre-Mímir BC-URL: ' . json_encode($queryCall));
}

odata_mimir_circuit_reset();
$beforeFetch = count($calls);
$fetchRows = odata_mimir_fetch_all(
    "https://mimir.invalid/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No",
    15
);
if (($fetchRows[0]['No'] ?? '') !== 'WO-1') {
    fail('odata_mimir_fetch_all viel niet terug');
}
$fetchCall = $calls[$beforeFetch] ?? null;
if (!is_array($fetchCall) || $fetchCall['url'] !== "https://bc.example:7148/Production/ODataV4/Company('KVT%20Gas')/AppWerkorders?\$select=No") {
    fail('fetch_all-fallback herschreef de URL niet: ' . json_encode($fetchCall));
}

odata_mimir_circuit_reset();
$map = odata_mimir_company_environment_map(null);
if (($map['Hunter van Twist'] ?? '') !== 'Production' || ($map['KVT Gas'] ?? '') !== 'Production') {
    fail('environment-map viel niet terug op BC: ' . json_encode($map));
}

odata_mimir_circuit_reset();
$beforePageFetch = count($calls);
$pageRows = bc_fetch_rows('Koninklijke van Twist', 'Urenstaatregels', ['$select' => 'No'], 40);
if (($pageRows[0]['No'] ?? '') !== 'WO-1') {
    fail('eerste bc_fetch_rows viel niet terug op de stub');
}
$pageCall = $calls[$beforePageFetch] ?? null;
if (!is_array($pageCall) || strpos($pageCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Koninklijke%20van%20Twist')/Urenstaatregels?") !== 0 || $pageCall['user'] !== 'bcuser') {
    fail('eerste bc_fetch_rows bouwde niet de pre-Mímir URL: ' . json_encode($pageCall));
}
if (!odata_mimir_circuit_open()) {
    fail('bc_fetch_rows moet het circuit openen bij een Mímir-fout');
}

odata_mimir_circuit_reset();
$auth_list['Sandbox'] = ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret'];
$GLOBALS['demeter_company_environment_map']['Andere BV'] = 'Sandbox';
$loggedBeforeSecond = fallback_count();
$beforeSecond = count($calls);
$secondRows = odata_mimir_query('Andere BV', 'Urenstaatregels', ['$select' => 'No'], 30);
if (($secondRows[0]['No'] ?? '') !== 'WO-1') {
    fail('query voor een tweede environment viel niet terug op de stub');
}
$secondCall = $calls[$beforeSecond] ?? null;
$expectedSecondPrefix = "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/Urenstaatregels?";
if (!is_array($secondCall) || strpos($secondCall['url'], $expectedSecondPrefix) !== 0 || $secondCall['user'] !== 'sandbox-user') {
    fail('tweede environment gebruikte niet de bijbehorende auth: ' . json_encode($secondCall));
}
if (fallback_count() !== $loggedBeforeSecond + 1) {
    fail('een nieuwe Mímir-fout in het tweede environment logt niet precies één keer');
}

$loggedBeforeOpen = fallback_count();
$beforeSandboxUrl = count($calls);
$sandboxUrlRows = odata_get_all(
    "https://mimir.invalid/Sandbox/ODataV4/Company('Andere%20BV')/Urenstaatregels?\$select=No",
    $auth,
    20
);
$sandboxUrlCall = $calls[$beforeSandboxUrl] ?? null;
$expectedSandboxUrl = "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/Urenstaatregels?\$select=No";
if (($sandboxUrlRows[0]['No'] ?? '') !== 'WO-1' || !is_array($sandboxUrlCall) || $sandboxUrlCall['url'] !== $expectedSandboxUrl || $sandboxUrlCall['user'] !== 'sandbox-user') {
    fail('URL-environment won niet van de primaire auth: ' . json_encode($sandboxUrlCall));
}
if (fallback_count() !== $loggedBeforeOpen) {
    fail('een open circuit mag niet opnieuw een fallback loggen');
}

$encodedEnvUrl = odata_bc_url_from_odata_url("https://mimir.invalid/My%20Env/ODataV4/Company('X')/T");
if ($encodedEnvUrl !== "https://bc.example:7148/My%20Env/ODataV4/Company('X')/T" || strpos($encodedEnvUrl, 'My%2520Env') !== false) {
    fail('environment-segment moet één keer geëncodeerd worden: ' . $encodedEnvUrl);
}
$mappedEnvUrl = odata_bc_url_from_odata_url("https://mimir.invalid/mimir/ODataV4/Company('Andere%20BV')/Urenstaatregels");
if ($mappedEnvUrl !== "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/Urenstaatregels") {
    fail('placeholder-segment mimir moet de company-map gebruiken: ' . $mappedEnvUrl);
}

$beforeUnknown = count($calls);
$unknownRows = odata_mimir_query('Onbekend BV', 'Urenstaatregels', ['$select' => 'No'], 10);
$unknownCall = $calls[$beforeUnknown] ?? null;
if (($unknownRows[0]['No'] ?? '') !== 'WO-1' || !is_array($unknownCall) || strpos($unknownCall['url'], "https://bc.example:7148/Production/ODataV4/Company('Onbekend%20BV')/Urenstaatregels?") !== 0 || $unknownCall['user'] !== 'bcuser') {
    fail('onbekend bedrijf moet op het primaire environment terugvallen: ' . json_encode($unknownCall));
}
if (fallback_count() !== $loggedBeforeOpen) {
    fail('open circuit logde opnieuw bij een onbekend bedrijf');
}

$beforeSandboxCompanies = count($calls);
$sandboxCompanyRows = odata_direct_companies_as_rows('Sandbox');
$sandboxCompanyCall = $calls[$beforeSandboxCompanies] ?? null;
if (count($sandboxCompanyRows) !== 3 || !is_array($sandboxCompanyCall) || $sandboxCompanyCall['url'] !== 'https://bc.example:7148/Sandbox/ODataV4/Company' || $sandboxCompanyCall['user'] !== 'sandbox-user') {
    fail('companylijst voor Sandbox gebruikte niet dat environment: ' . json_encode($sandboxCompanyCall));
}

$savedEnvironment = $environment;
$environment = 'mimir';
$sandboxCacheKey = build_cache_key(
    "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/Urenstaatregels",
    ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret']
);
$placeholderCacheKey = build_cache_key(
    "https://mimir.invalid/mimir/ODataV4/Company('Andere%20BV')/Urenstaatregels",
    ['mode' => 'basic', 'user' => 'sandbox-user', 'pass' => 'sandbox-secret']
);
$environment = $savedEnvironment;
if ($sandboxCacheKey !== "https://bc.example:7148/Sandbox/ODataV4/Company('Andere%20BV')/Urenstaatregels|sandbox-user|Sandbox") {
    fail('cache-key moet het echte BC-environment gebruiken: ' . $sandboxCacheKey);
}
if ($placeholderCacheKey !== "https://mimir.invalid/mimir/ODataV4/Company('Andere%20BV')/Urenstaatregels|sandbox-user|Sandbox" || strpos($placeholderCacheKey, '|mimir') !== false) {
    fail('cache-key mag geen mimir-placeholder zijn: ' . $placeholderCacheKey);
}

odata_mimir_circuit_reset();
$loggedBeforeOther = fallback_count();
$callsBeforeOther = count($calls);
$otherException = null;
try {
    odata_mimir_or_direct(
        static function (): array {
            throw new RuntimeException('geen transportfout');
        },
        static function (): array {
            throw new RuntimeException('direct pad mag niet starten');
        }
    );
    fail('een exception buiten Mímir moet terugkomen');
} catch (Throwable $exception) {
    $otherException = $exception;
}
if (!$otherException instanceof RuntimeException || $otherException->getMessage() !== 'geen transportfout') {
    fail('niet-Mímir-exception werd gemaskeerd: ' . ($otherException instanceof Throwable ? $otherException->getMessage() : 'geen'));
}
if (odata_mimir_circuit_open()) {
    fail('een exception buiten Mímir mag het circuit niet openen');
}
if (fallback_count() !== $loggedBeforeOther || count($calls) !== $callsBeforeOther) {
    fail('een exception buiten Mímir startte de fallback');
}

$loggedBeforeRethrow = fallback_count();
$callsBeforeRethrow = count($calls);
odata_mimir_circuit_reset();
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://mimir.invalid/';
$environment = 'mimir';
$auth = [];
$auth_list = [];
$rethrown = null;
try {
    odata_get_all('https://mimir.invalid/mimir/ODataV4/Company(\'X\')/AppWerkorders', ['mode' => 'basic', 'user' => '', 'pass' => ''], 30);
    fail('zonder BC-credentials moet de oorspronkelijke Mímir-fout terugkomen');
} catch (Throwable $exception) {
    $rethrown = $exception;
}
if (!$rethrown instanceof Throwable) {
    fail('geen exception gevangen');
}
if (strpos($rethrown->getMessage(), 'Mímir') === false) {
    fail('hergooide fout is niet de Mímir-fout: ' . $rethrown->getMessage());
}
if (stripos($rethrown->getMessage(), 'credential') !== false) {
    fail('hergooide fout maskeert Mímir met een credentials-melding: ' . $rethrown->getMessage());
}
if (count($calls) !== $callsBeforeRethrow) {
    fail('zonder BC-credentials mag de directe fetch niet starten');
}
if (fallback_count() !== $loggedBeforeRethrow) {
    fail('zonder BC-credentials mag er geen fallback gelogd worden');
}

odata_mimir_circuit_reset();
$mimirApi = '';
$mimirBase = 'http://127.0.0.1:9';
$baseUrl = 'https://bc.example:7148/';
$environment = 'Production';
$auth = ['mode' => 'basic', 'user' => 'bcuser', 'pass' => 'bc-secret'];
$auth_list = ['Production' => $auth];
$loggedBeforeDirect = fallback_count();
$directOnlyUrl = 'https://mimir.invalid/Production/ODataV4/Company(\'KVT%20Gas\')/AppWerkorders?$select=No';
$directRows = odata_get_all($directOnlyUrl, $auth, 45);
if (odata_mimir_circuit_open()) {
    fail('lege $mimirApi mag Mímir niet proberen');
}
if (fallback_count() !== $loggedBeforeDirect) {
    fail('lege $mimirApi mag geen Mímir-fallback loggen');
}
$directCall = $calls[count($calls) - 1] ?? null;
if (($directRows[0]['No'] ?? '') !== 'WO-1' || !is_array($directCall) || $directCall['url'] !== $directOnlyUrl) {
    fail('lege $mimirApi moet de oude directe route ongewijzigd gebruiken: ' . json_encode($directCall));
}

$authFile = tempnam(sys_get_temp_dir(), 'seshat-auth-');
if (!is_string($authFile) || $authFile === '') {
    fail('tijdelijk auth-bestand kon niet worden gemaakt');
}
file_put_contents($authFile, <<<'PHP'
<?php
$baseUrl = 'https://from-file.example/';
$base = 'https://from-file-base.example/';
$environment = 'Sandbox';
$auth = ['mode' => 'basic', 'user' => 'fileuser', 'pass' => 'file-secret'];
$auth_list = ['Sandbox' => $auth];
PHP
);
$GLOBALS['SESHAT_AUTH_PHP_PATH'] = $authFile;
$GLOBALS['baseUrl'] = 'https://kept.example:7148/';
$GLOBALS['environment'] = 'mimir';
$GLOBALS['auth'] = [];
$GLOBALS['auth_list'] = [];
unset($GLOBALS['base']);
$picked = odata_bc_auth_for_environment_name('Sandbox');
if (($picked['user'] ?? '') !== 'fileuser') {
    fail('auth voor Sandbox werd niet uit het lazy geladen auth-bestand gelezen');
}
if ($GLOBALS['baseUrl'] !== 'https://kept.example:7148/') {
    fail('een gezette baseUrl werd overschreven: ' . (string) $GLOBALS['baseUrl']);
}
if (($GLOBALS['environment'] ?? '') !== 'Sandbox') {
    fail('placeholder-environment werd niet uit het auth-bestand gekopieerd');
}
if (($GLOBALS['auth']['user'] ?? '') !== 'fileuser') {
    fail('auth werd niet naar $GLOBALS gekopieerd');
}
if (($GLOBALS['auth_list']['Sandbox']['user'] ?? '') !== 'fileuser') {
    fail('auth_list werd niet naar $GLOBALS gekopieerd');
}
if (($GLOBALS['base'] ?? '') !== 'https://from-file-base.example/') {
    fail('base werd niet naar $GLOBALS gekopieerd');
}
odata_ensure_bc_auth_loaded();
require_once $authFile;
if ($GLOBALS['baseUrl'] !== 'https://kept.example:7148/' || ($GLOBALS['auth']['user'] ?? '') !== 'fileuser') {
    fail('tweede require_once overschreef de gekopieerde globals');
}
unset($GLOBALS['SESHAT_AUTH_PHP_PATH']);
@unlink($authFile);

$log = fallback_log();
if (strpos($log, 'sandbox-secret') !== false || strpos($log, 'file-secret') !== false || strpos($log, 'bc-secret') !== false || strpos($log, 'mimir_test_key_should_not_leak') !== false) {
    fail('log bevat een geheim');
}

echo "OK\n";
