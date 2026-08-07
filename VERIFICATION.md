# Verification

Verification performed on 2026-08-07 against Gaming Hub Manager 0.1.4.

## Passed

- canonical dependency ID remains exactly `gaming-hub-core` from package manifest through installed metadata and resolver lookup;
- installed dependency metadata is reconciled from the filesystem immediately before validation, with no manual refresh required;
- Core installed version is read from `gaminghub_manager_packages.installed_version`, not registry release metadata;
- Gaming Hub Core `0.7.0` satisfies the Manager package-dependency requirement `^0.6.0`;
- the special pre-1.0 Core rule does not alter the base Composer-compatible comparator or unrelated package dependency behavior;
- failed dependency validation includes requested package ID, installed package IDs and versions, constraint, installed version, and exact comparison result in the operation failure message;
- successful install metadata is persisted before `install()` returns, and no deferred package persistence or surrounding Manager transaction delays visibility to the next request;
- PHP syntax validation for all PHP, migration, route, test, configuration, and Blade files;
- JSON validation and matching `0.1.4` plugin/package-manifest versions;
- exact default official registry name and `GamingHubProject/Registry` raw URL;
- bundled and example registries use only `GamingHubProject` repositories;
- clean-install legacy import is gated before throttle metadata or import side effects;
- absent and empty legacy tables produce a silent no-import result;
- validated real legacy metadata remains importable and the import remains idempotent by stable IDs;
- installed-package reconciliation validates directory presence, `plugin.json`, package ID, and installed version before catalog state is computed;
- stale enabled database rows are deleted when package files are missing or invalid;
- registry metadata cannot classify an absent package as installed or updateable;
- Manager schema readiness checks cover sources, packages, operations, backups, and settings tables;
- PostgreSQL SQLSTATE `42P01` is handled as a missing-table condition while unrelated schema exceptions are rethrown;
- all administration entry points check readiness before Manager model queries;
- implicit Manager Eloquent route binding was removed so missing tables are not queried during route binding;
- incomplete migrations render a safe administrative warning;
- existing checksum, release discovery, archive, version consistency, alert, navigation, independence, and self-protection contracts continue to pass;
- no Gaming Hub Core, Gaming Hub Panel, or Azuriom core file is modified.

## Focused test commands

```bash
php tests/run-dependency-resolution.php
python3 tests/verify_dependency_resolution.py
python3 tests/verify_package.py
python3 tests/verify_view_contract.py
python3 tests/verify_release_pipeline.py
php tests/run-alert-normalizer.php
php tests/run-manifest-inspection.php
php tests/run-release-security.php
```

All dependency-resolution and existing functional focused tests listed above return `PASS`.

## Pre-existing out-of-scope test finding

`python3 tests/verify_clean_install.py` still reports two documentation-only legacy references in `INSTALL.md` from the attached 0.1.3 source. They are unrelated to dependency resolution and were intentionally not changed in this dependency-only patch. The clean-install runtime guards exercised by the dependency fix are unchanged.

## Environment limitation

The build environment does not contain a complete running Azuriom installation or a PostgreSQL-backed Azuriom test database. Database-backed Eloquent reconciliation, browser requests, migration execution, and Azuriom PluginManager state changes were therefore not executed end to end here. PHP syntax, JSON, standalone behavioral tests, and focused static integration contracts were exercised directly. Perform the final clean-install smoke test in the target Azuriom v1.2.x/PostgreSQL installation.
