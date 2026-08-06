<?php

return [
    'official_registry_url' => env(
        'GAMING_HUB_MANAGER_OFFICIAL_REGISTRY_URL',
        'https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-registry/main/registry.json'
    ),
    'official_registry_fallback' => plugin_path('gaming-hub-manager/resources/registry/official.json'),
    'allow_private_hosts' => (bool) env('GAMING_HUB_MANAGER_ALLOW_PRIVATE_HOSTS', false),
    'registry_cache_ttl' => 300,
    'release_cache_ttl' => 300,
    'github_release_page_limit' => 10,
    'http_timeout' => 10,
    'download_timeout' => 60,
    'github_redirect_limit' => 5,
    'max_download_bytes' => 100 * 1024 * 1024,
    'max_extracted_bytes' => 300 * 1024 * 1024,
    'max_files' => 10000,
    'stale_staging_hours' => 24,
    'operation_log_retention_days' => 180,
    'retain_successful_update_backups' => true,
    'auto_import_legacy_core_metadata' => true,
];
