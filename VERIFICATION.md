# Verification — Gaming Hub Manager 0.1.5

Gaming Hub Manager 0.1.5 finalizes M0.4 database resilience and adds M0.5 package-lifecycle verification. Milestone labels are development labels only; the installable plugin version is exactly `0.1.5`.

## M0.5 release gate

Run from the plugin root:

```bash
php tests/run-m05-filesystem-lifecycle.php
python3 tests/verify_m05_lifecycle.py
php tests/run-m04-schema-health.php
php tests/run-m04-schema-exception-policy.php
php tests/run-m04-runtime-guard.php
python3 tests/verify_m04_database_resilience.py
php tests/run-m03-registry-policy.php
python3 tests/verify_m03_registry_cleanup.py
python3 tests/verify_clean_install.py
php tests/run-manifest-inspection.php
php tests/run-m02-dependency-graph.php
python3 tests/verify_m02_package_state.py
python3 tests/verify_dependency_resolution.py
python3 tests/verify_package.py
python3 tests/verify_release_pipeline.py
python3 tests/verify_view_contract.py
php tests/run-release-security.php
php tests/run-alert-normalizer.php
php tests/run-dependency-resolution.php
```

`tests/run-dependency-resolution.php` requires the real Composer `composer/semver` autoloader. In a standalone source workspace without Composer dependencies it must report `SKIP`, not `PASS`.

The standalone M0.5 behavioral harness executes production filesystem/manifest/integrity/release primitives and restore ordering. Full install/update/reinstall/enable/disable/backup/uninstall transactions require an Azuriom/Laravel/Eloquent runtime and must not be reported as runtime-passed unless they were actually executed.

## Real Azuriom lifecycle verification

In a disposable Azuriom v1.2.x installation, verify Manager 0.1.5 schema health, the controlled missing-operations-table recovery state, Panel-without-Core rejection, Core then immediate Panel installation, dependency protection, enable/disable, reinstall, update, backup/restore, uninstall, and persistence after application/container restart. Do not perform missing-table tests against production data.

---

## Retained M0.4 verification notes

## M0.4 database resilience contracts

- Manager schema health is classified as `READY`, `MIGRATIONS_PENDING`, `SCHEMA_INCONSISTENT`, or `DATABASE_UNAVAILABLE`;
- physical existence of all five Manager tables is checked before any Manager model/runtime preparation;
- Laravel's migration repository is consulted to distinguish ordinary pending migrations from migration-history/table divergence;
- a recorded Manager migration whose table is missing is `SCHEMA_INCONSISTENT`, including the historical `gaminghub_manager_operations` failure;
- a Manager table that exists without its expected migration record is also reported as inconsistent when migration history is available;
- inconsistent state is logged through Laravel's normal application logger using table/migration names only;
- no migration rows are deleted and no table is created, dropped, reset, or otherwise repaired automatically;
- Manager runtime returns before settings loading, operation cleanup, registry initialization, legacy import, filesystem/DB reconciliation, staging cleanup, or log pruning whenever schema health is not ready;
- every current Manager admin read/mutation controller establishes runtime readiness before Manager table access or lifecycle work;
- the existing migration-required page remains DB-independent and now shows schema state, missing tables, pending migrations, and detected history divergence without secrets or stack traces;
- mutation redirects use state-aware recovery guidance rather than claiming every failure is a normal pending migration;
- M0.2 package-state/lifecycle behavior and M0.3 registry/legacy behavior remain unchanged.

## M0.4 verification commands

```bash
php tests/run-m04-schema-health.php
php tests/run-m04-schema-exception-policy.php
php tests/run-m04-runtime-guard.php
python3 tests/verify_m04_database_resilience.py
python3 tests/verify_m03_registry_cleanup.py
python3 tests/verify_clean_install.py
php tests/run-m03-registry-policy.php
python3 tests/verify_m02_package_state.py
php tests/run-manifest-inspection.php
php tests/run-m02-dependency-graph.php
python3 tests/verify_dependency_resolution.py
python3 tests/verify_package.py
python3 tests/verify_view_contract.py
python3 tests/verify_release_pipeline.py
php tests/run-alert-normalizer.php
php tests/run-release-security.php
```

