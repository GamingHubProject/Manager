# Migration Strategy

## Objectives

- do not change or disable the current Gaming Hub Core installation;
- do not mutate Core installer tables;
- let Manager continue managing packages installed by Core;
- keep import repeatable and safe when either plugin is enabled first.

## Imported legacy data

When enabled in Settings, Manager checks for:

- `gaminghub_extension_sources`;
- `gaminghub_installed_extensions`;
- `gaminghub_extension_operations`;
- `storage/app/gaming-hub/extensions/backups`.

Records are copied only when their stable ID is not already present. Missing legacy tables are treated as an empty import. Malformed records and valid-looking package rows whose plugin directory is absent are skipped and reported as sanitized Manager warnings. Existing official Core sources are imported as trusted custom registry records so they cannot collide with Manager's own protected official source. Up to the most recent 1,000 legacy operation records are copied. Old backup files are copied into Manager storage and assigned a new Manager backup UUID after manifest and integrity validation.

## Filesystem discovery

Manager scans Azuriom's plugin directory and automatically reconciles:

- package directories named `gaming-hub-*`, including `gaming-hub-manager` for inventory reporting; or
- packages containing `gaming-hub-extension.json`; or
- packages already known in Manager metadata.

Unrelated Azuriom plugins are not automatically adopted.

## Core transition

This release does not modify Core. A future Core release can remove its installer routes, views, services, and permissions, then replace its **Extensions** navigation item with a route/link to Gaming Hub Manager. No Manager database or package format change is required for that transition.
