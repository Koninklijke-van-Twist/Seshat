<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function consolelog($text)
{
    static $enabled = null;
    if ($enabled === null) {
        $flag = getenv('DEMETER_DEBUG_ODATA');
        $enabled = is_string($flag) && in_array(strtolower(trim($flag)), ['1', 'true', 'yes', 'on'], true);
    }

    if (!$enabled) {
        return;
    }

    file_put_contents('php://stdout', $text);
}

/**
 * Mímir-proxy: als $mimirApi in auth.php staat, gaan alle OData-fetches
 * (nightly/hourly/on-demand via odata_get_all / bc_fetch_rows) naar Mímir i.p.v. BC.
 *
 * Met $mimirApi gezet zijn $auth_list / $environment / $baseUrl / $auth ongebruikt voor BC;
 * Mímir beheert environments — Seshat heeft alleen de API-key (+ optioneel $mimirBase) nodig.
 *
 * Tim moet in web/auth.php zetten (niet in git):
 *   $mimirApi  = 'mimir_…';              // verplicht om Mímir te activeren
 *   $mimirBase = 'https://sleutels.kvt.nl/mimir/api'; // optioneel
 */

function odata_mimir_api_key(): string
{
    global $mimirApi;
    if (!isset($mimirApi) || !is_string($mimirApi)) {
        return '';
    }
    return trim($mimirApi);
}

function odata_mimir_enabled(): bool
{
    return odata_mimir_api_key() !== '';
}

function odata_mimir_base_url(): string
{
    global $mimirBase;
    if (isset($mimirBase) && is_string($mimirBase) && trim($mimirBase) !== '') {
        return rtrim(trim($mimirBase), '/');
    }
    return 'https://sleutels.kvt.nl/mimir/api';
}

function odata_mimir_request(string $method, string $path, ?array $jsonBody = null): array
{
    $apiKey = odata_mimir_api_key();
    if ($apiKey === '') {
        throw new Exception('Mímir API-sleutel ontbreekt ($mimirApi).');
    }

    $url = odata_mimir_base_url() . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey,
        'X-API-Key: ' . $apiKey,
    ];
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Seshat-MimirClient/1.0',
    ];
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new Exception('Mímir request JSON encode mislukt.');
        }
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POSTFIELDS] = $payload;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('Mímir cURL error: ' . $err);
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code < 200 || $code >= 300) {
        $message = is_array($decoded) ? (string) ($decoded['error'] ?? $raw) : $raw;
        throw new Exception('Mímir HTTP ' . $code . ': ' . $message);
    }
    if (!is_array($decoded)) {
        throw new Exception('Mímir gaf ongeldige JSON terug.');
    }
    return $decoded;
}

/**
 * @return array{company: string, entity: string, query: array<string, string>}|null
 */
function odata_mimir_parse_entity_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    $path = (string) $parts['path'];
    // .../ODataV4/Company('Name')/EntitySet  or urlencoded company
    if (preg_match("#/ODataV4/Company\\((?:'([^']*)'|%27([^%]+)%27)\\)/([^/?]+)#i", $path, $match) !== 1) {
        return null;
    }
    $company = rawurldecode($match[1] !== '' ? $match[1] : $match[2]);
    $company = str_replace("''", "'", $company);
    $entity = rawurldecode($match[3]);
    $query = [];
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        parse_str($parts['query'], $parsed);
        foreach ($parsed as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $query[$key] = (string) $value;
            }
        }
    }
    return [
        'company' => $company,
        'entity' => $entity,
        'query' => $query,
    ];
}

/**
 * @return array{environment: string}|null
 */
function odata_mimir_parse_companies_url(string $url): ?array
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['path'])) {
        return null;
    }
    $path = (string) $parts['path'];
    // .../{environment}/ODataV4/Company or Companies
    if (preg_match('#/([^/]+)/ODataV4/(?:Companies|Company)(?:/|\\?|$)#i', $path . (isset($parts['query']) ? '?' : ''), $match) !== 1
        && preg_match('#/([^/]+)/ODataV4/(?:Companies|Company)$#i', $path, $match) !== 1) {
        return null;
    }
    return ['environment' => rawurldecode($match[1])];
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_companies_as_rows(?string $environment = null): array
{
    $response = odata_mimir_request('GET', 'companies.php');
    $items = $response['value'] ?? null;
    if (!is_array($items)) {
        throw new Exception("Mímir companies-antwoord mist 'value'.");
    }
    $rows = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? $item['Name'] ?? ''));
        $env = trim((string) ($item['environment'] ?? ''));
        if ($name === '') {
            continue;
        }
        if ($environment !== null && $environment !== '' && $env !== '' && strcasecmp($env, $environment) !== 0) {
            continue;
        }
        $rows[] = ['Name' => $name, 'environment' => $env];
    }
    return $rows;
}

/**
 * Bedrijfsnamen via Mímir companies.php (gesorteerd).
 *
 * @return list<string>
 */
function odata_mimir_list_companies(?string $environment = null): array
{
    $rows = odata_mimir_companies_as_rows($environment);
    $names = [];
    $seen = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $key = strtolower($name);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $names[] = $name;
    }
    natcasesort($names);
    return array_values($names);
}

