# Seshat

Timesheet-overzicht direct/indirect op basis van goedgekeurde urenstaten uit Business Central.

## Configuratie

Pas de werksoortlijsten handmatig aan:

- `web/seshat_productive_work_types.json` — directe werksoorten (groen in pie-chart)
- `web/seshat_leave_work_types.json` — verlof (blauw in pie-chart)
- `web/seshat_ignored_work_types.json` — volledig genegeerd (niet zichtbaar)

## Cache

Goedgekeurde timesheetregels worden permanent per week opgeslagen in `web/cache/seshat/`.
Verhoog `SESHAT_CACHE_VERSION` in `web/seshat_config.php` om oudere cachebestanden automatisch te negeren.


## Mímir (optioneel)

Zet in `web/auth.php` (niet in git). De Business Central-credentials blijven naast `$mimirApi` staan; die zijn de automatische fallback als Mímir uitvalt.

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';

$auth_list = [
    'Production' => ['mode' => 'basic', 'user' => '…', 'pass' => '…'],
];
$environment = 'Production';
$auth = $auth_list[$environment];
$baseUrl = 'https://example:7148/';
```

Met `$mimirApi` gezet proberen company-discovery en OData eerst Mímir. Faalt die aanroep (verbinding/timeout, non-2xx, ongeldige JSON of een Mímir-foutpayload), dan haalt Seshat dezelfde gegevens op via het directe Business Central-pad (`$baseUrl`, `$auth` / `$auth_list`, `$environment`, lokale odata-filecache) en slaat Mímir voor de rest van dat PHP-verzoek over. Dat geldt voor de webpagina (`index.php`) en voor CLI/cron die `odata_get_all` of `bc_fetch_rows` gebruiken; CLI houdt de lange timeout, webverzoeken ongeveer 90 seconden. Ontbreken de BC-credentials, dan komt de oorspronkelijke Mímir-fout terug. Zonder `$mimirApi` blijft het bestaande BC-pad ongewijzigd.

## Starten

De applicatie draait vanuit `web/` via `index.php`.
