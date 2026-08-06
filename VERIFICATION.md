# Verification

Verification performed on 2026-08-06 against Gaming Hub Manager 0.1.2.

## Passed

- PHP syntax validation for all PHP, migration, route, test, configuration, and Blade files;
- JSON validation and matching `0.1.2` plugin/package-manifest versions;
- explicit checksum-file parsing for exact ZIP entries and exact ZIP sidecars;
- GitHub asset digest acceptance only for `sha256:<64 hex>` bound to the selected asset ID/name;
- malformed, unsupported, unbound, wrong-file, and mismatching downloaded checksums are rejected;
- registry-pinned checksums require exact selected version and asset name;
- no valid checksum source results in rejection rather than skipped verification;
- draft releases are ignored;
- prereleases are ignored on stable sources and considered only when the source permits them;
- the highest semantic release containing a matching uploaded ZIP asset is selected;
- source-code ZIP/TAR URLs are not used;
- stale `latest_version` values do not precede successful GitHub discovery;
- registries without `latest_version` remain valid;
- forced registry refresh invalidates GitHub release metadata caches;
- Core, Panel, and an arbitrary future package use the same generic discovery logic;
- GitHub tag, versioned ZIP filename, `plugin.json`, and package-manifest versions are checked before replacement;
- operation context records `checksum_source`, package asset ID/name, and checksum asset ID/name;
- the previous 0.1.1 alert, navigation, independence, legacy-import, and self-detection tests continue to pass;
- no Gaming Hub Core, Gaming Hub Panel, or Azuriom core file is modified.

## Focused test commands

```bash
python3 tests/verify_package.py
python3 tests/verify_view_contract.py
python3 tests/verify_release_pipeline.py
php tests/run-alert-normalizer.php
php tests/run-manifest-inspection.php
php tests/run-release-security.php
```

All focused test programs return `PASS`.

## Environment limitation

The build environment does not contain a complete running Azuriom installation or PHP `ext-zip`. Database-backed Eloquent behavior, browser requests, Azuriom PluginManager state changes, and a live GitHub package update were therefore not executed end to end. The release-selection, digest, checksum-verification, version-consistency, and static integration contracts were exercised directly. Perform the final update smoke test in the target Azuriom installation.