/**
 * name => environment map uit Mímir companies.php.
 *
 * @return array<string, string>
 */
function odata_mimir_company_environment_map(?string $environment = null): array
{
    $rows = odata_mimir_companies_as_rows($environment);
    $map = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['Name'] ?? ''));
        $env = trim((string) ($row['environment'] ?? ''));
        if ($name === '' || $env === '') {
            continue;
        }
        $map[$name] = $env;
    }
    ksort($map, SORT_NATURAL | SORT_FLAG_CASE);
    return $map;
}

/**
 * Directe company/table-query via Mímir — geen BC-URL nodig.
 * $odataQuery gebruikt Seshat-keys zoals $select / $filter.
 *
 * @param array<string, mixed> $odataQuery
 * @return list<array<string, mixed>>
 */
function odata_mimir_query(string $company, string $table, array $odataQuery, int $ttlSeconds): array
{
    consolelog("Mímir query company=$company table=$table\n");

    $body = [
        'company' => $company,
        'table' => $table,
        'max_age' => max(0, $ttlSeconds),
        'top' => 0,
    ];

    $select = trim((string) ($odataQuery['$select'] ?? $odataQuery['select'] ?? ''));
    if ($select !== '') {
        $cols = [];
        foreach (explode(',', $select) as $col) {
            $col = trim($col);
            if ($col !== '') {
                $cols[] = $col;
            }
        }
        if ($cols !== []) {
            $body['select'] = $cols;
        }
    }

    $filter = trim((string) ($odataQuery['$filter'] ?? $odataQuery['filter'] ?? ''));
    if ($filter !== '') {
        $body['filter'] = $filter;
    }

    $response = odata_mimir_request('POST', 'query.php', $body);
    if (!isset($response['value']) || !is_array($response['value'])) {
        throw new Exception("Mímir query-antwoord mist 'value'.");
    }
    /** @var list<array<string, mixed>> $value */
    $value = $response['value'];
    return $value;
}

/**
 * @return list<array<string, mixed>>
 */
function odata_mimir_fetch_all(string $url, int $ttlSeconds): array
{
    consolelog("Mímir fetch $url\n");

    $companies = odata_mimir_parse_companies_url($url);
    if ($companies !== null) {
        return odata_mimir_companies_as_rows($companies['environment']);
    }

    $parsed = odata_mimir_parse_entity_url($url);
    if ($parsed === null) {
        throw new Exception('Mímir: OData-URL kon niet worden vertaald naar company/table: ' . $url);
    }

    return odata_mimir_query($parsed['company'], $parsed['entity'], $parsed['query'], $ttlSeconds);
}

function odata_get_all(string $url, array $auth, $ttlSeconds = 300): array
{
    consolelog("Fetching $url\n");
    $ttlSeconds = max(0, (int) $ttlSeconds);

    if (odata_mimir_api_key() !== '') {
        // Mímir beheert de BC-cache (max_age); Seshat-filecache wordt overgeslagen.
        return odata_mimir_fetch_all($url, $ttlSeconds === 0 ? 3600 : $ttlSeconds);
    }

    $ttlSeconds = max(1, $ttlSeconds);
    maybe_cleanup_expired_cache_files();

    $cacheKey = build_cache_key($url, $auth);
    $cachePath = cache_path_for_key($cacheKey);

    if (is_file($cachePath)) {

        consolelog("Found in cache.\n");
        $cached = read_cache_payload($cachePath, $ttlSeconds);
        if ($cached['valid']) {
            consolelog("Returning data.\n");
            return $cached['data'];
        }

        if ($cached['delete']) {
            consolelog("Cache expired.\n");
            @unlink($cachePath);
        }
    }

    $all = [];
    $next = $url;

    while ($next) {
        $resp = odata_get_json($next, $auth);

        if (!isset($resp['value']) || !is_array($resp['value'])) {
            throw new Exception("OData response missing 'value' array");
        }

        $all = array_merge($all, $resp['value']);
        $next = $resp['@odata.nextLink'] ?? null;
        consolelog("Reading next chunk...\n");
    }

    consolelog("Fetched. Now caching...\n");
    write_cache_json($cachePath, $all, $ttlSeconds, $url);
    consolelog("Done, returning data.\n");
    return $all;
}

function odata_get_json(string $url, array $auth): array
{
    $ch = curl_init($url);
    $userAgent = 'Demeter-ODataClient/1.0 (Windows; nl-NL)';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_HTTPHEADER => [
            "Accept: application/json",
            "Accept-Language: nl-NL,nl;q=0.9,en;q=0.8",
        ],
    ]);

    // Auth: kies 1.
    if (($auth['mode'] ?? '') === 'basic') {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    } elseif (($auth['mode'] ?? '') === 'ntlm') {
        // Werkt als BC via Windows auth/NTLM gaat:
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NTLM);
        curl_setopt($ch, CURLOPT_USERPWD, $auth['user'] . ":" . $auth['pass']);
    }

    // (optioneel) als je met interne CA/self-signed werkt:
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new Exception("cURL error: " . curl_error($ch));
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new Exception("HTTP $code from OData: $raw");
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new Exception("Invalid JSON from OData");
    }

    return $json;
}

