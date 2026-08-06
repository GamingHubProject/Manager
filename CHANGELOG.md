# Changelog

## 0.1.2 - 2026-08-06

- Made published GitHub Releases authoritative for GitHub-backed registry packages; deprecated `latest_version` is now optional and used only as a temporary fallback when GitHub discovery is unavailable.
- Added semantic release selection across published releases, with draft filtering, stable/prerelease channel enforcement, exact ZIP asset-pattern matching, and source-code archive exclusion.
- Added SHA-256 checksum priority: explicit checksum asset, selected GitHub release-asset digest, exact version/asset registry pin, then rejection.
- Added strict GitHub `digest` parsing for `sha256:<64 hex>`, exact selected asset identity binding, and operation-log `checksum_source` metadata.
- Added tag, versioned asset filename, `plugin.json`, and optional `gaming-hub-extension.json` version-consistency validation before installation or replacement.
- Registry refresh now invalidates cached GitHub release metadata so newly published releases are discovered without editing registry JSON.
- Added focused checksum, release-selection, cache-invalidation, generic-package, and version-consistency tests.

## 0.1.1 - 2026-08-06

- Fixed the Laravel reserved `$errors` collision that caused Overview, Installed Packages, Available Packages, Registries, and Install Logs to return HTTP 500.
- Added type-safe normalization for Laravel validation errors, Manager alert arrays/collections, structured alerts, and success/warning/error flash messages.
- Renamed catalog and legacy-import domain failures to `managerAlerts` and `warnings`; operational failures now use explicit error flash messages rather than the validation bag.
- Removed the duplicate horizontal Manager tab bar and retained Azuriom's supported admin sidebar navigation as the sole primary navigation.
- Removed duplicate page headings while preserving page titles, breadcrumbs, and contextual actions.
- Added safe installed-package reporting for Gaming Hub Manager itself while preserving self-update, self-reinstall, self-disable, backup, rollback, and uninstall protection.
- Hardened legacy import against missing tables and malformed or stale records without requiring Gaming Hub Core.
- Added focused alert, view-contract, navigation, independence, self-detection, and legacy-package compatibility tests.

## 0.1.0 - 2026-08-06

- Added standalone Gaming Hub package lifecycle manager.
- Added official registry with bundled bootstrap fallback and custom registries.
- Added direct GitHub Release sources and configurable ZIP/checksum asset patterns.
- Added install, update, reinstall, enable, disable, uninstall, backup, rollback, and integrity verification.
- Added transactional file replacement, recovery backups, staged cleanup, and operation logs.
- Added official/trusted/untrusted source model and explicit warning/confirmation flow.
- Added non-destructive import of Gaming Hub Core installer sources, installed-package metadata, operation history, and backups.
- Added automatic discovery of existing Gaming Hub package directories.
- Added Manager self-protection and independent tables, configuration, routes, storage, permissions, and navigation.
