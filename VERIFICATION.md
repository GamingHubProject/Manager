# Verification

M0.3 registry and legacy cleanup finalized on 2026-08-07 against Gaming Hub Manager 0.1.4 M0.2 Final.

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