`php tests/run-dependency-resolution.php` remains the standalone Composer-SemVer runner. If no Composer autoloader is present it reports `SKIP`; that is not a runtime pass.

## Real Azuriom integration verification

The standalone M0.4 tests execute the production schema classifier and the production `ManagerRuntime` early-stop path, but they do not boot Azuriom or intentionally mutate a real database. In a disposable Azuriom v1.2.x environment, additionally verify a healthy installation, then remove only `gaminghub_manager_operations` while leaving its migration record present and confirm Manager renders the controlled `SCHEMA_INCONSISTENT` recovery page without HTTP 500 or raw `SQLSTATE[42P01]`. Restore the disposable database afterward.

Do not report those HTTP/database integration steps as passed unless they were actually executed in a booted Azuriom installation.

---

## M0.3 registry contracts

- the protected built-in registry is exactly `GamingHubProject Official Registry`;
- its canonical URL is exactly `https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json`;
- the official source no longer accepts an environment override that can silently replace the canonical built-in URL;
- bundled fallback and example registry data use the GamingHubProject organization and current Registry repository;
- clean Manager initialization does not import Core registry data unless concrete non-empty historical metadata or a validated historical backup is present;
- historical official Core sources are suppressed during migration because Manager now owns the canonical official registry;
- genuine legacy custom sources remain eligible for non-destructive migration;
- previously stored Manager-owned official rows and the exact historical Core-import official artifact shape are reconciled without deleting arbitrary administrator registries;
- repeated initialization converges on one protected Manager official source and does not recreate a suppressed historical official import;
- a new custom registry cannot be added when its URL normalizes to the canonical built-in official URL;
- M0.2 filesystem authority, canonical IDs, Composer SemVer, dependency graph, lifecycle protection, state restoration, and immediate package refresh behavior remain unchanged.
- M0.2 still uses normal Composer caret semantics: `^0.6.0` means `>=0.6.0 <0.7.0`, so Core `0.7.0` does **not** satisfy `^0.6.0`; support for both 0.6.x and 0.7.x requires an explicitly broader constraint.

Historical owner/repository literals required solely to identify real upgrades are isolated in `src/Services/LegacyRegistryPolicy.php` and its focused migration regression test. They are not defaults, fallbacks, bundled sources, discovery repositories, or current documentation.

## M0.3 verification commands

These source-workspace checks must return `PASS`:

```bash
python3 tests/verify_m03_registry_cleanup.py
python3 tests/verify_clean_install.py
php tests/run-m03-registry-policy.php
python3 tests/verify_m02_package_state.py
php tests/run-manifest-inspection.php
php tests/run-m02-dependency-graph.php
python3 tests/verify_dependency_resolution.py
python3 tests/verify_package.py
python3 tests/verify_view_contract.py
python3 tests/verify_release_pipeline.py
php tests/run-alert-normalizer.php
php tests/run-release-security.php
```

Run the executable Composer-SemVer check separately:

```bash
php tests/run-dependency-resolution.php
```

If the standalone workspace has no Composer autoloader containing `composer/semver`, the SemVer runner reports `SKIP`; that is not a runtime pass. A real Azuriom installation may be supplied with `GAMING_HUB_TEST_AUTOLOAD=/path/to/vendor/autoload.php`.

## Real Azuriom integration verification

The pure registry policy test executes current/legacy URL normalization and exact migration ownership classification directly. Database-backed initialization, Eloquent source reconciliation, and Core installation effects still require a real Azuriom v1.2.x runtime.

In a real clean installation verify:

1. install Manager into clean Azuriom;
2. open **Registries** and confirm exactly one GamingHubProject official registry at the canonical raw URL;
3. confirm there is no Core-imported historical official source;
4. revisit/re-run initialization and confirm no duplicate appears;
5. install Core and confirm the Manager registry list remains unchanged.

Do not report those runtime steps as passed unless they were actually executed in a booted Azuriom installation.