function build_cache_key(string $url, array $auth): string
{
    require __DIR__ . "/auth.php";
    require_once __DIR__ . "/auth_helper.php";
    $user = (string) ($auth['user'] ?? '');
    $envFragment = auth_get_environment_key_fragment();
    return $url . '|' . $user . '|' . $envFragment;
}

function cache_base_dir(): string
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . "cache" . DIRECTORY_SEPARATOR . "odata";
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    return $dir;
}

function cache_cleanup_marker_path(): string
{
    return cache_base_dir() . "/.cleanup_marker";
}

function maybe_cleanup_expired_cache_files(): void
{
    $markerPath = cache_cleanup_marker_path();
    $now = time();
    $intervalSeconds = 300;

    if (is_file($markerPath)) {
        $lastRun = (int) @file_get_contents($markerPath);
        if ($lastRun > 0 && ($now - $lastRun) < $intervalSeconds) {
            return;
        }
    }

    @file_put_contents($markerPath, (string) $now, LOCK_EX);

    $entries = @scandir(cache_base_dir());
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.cleanup_marker') {
            continue;
        }

        $path = cache_base_dir() . '/' . $entry;
        if (!is_file($path) || pathinfo($path, PATHINFO_EXTENSION) !== 'json') {
            continue;
        }

        $fallbackMaxAge = 21600;
        $age = $now - (int) @filemtime($path);
        if ($age > $fallbackMaxAge) {
            @unlink($path);
        }
    }
}

function read_cache_payload(string $path, int $fallbackTtlSeconds): array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    if (isset($payload['_meta']) && isset($payload['data']) && is_array($payload['data'])) {
        $expiresAt = (int) ($payload['_meta']['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() <= $expiresAt) {
            return ['valid' => true, 'delete' => false, 'data' => $payload['data']];
        }

        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    if ($fallbackTtlSeconds > 0) {
        $age = time() - (int) @filemtime($path);
        if ($age >= 0 && $age < $fallbackTtlSeconds) {
            return ['valid' => true, 'delete' => false, 'data' => $payload];
        }

        return ['valid' => false, 'delete' => true, 'data' => []];
    }

    return ['valid' => false, 'delete' => false, 'data' => []];
}
function cache_path_for_key(string $cacheKey): string
{
    // bestandsnaam moet veilig en niet te lang: hash is ideaal
    $hash = hash('sha256', $cacheKey);
    return cache_base_dir() . "/" . $hash . ".json";
}

function write_cache_json(string $path, array $data, int $ttlSeconds, string $sourceUrl = ''): void
{
    $tmp = $path . ".tmp";
    $now = time();
    $payload = [
        '_meta' => [
            'cached_at' => $now,
            'expires_at' => $now + max(1, $ttlSeconds),
            'source_url' => $sourceUrl,
        ],
        'data' => $data,
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if ($json === false) {
        throw new Exception("Failed to encode cache JSON");
    }

    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $path);
}

function odata_cache_read_payload_meta(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        return null;
    }

    $meta = $payload['_meta'] ?? null;
    if (!is_array($meta)) {
        return null;
    }

    return [
        'cached_at' => (int) ($meta['cached_at'] ?? 0),
        'expires_at' => (int) ($meta['expires_at'] ?? 0),
        'source_url' => (string) ($meta['source_url'] ?? ''),
        'attributes' => odata_cache_extract_attributes_from_payload($payload),
    ];
}

function odata_cache_extract_attributes_from_payload(array $payload): array
{
    $data = $payload['data'] ?? null;
    if (!is_array($data) || count($data) === 0) {
        return [];
    }

    $firstRow = $data[0] ?? null;
    if (!is_array($firstRow)) {
        return [];
    }

    $result = [];
    foreach ($firstRow as $key => $value) {
        if (!is_string($key)) {
            continue;
        }

        $key = trim($key);
        if ($key === '') {
            continue;
        }

        if (strcasecmp($key, '@odata.etag') === 0) {
            continue;
        }

        if (is_scalar($value) || $value === null) {
            $valueText = trim((string) $value);
        } else {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $valueText = is_string($encoded) ? $encoded : '';
        }

        if ($valueText === '') {
            $result[] = $key;
            continue;
        }

        $result[] = $key . ': ' . $valueText;
    }

    return array_values(array_unique($result));
}

function odata_cache_title_from_url(string $url, string $fallback): string
{
    if ($url === '') {
        return $fallback;
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($path === '') {
        return $fallback;
    }

    $name = basename($path);
    if ($name === '') {
        return $fallback;
    }

    return rawurldecode($name);
}

function odata_cache_status_payload(): array
{
    $cacheDir = cache_base_dir();
    $totalBytes = 0;
    $entriesPayload = [];
    $now = time();

    if (is_dir($cacheDir)) {
        $iterator = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            $filename = $fileInfo->getFilename();
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            $meta = odata_cache_read_payload_meta($path);
            if ($meta === null) {
                continue;
            }

            $expiresAt = (int) ($meta['expires_at'] ?? 0);
            if ($expiresAt > 0 && $expiresAt <= $now) {
                @unlink($path);
                continue;
            }

            $sizeBytes = (int) $fileInfo->getSize();
            $totalBytes += $sizeBytes;

            $url = (string) ($meta['source_url'] ?? '');
            $nameFallback = pathinfo($filename, PATHINFO_FILENAME);
            $entriesPayload[] = [
                'id' => $filename,
                'name' => odata_cache_title_from_url($url, $nameFallback),
                'url' => $url,
                'attributes' => is_array($meta['attributes'] ?? null) ? $meta['attributes'] : [],
                'size_bytes' => $sizeBytes,
                'cached_at' => (int) ($meta['cached_at'] ?? 0),
                'expires_at' => $expiresAt,
            ];
        }
    }

    usort($entriesPayload, function (array $a, array $b): int {
        return ((int) ($b['size_bytes'] ?? 0)) <=> ((int) ($a['size_bytes'] ?? 0));
    });

    return [
        'bytes' => $totalBytes,
        'entries' => $entriesPayload,
    ];
}

