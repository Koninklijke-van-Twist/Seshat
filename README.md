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

Zet in `web/auth.php` (niet in git):

```php
$mimirApi  = 'mimir_…';
// optioneel:
$mimirBase = 'https://sleutels.kvt.nl/mimir/api';
```

Met `$mimirApi` gezet zijn `$auth_list`, `$environment`, `$baseUrl` en `$auth` ongebruikt voor Business Central — company-discovery en OData lopen via Mímir. Zonder `$mimirApi` blijft het bestaande BC-pad ongewijzigd.

## Starten

De applicatie draait vanuit `web/` via `index.php`.
