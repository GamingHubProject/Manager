# Verification

M0.2 verification finalized on 2026-08-07 against Gaming Hub Manager 0.1.4.

## M0.2 passed contracts

- canonical package identity remains exact across `plugin.json`, `gaming-hub-extension.json`, registry metadata, installed directory names, and dependency declarations;
- installed-package state is filesystem-authoritative and Manager metadata is reconciled from the installed manifests before dependency-sensitive decisions;
- registry release metadata cannot by itself classify a package as installed or satisfy an installed dependency;
- dependency compatibility uses `Composer\Semver\Semver::satisfies()` only; there is no package-specific Gaming Hub Core comparator and no custom fallback parser;
- Composer caret semantics are preserved: `^0.6.0` means `>=0.6.0 <0.7.0`, therefore Core `0.7.0` does **not** satisfy `^0.6.0`;
- packages supporting both Core 0.6.x and Core 0.7.x must publish an explicitly broader constraint, for example `>=0.6.0 <0.8.0` or `^0.6.0 || ^0.7.0`;
- mandatory Gaming Hub dependencies and mandatory Azuriom `plugin.json` dependencies participate in the reverse dependency graph, including manually installed filesystem packages;
- Azuriom optional `dependency?` declarations remain optional;
- direct and transitive reverse dependents are traversed in deterministic depth/ID order;
- update/reinstall keeps the existing target/dependent enabled-state snapshot, disables dependents deepest-first, restores the target first when previously enabled, then restores dependents outward; previously disabled packages are not intentionally enabled;
- failed dependency/restoration operations do not report false success and retain detailed operation diagnostics;
- successful install writes and reconciles installed metadata before returning so the next lifecycle request can resolve the actual installed package immediately;
- existing checksum, release discovery, archive, version consistency, alert, navigation, independence, self-protection, filesystem-authority, schema-readiness, and migration-safety contracts remain unchanged by this M0.2 finalization;
- no Gaming Hub Core, Gaming Hub Panel, Azuriom core file, registry URL, or deferred legacy registry/documentation reference is changed by this finalization.

## M0.2 verification commands

The following source-workspace M0.2 checks must return `PASS`:

```bash
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

Run the Composer-SemVer behavioral check separately:

```bash
php tests/run-dependency-resolution.php
```

`tests/run-dependency-resolution.php` is an executable Composer-SemVer behavioral test. It requires a Composer autoloader containing `composer/semver` (as a normal Azuriom installation does). If no such autoloader exists in a standalone source workspace, it reports `SKIP`; that skip is not evidence that SemVer behavior executed. You can point it at a real Composer autoloader with `GAMING_HUB_TEST_AUTOLOAD=/path/to/vendor/autoload.php php tests/run-dependency-resolution.php`. For release verification, run it in an environment where `composer/semver` is actually available and record the result separately.

`tests/run-manifest-inspection.php` executes the production `ExtensionManifestValidator` directly for canonical-ID acceptance/rejection. `tests/run-m02-dependency-graph.php` executes the production dependency graph traversal with an in-memory package graph through reflection; it does not mock Eloquent or Azuriom lifecycle state.

## M0.3 checks intentionally pending

`python3 tests/verify_clean_install.py` currently mixes clean-install/filesystem-authority contracts with legacy owner/registry documentation cleanup checks. The latter are M0.3 work.

During M0.2 finalization this script is therefore **not** part of the M0.2 pass gate. When run now, it is expected to report the remaining legacy owner / old-registry documentation references that are intentionally deferred to M0.3. Do not interpret that expected M0.3 failure as an M0.2 lifecycle regression, and do not claim the complete repository suite passes while those future-phase assertions remain.

The M0.2-relevant clean-install behavior remains covered by `tests/verify_m02_package_state.py` and the unchanged filesystem/schema contracts. M0.3 will own removal of the deferred legacy references and will make the legacy-reference assertions in `verify_clean_install.py` eligible for the full release gate again.

## Integration verification still required in a real Azuriom runtime

Standalone verification cannot execute the Eloquent-backed installed-package map, real plugin-directory reconciliation inside a booted Laravel application, or Azuriom `PluginManager` enable/disable transitions end to end without a real Azuriom runtime.

In a real Azuriom v1.2.x installation, additionally verify:

1. install Gaming Hub Manager;
2. install Gaming Hub Core;
3. without manual refresh, immediately install Gaming Hub Panel;
4. record the exact Core constraint declared by the Panel package being installed;
5. record Core's actual installed manifest version;
6. record the resulting `Composer\Semver\Semver::satisfies(installedCoreVersion, declaredConstraint)` decision;
7. confirm Manager accepts or rejects the Panel operation consistently with that Composer decision.

Do not report this scenario as passed unless those real runtime steps were actually executed.