function odata_send_cache_status_json(): void
{
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $payload = odata_cache_status_payload();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_send_cache_delete_json(): void
{
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $id = trim((string) ($_POST['id'] ?? $_GET['id'] ?? ''));
    if ($id === '' || !preg_match('/^[a-z0-9._-]+\\.json$/i', $id)) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'deleted' => false,
            'error' => 'Ongeldige cache-id',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $safeId = basename($id);
    $path = cache_base_dir() . '/' . $safeId;
    $deleted = false;
    if (is_file($path)) {
        $deleted = @unlink($path);
    }

    echo json_encode([
        'ok' => true,
        'deleted' => $deleted,
        'id' => $safeId,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_send_cache_clear_json(): void
{
    if (function_exists('xdebug_disable')) {
        xdebug_disable();
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $deletedCount = 0;
    $failedCount = 0;
    $cacheDir = cache_base_dir();

    if (is_dir($cacheDir)) {
        $iterator = new FilesystemIterator($cacheDir, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $filename = $fileInfo->getFilename();
            if (pathinfo($filename, PATHINFO_EXTENSION) !== 'json') {
                continue;
            }

            if (@unlink($fileInfo->getPathname())) {
                $deletedCount++;
            } else {
                $failedCount++;
            }
        }
    }

    echo json_encode([
        'ok' => $failedCount === 0,
        'deleted_count' => $deletedCount,
        'failed_count' => $failedCount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function odata_is_direct_request(): bool
{
    $self = basename(__FILE__);
    $scriptFilename = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $phpSelf = basename((string) ($_SERVER['PHP_SELF'] ?? ''));

    return $scriptFilename === $self || $scriptName === $self || $phpSelf === $self;
}

/**
 * Render een volledige cache-widget (HTML + scoped CSS + JS polling) als string.
 *
 * Agent-contract:
 * - Deze functie is self-contained: output bevat een root wrapper, <style> en <script>.
 * - Meerdere instanties op 1 pagina zijn veilig; selectors worden gescope'd met uniek instance-id.
 * - Pas positionering/layout bij voorkeur aan via $options['css'] i.p.v. core CSS te wijzigen.
 *
 * Default-implementatie:
 * <?= injectTimerHtml([
 *           'statusUrl' => 'odata.php?action=cache_status',
 *           'title' => 'Cachebestanden',
 *           'label' => 'Cache',
 *       ]) ?>
 * 
 * Ondersteunde opties:
 * - statusUrl (string) Endpoint voor JSON payload met keys: bytes (number), entries (array)
 * - deleteUrl (string) Endpoint voor direct verwijderen van 1 cachebestand (POST id=<filename>)
 * - clearUrl  (string) Endpoint voor verwijderen van alle cachebestanden
 * - title     (string) Titel in popout-header
 * - label     (string) Label naast byte-teller
 * - css       (string) Extra CSS die onderaan het interne <style>-blok wordt toegevoegd
 *
 * CSS placeholder:
 * - Gebruik {{root}} of {root} in $options['css']; dit wordt vervangen door '#<instanceId>'.
 * - Daarmee target je alleen deze instance en voorkom je globale CSS-conflicten.
 *
 * JSON contract voor statusUrl:
 * {
 *   "bytes": 12345,
 *   "entries": [
 *     {
 *       "id": "...json",
 *       "name": "ValueEntries",
 *       "url": "https://...",
 *       "attributes": ["No: 1000", "Description: Filter element"],
 *       "size_bytes": 123,
 *       "cached_at": 1700000000,
 *       "expires_at": 1700003600
 *     }
 *   ]
 * }
 *
 * Voorbeeld:
 * injectTimerHtml([
 *   'statusUrl' => 'odata.php?action=cache_status',
 *   'deleteUrl' => 'odata.php?action=cache_delete',
 *   'clearUrl' => 'odata.php?action=cache_clear',
 *   'title' => 'Cachebestanden',
 *   'label' => 'Cache',
 *   'css' => '{{root}} .odata-cache-widget{top:16px;left:20px;right:auto;} {{root}} .odata-cache-popout{top:64px;left:20px;right:auto;}'
 * ])
 */
function injectTimerHtml(array $options = []): string
{
    $dir = cache_base_dir();
    $statusUrl = (string) ($options['statusUrl'] ?? 'odata.php?action=cache_status');
    $deleteUrl = (string) ($options['deleteUrl'] ?? 'odata.php?action=cache_delete');
    $clearUrl = (string) ($options['clearUrl'] ?? 'odata.php?action=cache_clear');
    $title = (string) ($options['title'] ?? 'Cachebestanden') . " ($dir)";
    $label = (string) ($options['label'] ?? 'Cache');
    $instanceId = 'odata-cache-' . substr(hash('sha256', uniqid('', true)), 0, 8);
    $customCss = trim((string) ($options['css'] ?? ''));

    if ($customCss !== '') {
        $customCss = str_replace(['{{root}}', '{root}'], '#' . $instanceId, $customCss);
    }

    $statusUrlJs = json_encode($statusUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $deleteUrlJs = json_encode($deleteUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $clearUrlJs = json_encode($clearUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $titleHtml = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $labelHtml = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

    return <<<HTML
<div class="odata-cache-root" id="{$instanceId}">
    <style>
        #{$instanceId} .odata-cache-widget {
            position: absolute;
            top: 8px;
            right: 20px;
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 11px;
            color: #4f6077;
            line-height: 1.2;
            min-width: 145px;
            text-align: right;
            z-index: 10;
            cursor: pointer;
            user-select: none;
        }

        #{$instanceId} .odata-cache-value {
            font-weight: 700;
            color: #314257;
            font-variant-numeric: tabular-nums;
        }

        #{$instanceId} .odata-cache-glow-up {
            animation: {$instanceId}-cacheGlowUp 700ms ease-out 1;
        }

        #{$instanceId} .odata-cache-glow-down {
            animation: {$instanceId}-cacheGlowDown 700ms ease-out 1;
        }

        @keyframes {$instanceId}-cacheGlowUp {
            0% {
                box-shadow: 0 0 0 0 rgba(215, 40, 40, 0.55);
            }

            35% {
                box-shadow: 0 0 0 4px rgba(215, 40, 40, 0.25);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(215, 40, 40, 0);
            }
        }

        @keyframes {$instanceId}-cacheGlowDown {
            0% {
                box-shadow: 0 0 0 0 rgba(21, 160, 70, 0.55);
            }

            35% {
                box-shadow: 0 0 0 4px rgba(21, 160, 70, 0.25);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(21, 160, 70, 0);
            }
        }

        #{$instanceId} .odata-cache-popout {
            position: absolute;
            top: 56px;
            right: 20px;
            width: min(760px, calc(100vw - 40px));
            max-height: 60vh;
            overflow: auto;
            background: #fff;
            border: 1px solid #d7dfeb;
            border-radius: 10px;
            box-shadow: 0 12px 28px rgba(23, 37, 61, 0.14);
            z-index: 30;
            display: none;
            overflow-x: hidden;
        }

        #{$instanceId} .odata-cache-popout.open {
            display: block;
        }

        #{$instanceId} .odata-cache-popout-head {
            padding: 10px 12px;
            border-bottom: 1px solid #e5ecf6;
            font-size: 12px;
            color: #516179;
            font-weight: 700;
            position: sticky;
            top: 0;
            z-index: 3;
            background: #fff;
            box-shadow: 0 1px 0 #e5ecf6;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        #{$instanceId} .odata-cache-popout-close {
            border: 1px solid #d4dce8;
            background: #fff;
            color: #566a82;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1;
            padding: 4px 6px;
            cursor: pointer;
            width: 30px;
        }

        #{$instanceId} .odata-cache-popout-head-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        #{$instanceId} .odata-cache-popout-clear {
            border: 0;
            background: transparent;
            color: #c73737;
            cursor: pointer;
            font-size: 13px;
            line-height: 1;
            width: 18px;
            height: 18px;
            display: inline-grid;
            place-items: center;
            padding: 0;
        }

        #{$instanceId} .odata-cache-popout-clear:hover {
            color: #a81f1f;
        }

        #{$instanceId} .odata-cache-popout-clear:disabled {
            opacity: 0.45;
            cursor: default;
        }

        #{$instanceId} .odata-cache-popout-body {
            padding: 8px;
            display: grid;
            gap: 8px;
            background: #fff;
            position: relative;
            z-index: 1;
            min-width: 0;
        }

        #{$instanceId} .odata-cache-item {
            border: 1px solid #e5ecf6;
            border-radius: 8px;
            padding: 8px 10px;
            background: #fcfdff;
            min-width: 0;
            transition: background-color 160ms ease, border-color 160ms ease;
        }

        #{$instanceId} .odata-cache-item.is-deleting {
            background: #fff1f1;
            border-color: #f0bcbc;
        }

        #{$instanceId} .odata-cache-item-top {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 10px;
            min-width: 0;
        }

        #{$instanceId} .odata-cache-item-name {
            font-size: 12px;
            color: #27384c;
            font-weight: 700;
            flex: 1 1 auto;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #{$instanceId} .odata-cache-item-size {
            font-size: 11px;
            color: #516179;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        #{$instanceId} .odata-cache-item-actions {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex: 0 0 auto;
        }

        #{$instanceId} .odata-cache-item-delete {
            border: 0;
            background: transparent;
            color: #c73737;
            cursor: pointer;
            padding: 0;
            font-size: 12px;
            line-height: 1;
            width: 14px;
            height: 14px;
            display: inline-grid;
            place-items: center;
            opacity: 0.92;
        }

        #{$instanceId} .odata-cache-item-delete:hover {
            opacity: 1;
            color: #a81f1f;
        }

        #{$instanceId} .odata-cache-item-delete:disabled {
            opacity: 0.45;
            cursor: default;
        }

        #{$instanceId} .odata-cache-item-url {
            margin-top: 3px;
            font-size: 10px;
            color: #7a899d;
            display: block;
            max-width: 100%;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        #{$instanceId} .odata-cache-item-timer {
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 10px;
            color: #64758b;
        }

        #{$instanceId} .odata-cache-item-bar {
            position: relative;
            flex: 1 1 auto;
            height: 6px;
            border-radius: 999px;
            background: #e4ebf6;
            overflow: hidden;
        }

        #{$instanceId} .odata-cache-item-bar-fill {
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 0%;
            background: linear-gradient(90deg, #0f5bb7, #6ea5e7);
            transition: width 900ms linear;
        }

        #{$instanceId} .odata-cache-empty {
            font-size: 12px;
            color: #607287;
            padding: 8px 4px;
        }

        @media (max-width: 980px) {
            #{$instanceId} .odata-cache-widget {
                position: static;
                margin-bottom: 10px;
                width: fit-content;
            }

            #{$instanceId} .odata-cache-popout {
                position: fixed;
                top: 52px;
                right: 10px;
                left: 10px;
                width: auto;
                max-height: calc(100vh - 72px);
            }
        }

        {$customCss}
    </style>

    <div class="odata-cache-widget" id="{$instanceId}-widget">
        <span>{$labelHtml}:</span>
        <span class="odata-cache-value" id="{$instanceId}-bytes">0 bytes</span>
    </div>
    <div class="odata-cache-popout" id="{$instanceId}-popout" aria-hidden="true">
        <div class="odata-cache-popout-head">
            <span>{$titleHtml}</span>
            <div class="odata-cache-popout-head-actions">
                <button type="button" class="odata-cache-popout-clear" id="{$instanceId}-clear" aria-label="Verwijder volledige cache" title="Verwijder volledige cache">🗑</button>
                <button type="button" class="odata-cache-popout-close" id="{$instanceId}-close" aria-label="Sluiten">✕</button>
            </div>
        </div>
        <div class="odata-cache-popout-body" id="{$instanceId}-body"></div>
    </div>

    <script>
        (function ()
        {
            const statusUrl = {$statusUrlJs};
            const deleteUrl = {$deleteUrlJs};
            const clearUrl = {$clearUrlJs};
            const root = document.getElementById('{$instanceId}');
            if (!root)
            {
                return;
            }

            const widgetEl = document.getElementById('{$instanceId}-widget');
            const bytesEl = document.getElementById('{$instanceId}-bytes');
            const popoutEl = document.getElementById('{$instanceId}-popout');
            const popoutBodyEl = document.getElementById('{$instanceId}-body');
            const closeEl = document.getElementById('{$instanceId}-close');
            const clearEl = document.getElementById('{$instanceId}-clear');

            let lastCacheBytes = null;
            let displayedCacheBytes = 0;
            let cacheTargetBytes = 0;
            let cacheAnimFrameId = null;
            let cacheEntries = [];
            const deletingCacheIds = new Set();

            function escapeHtml(value)
            {
                return String(value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/\"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function setCacheGlow(className)
            {
                widgetEl.classList.remove('odata-cache-glow-up', 'odata-cache-glow-down');
                void widgetEl.offsetWidth;
                widgetEl.classList.add(className);
            }

            function renderCacheBytes(value)
            {
                const rounded = Math.max(0, Math.round(value));
                bytesEl.textContent = rounded.toLocaleString('nl-NL') + ' bytes';
            }

            function animateCacheBytes()
            {
                const delta = cacheTargetBytes - displayedCacheBytes;
                if (Math.abs(delta) < 0.5)
                {
                    displayedCacheBytes = cacheTargetBytes;
                    renderCacheBytes(displayedCacheBytes);
                    cacheAnimFrameId = null;
                    return;
                }

                displayedCacheBytes += delta * 0.18;
                renderCacheBytes(displayedCacheBytes);
                cacheAnimFrameId = requestAnimationFrame(animateCacheBytes);
            }

            function setCacheTarget(bytes)
            {
                cacheTargetBytes = Math.max(0, bytes);
                if (cacheAnimFrameId === null)
                {
                    cacheAnimFrameId = requestAnimationFrame(animateCacheBytes);
                }
            }

            function formatTimestamp(epochSeconds)
            {
                const value = Number(epochSeconds || 0);
                if (!Number.isFinite(value) || value <= 0)
                {
                    return '';
                }
                return new Date(value * 1000).toLocaleString('nl-NL');
            }

            function formatRemaining(seconds)
            {
                const safe = Math.max(0, Math.floor(seconds));
                const d = Math.floor(safe / 86400);
                const h = Math.floor((safe % 86400) / 3600);
                const m = Math.floor((safe % 3600) / 60);
                const s = safe % 60;
                if (d > 0)
                {
                    return d + 'd ' + h + 'u';
                }
                if (h > 0)
                {
                    return h + 'u ' + m + 'm';
                }
                return m + 'm ' + s + 's';
            }

            function normalizeProgress(cachedAt, expiresAt, nowSeconds)
            {
                const start = Number(cachedAt || 0);
                const end = Number(expiresAt || 0);
                if (!(end > start))
                {
                    return 0;
                }
                const t = (Number(nowSeconds) - start) / (end - start);
                return 1 - Math.max(0, Math.min(1, t));
            }

            function withQueryParam(url, key, value)
            {
                const base = String(url || '');
                const sep = base.indexOf('?') === -1 ? '?' : '&';
                return base + sep + encodeURIComponent(String(key)) + '=' + encodeURIComponent(String(value));
            }

            function getEntryId(entry)
            {
                return String((entry && entry.id) || '').trim();
            }

            function renderCachePopoutEntries()
            {
                if (!popoutBodyEl)
                {
                    return;
                }

                const nowSeconds = Math.floor(Date.now() / 1000);
                const visibleEntries = cacheEntries;

                if (visibleEntries.length === 0)
                {
                    popoutBodyEl.innerHTML = '<div class="odata-cache-empty">Geen actieve cachebestanden.</div>';
                    return;
                }

                let html = '';
                for (const entry of visibleEntries)
                {
                    const id = String(entry.id || '');
                    const isDeleting = deletingCacheIds.has(id);
                    const nameBase = String(entry.name || id || 'Onbekend');
                    const attributes = Array.isArray(entry.attributes) ? entry.attributes : [];
                    const attrTextRaw = attributes
                        .map(function (value)
                        {
                            return String(value || '').trim();
                        })
                        .filter(function (value)
                        {
                            return value !== '';
                        })
                        .join(', ');
                    const titleRaw = attrTextRaw !== '' ? (nameBase + ' — ' + attrTextRaw) : nameBase;

                    const url = String(entry.url || '');
                    const sizeBytes = Number(entry.size_bytes || 0);
                    const sizeLabel = Math.max(0, Math.round(sizeBytes)).toLocaleString('nl-NL') + ' bytes';

                    const progress = normalizeProgress(entry.cached_at, entry.expires_at, nowSeconds);
                    const progressPct = Math.max(0, Math.min(100, progress * 100));
                    const remaining = Number(entry.expires_at || 0) - nowSeconds;

                    const cachedAtText = formatTimestamp(entry.cached_at);
                    const expiresAtText = formatTimestamp(entry.expires_at);
                    const timerText = cachedAtText !== '' && expiresAtText !== ''
                        ? (cachedAtText + ' → ' + expiresAtText + ' (' + formatRemaining(remaining) + ')')
                        : 'verlooptijd onbekend';
                    const itemClass = 'odata-cache-item' + (isDeleting ? ' is-deleting' : '');
                    const deleteDisabled = isDeleting ? ' disabled' : '';

                    html += '<div class="' + itemClass + '">'
                        + '<div class="odata-cache-item-top">'
                        + '<div class="odata-cache-item-name" title="' + escapeHtml(titleRaw) + '">' + escapeHtml(titleRaw) + '</div>'
                        + '<div class="odata-cache-item-actions">'
                        + '<div class="odata-cache-item-size">' + escapeHtml(sizeLabel) + '</div>'
                        + '<button type="button" class="odata-cache-item-delete" data-cache-id="' + escapeHtml(id) + '" title="Verwijder cachebestand" aria-label="Verwijder cachebestand"' + deleteDisabled + '>🗑</button>'
                        + '</div>'
                        + '</div>'
                        + '<div class="odata-cache-item-url" title="' + escapeHtml(url !== '' ? url : '(url onbekend)') + '">' + (url !== '' ? escapeHtml(url) : '(url onbekend)') + '</div>'
                        + '<div class="odata-cache-item-timer">'
                        + '<span>🕒</span>'
                        + '<div class="odata-cache-item-bar"><div class="odata-cache-item-bar-fill" style="width:' + progressPct.toFixed(2) + '%"></div></div>'
                        + '<span>' + escapeHtml(timerText) + '</span>'
                        + '</div>'
                        + '</div>';
                }

                popoutBodyEl.innerHTML = html;
            }

            function setCacheEntries(entries)
            {
                cacheEntries = Array.isArray(entries) ? entries.slice() : [];

                const existingIds = new Set();
                for (const entry of cacheEntries)
                {
                    const id = getEntryId(entry);
                    if (id !== '')
                    {
                        existingIds.add(id);
                    }
                }

                Array.from(deletingCacheIds).forEach(function (id)
                {
                    if (!existingIds.has(id))
                    {
                        deletingCacheIds.delete(id);
                    }
                });

                renderCachePopoutEntries();
            }

            function closePopout()
            {
                popoutEl.classList.remove('open');
                popoutEl.setAttribute('aria-hidden', 'true');
            }

            async function deleteCacheEntry(cacheId, buttonEl)
            {
                const id = String(cacheId || '').trim();
                if (id === '')
                {
                    return;
                }

                deletingCacheIds.add(id);
                renderCachePopoutEntries();

                if (buttonEl)
                {
                    buttonEl.disabled = true;
                }

                try
                {
                    const body = new URLSearchParams();
                    body.set('id', id);

                    const requestUrl = withQueryParam(withQueryParam(deleteUrl, 'id', id), '_t', Date.now());

                    const response = await fetch(requestUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        body
                    });

                    if (!response.ok)
                    {
                        deletingCacheIds.delete(id);
                        await updateCacheWidget();
                        return;
                    }

                    await updateCacheWidget();
                    if (popoutEl.classList.contains('open'))
                    {
                        renderCachePopoutEntries();
                    }
                }
                catch (error)
                {
                    console.warn('Cachebestand verwijderen mislukt', error);
                    deletingCacheIds.delete(id);
                    await updateCacheWidget();
                }
                finally
                {
                    if (buttonEl)
                    {
                        buttonEl.disabled = false;
                    }
                }
            }

            async function clearCacheAll()
            {
                const message = 'Dit verwijderd de gehele cache. Wanneer u de pagina hierna opnieuw laad, kan dat lang duren. Weet u het zeker?';
                if (!window.confirm(message))
                {
                    return;
                }

                if (clearEl)
                {
                    clearEl.disabled = true;
                }

                try
                {
                    const response = await fetch(withQueryParam(clearUrl, '_t', Date.now()), {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store'
                    });

                    if (!response.ok)
                    {
                        await updateCacheWidget();
                        return;
                    }

                    deletingCacheIds.clear();
                    await updateCacheWidget();
                    if (popoutEl.classList.contains('open'))
                    {
                        renderCachePopoutEntries();
                    }
                }
                catch (error)
                {
                    console.warn('Volledige cache verwijderen mislukt', error);
                    await updateCacheWidget();
                }
                finally
                {
                    if (clearEl)
                    {
                        clearEl.disabled = false;
                    }
                }
            }

            async function updateCacheWidget()
            {
                try
                {
                let actualUrl = withQueryParam(statusUrl, '_t', Date.now());
                    const response = await fetch(actualUrl, {
                        headers: { 'Accept': 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        priority: 'high'
                    });

                    if (!response.ok)
                    {
                        return;
                    }

                    const raw = await response.text();
                    const trimmed = raw.trim();
                    if (trimmed === '')
                    {
                        return;
                    }

                    let payload = null;
                    try
                    {
                        payload = JSON.parse(trimmed);
                    }
                    catch (parseError)
                    {
                        console.warn('Cache-status bevat geen geldige JSON', parseError, trimmed.slice(0, 180));
                        return;
                    }

                    if (!payload || typeof payload !== 'object')
                    {
                        return;
                    }

                    const bytes = Number(payload.bytes || 0);
                    setCacheTarget(bytes);
                    setCacheEntries(payload.entries || []);

                    if (lastCacheBytes !== null)
                    {
                        if (bytes > lastCacheBytes)
                        {
                            setCacheGlow('odata-cache-glow-up');
                        }
                        else if (bytes < lastCacheBytes)
                        {
                            setCacheGlow('odata-cache-glow-down');
                        }
                    }

                    lastCacheBytes = bytes;
                }
                catch (error)
                {
                    console.warn('Cache-status laden mislukt', error);
                }
            }

            widgetEl.addEventListener('click', function (event)
            {
                event.stopPropagation();
                const isOpen = popoutEl.classList.toggle('open');
                popoutEl.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
                if (isOpen)
                {
                    renderCachePopoutEntries();
                }
            });

            if (closeEl)
            {
                closeEl.addEventListener('click', function (event)
                {
                    event.stopPropagation();
                    closePopout();
                });
            }

            if (clearEl)
            {
                clearEl.addEventListener('click', function (event)
                {
                    event.preventDefault();
                    event.stopPropagation();
                    clearCacheAll();
                });
            }

            if (popoutBodyEl)
            {
                popoutBodyEl.addEventListener('click', function (event)
                {
                    const target = event.target;
                    if (!(target instanceof Element))
                    {
                        return;
                    }

                    const deleteButton = target.closest('.odata-cache-item-delete');
                    if (!(deleteButton instanceof HTMLButtonElement))
                    {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    deleteCacheEntry(deleteButton.dataset.cacheId || '', deleteButton);
                });
            }

            document.addEventListener('click', function (event)
            {
                const target = event.target;
                if (!(target instanceof Node))
                {
                    return;
                }

                if (popoutEl.contains(target) || widgetEl.contains(target))
                {
                    return;
                }

                closePopout();
            });

            updateCacheWidget();
            setTimeout(updateCacheWidget, 150);
            setInterval(updateCacheWidget, 2000);
            setInterval(function ()
            {
                if (popoutEl.classList.contains('open'))
                {
                    renderCachePopoutEntries();
                }
            }, 1000);
        })();
    </script>
</div>
HTML;
}

$odataAction = (string) ($_GET['action'] ?? '');
if (odata_is_direct_request() && $odataAction === 'cache_status') {
    odata_send_cache_status_json();
}
if (odata_is_direct_request() && $odataAction === 'cache_delete') {
    odata_send_cache_delete_json();
}
if (odata_is_direct_request() && $odataAction === 'cache_clear') {
    odata_send_cache_clear_json();
}