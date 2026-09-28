<?php

/**
 * Includes/requires
 */
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/odata.php';

/**
 * Functies
 */

function bc_escape_odata_string(string $value): string
{
    return str_replace("'", "''", trim($value));
}

function bc_company_entity_url(string $baseUrl, string $environment, string $company, string $entitySet, array $query): string
{
    $safeCompany = bc_escape_odata_string($company);
    $companySegment = "Company('" . rawurlencode($safeCompany) . "')";
    $url = rtrim($baseUrl, '/') . '/' . rawurlencode($environment) . '/ODataV4/' . $companySegment . '/' . rawurlencode($entitySet);

    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    return $url;
}

function bc_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    $mimirFirst = odata_mimir_enabled()
        && !(function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open());
    if ($mimirFirst) {
        return odata_mimir_query($company, $entitySet, $query, $ttl === 0 ? 3600 : $ttl);
    }

    if (odata_mimir_enabled()
        && function_exists('odata_mimir_circuit_open')
        && odata_mimir_circuit_open()
        && !(function_exists('odata_bc_credentials_configured') && odata_bc_credentials_configured())
    ) {
        $previous = function_exists('odata_mimir_last_error') ? odata_mimir_last_error() : null;
        if ($previous instanceof Throwable) {
            throw $previous;
        }
        throw new Exception('Mímir mislukt.');
    }

    global $baseUrl;

    $environment = auth_get_environment_for_company($company, $ttl);
    $auth = auth_get_auth_for_environment($environment);
    $resolvedBase = is_string($baseUrl) ? $baseUrl : '';
    if (function_exists('odata_bc_base_url')) {
        $fromConfig = odata_bc_base_url();
        if (is_string($fromConfig) && $fromConfig !== '') {
            $resolvedBase = $fromConfig;
        }
    }
    $url = bc_company_entity_url($resolvedBase, $environment, $company, $entitySet, $query);

    return odata_get_all($url, $auth, $ttl);
}

function bc_try_fetch_rows(string $company, string $entitySet, array $query, int $ttl = 3600): array
{
    try {
        return bc_fetch_rows($company, $entitySet, $query, $ttl);
    } catch (Throwable $error) {
        return [];
    }
}

function bc_default_companies(): array
{
    return [
        'Koninklijke van Twist',
        'Hunter van Twist',
        'KVT Gas',
    ];
}

function bc_companies_for_page(int $ttl = 3600): array
{
    try {
        $mimirFirst = odata_mimir_enabled()
            && !(function_exists('odata_mimir_circuit_open') && odata_mimir_circuit_open());
        if ($mimirFirst) {
            $companies = odata_mimir_list_companies(null);
            // Vul demeter_* globals / map voor eventuele callers.
            try {
                auth_discover_companies_across_active_environments($ttl);
            } catch (Throwable $ignored) {
            }
            if ($companies !== []) {
                return $companies;
            }
        } else {
            $result = auth_discover_companies_across_active_environments($ttl);
            $companies = is_array($result['companies'] ?? null) ? $result['companies'] : [];
            if ($companies !== []) {
                return $companies;
            }
        }
    } catch (Throwable $ignored) {
    }

    return bc_default_companies();
}
