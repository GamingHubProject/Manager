#!/usr/bin/env python3
"""M0.2 package-state/dependency correctness source contracts.

These focused checks supplement runtime lifecycle tests. They deliberately verify
that the code paths implementing the M0.2 invariants remain wired to filesystem
reconciliation, exact identity and Composer SemVer rather than registry/DB guesses.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors: list[str] = []
passed: list[str] = []


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def check(label: str, condition: bool) -> None:
    if condition:
        passed.append(label)
    else:
        errors.append(label)


composer = json.loads(text("composer.json"))
resolver = text("src/Services/InstalledExtensionResolver.php")
validator = text("src/Services/ExtensionManifestValidator.php")
archive = text("src/Services/ExtensionArchiveInspector.php") if (ROOT / "src/Services/ExtensionArchiveInspector.php").exists() else ""
policy = text("src/Services/ExtensionVersionPolicy.php")
guard = text("src/Services/ExtensionDependencyGuard.php")
installer = text("src/Services/ExtensionInstaller.php")
lifecycle = text("src/Services/AzuriomPluginLifecycle.php")
catalog = text("src/Services/PackageCatalog.php")
actions = text("src/Controllers/Admin/PackageActionController.php")
semver_test = text("tests/run-dependency-resolution.php")
graph_test = text("tests/run-m02-dependency-graph.php")
verification = text("VERIFICATION.md")
changelog = text("CHANGELOG.md")

# 1-5 Installed-state authority.
check("01 stale DB + missing directory is removed", "$record?->delete();" in resolver and "Installed package files were not found." in resolver)
check("02 valid filesystem plugin is discovered without Manager DB metadata", "Then discover every valid Azuriom plugin directory" in resolver and "$this->resolve($entry, true, false);" in resolver)
check("03 manifest version overwrites stale stored version", "'installed_version' => $manifest->version" in resolver)
check("04 registry version never becomes installed version", "latest_version" not in resolver and "installed_version' => $manifest->version" in resolver)
check("05 registry data alone cannot create installed state", "scandir($this->paths->pluginsRoot())" in resolver and "is_file($path.'/plugin.json')" in resolver)

# 6-9 Canonical identity.
check("06 registry ID mismatch is rejected", "'registry' => $registryMetadata['id'] ?? null" in validator and "Package identity mismatch:" in validator)
check("07 plugin.json and Gaming Hub manifest IDs must match", "'plugin.json' => $plugin['id'] ?? null" in validator and "'gaming-hub-extension.json' => $manifest['id'] ?? null" in validator)
check("08 directory must exactly match canonical ID", "Installed package ID does not match its directory" in resolver and ("Archive root does not match the plugin identifier." in archive if archive else True))
identity_block = validator[validator.find("private function canonicalId"):validator.find("private function requirements")]
check("09 aliases/case/underscore guessing are absent", "strtolower(" not in identity_block and "str_replace('_', '-'" not in identity_block and "strcasecmp(" not in identity_block)

# 10-17 Composer SemVer only.
check("10 composer/semver is a runtime require", composer.get("require", {}).get("composer/semver") is not None and "composer/semver" not in composer.get("suggest", {}))
check("11 policy directly calls Composer Semver::satisfies", "Semver::satisfies(" in policy)
check("12 custom fallback SemVer parser removed", "class_exists(\\Composer\\Semver" not in policy and "preg_match('/^\\^" not in policy)
check("13 Core-specific widening removed", "$dependencyId === 'gaming-hub-core'" not in policy and "'1.0.0', '<'" not in policy)
check("14 ^0.6 regression expects 0.7.0 false", "['0.7.0', '^0.6.0', false]" in semver_test)
check("15 tilde/exact/prerelease cases exist", "'~0.6.0'" in semver_test and "'0.6.0-beta.1'" in semver_test and "['0.6.1', '0.6.1', true]" in semver_test)
check("16 Composer OR expression is covered", "^0.6.0 || ^0.8.0" in semver_test)
check("17 package-specific SemVer policy is removed", "satisfiesPackageDependency" not in policy)

# 18-22 Dependency lookup + reverse graph.
check("18 missing mandatory Gaming Hub dependency is blocked", "assertDependencySatisfied" in guard and "installed === null" in guard)
check("19 mandatory Azuriom dependency participates", "($plugin['dependencies'] ?? [])" in guard and "'azuriom'" in guard)
check("20 manually installed plugins are scanned from filesystem", "scandir($this->paths->pluginsRoot())" in guard and "readManifest($path, $entry)" in guard)
check("21 direct reverse dependents are built from actual package map", "directDependentsFrom" in guard and "dependencies'][$extensionId]" in guard)
check("22 transitive reverse dependents use graph traversal", "while ($queue !== [])" in guard and "$queue[] = [$dependent['id'], $depth + 1]" in guard)

# 23-28 Enabled-state preservation.
check("23 update snapshots target enabled/disabled state", "enabledStateSnapshot($extensionId)" in installer and "target']['enabled']" in installer)
check("24 enabled target is restored", "restoreTargetState" in installer and "assertEnableAllowed($extensionId)" in installer)
check("25 direct enabled dependents are restored", "restoreDependentStates" in installer and "restore_order" in installer)
check("26 transitive dependents disable/restore in safe order", "disable_order" in installer and "restore_order" in installer and "enabledStateSnapshot" in guard)
check("27 previously disabled dependent is not enabled", "elseif ($this->lifecycle->isEnabled($dependentId))" in installer and "Previously disabled dependent package" in installer)
check("28 restoration failure prevents false success", "restoration_failure_plugin" in installer and "throw new ExtensionOperationFailed" in installer and "with dependent state restored" in installer)

# 29-34 Consecutive operations / one policy across lifecycle.
install_reconcile = installer.find("$this->installed->reconcileFilesystem();")
prepare = installer.find("private function preparePackage")
prepare_reconcile = installer.find("$this->installed->reconcileFilesystem();", prepare)
check("29 clean install reconciles before already-installed decision", 0 <= install_reconcile < installer.find("if ($expectedExtensionId", install_reconcile))
check("30 successful Core install persists/reconciles before returning", "InstalledExtension::updateOrCreate" in installer and "return $record;" in installer and "resolve($manifest->id, true, false)" in installer)
check("31 immediate Panel validation reconciles filesystem first", prepare_reconcile > prepare and installer.find("assertCandidateDependencies", prepare_reconcile) > prepare_reconcile)
check("32 Panel sees actual manifest version rather than registry", "'installed_version' => $manifest->version" in resolver and "latest_version" not in guard)
check("33 update and reinstall share the same dependency-aware update path", "$allowSameVersion ? 'reinstall' : 'update'" in installer and "$this->dependencies->assertUpdateAllowed($manifest);" in installer)
check("34 enable/disable/uninstall policy uses dependency graph/native Azuriom behavior", "assertEnableAllowed" in actions and "enabledStateSnapshot" in actions and "assertRequirementsSatisfied" in lifecycle and "assertUninstallAllowed" in guard)

# Additional phase-boundary/identity assertions.
check("35 optional Azuriom dependencies remain optional", "str_ends_with($dependencyId, '?')" in guard and "$optional ||" in guard)
check("36 direct-source identity is not inferred from repository URL", "normalizeRepository" not in catalog and "firstWhere('source_id', $source->source_id)" in catalog)
check("37 disable does not report simple success without handling enabled dependents", "Disabling this package also requires disabling enabled dependents" in actions and "disable_order" in actions)
check("38 rollback restores dependency enabled snapshot", "restoreTargetState($snapshot" in installer and "restoreDependentStates($snapshot" in installer and "rolling_back" in installer)

# M0.2 finalization: executable behavior + release consistency.
check("39 executable production dependency graph traversal test exists", "getMethod('dependentsFrom')" in graph_test and "getMethod('directDependentsFrom')" in graph_test)
check("40 executable graph test covers transitive and disabled dependents", "'addon' => 3" in graph_test and "disabled-child" in graph_test and "=== false" in graph_test)
check("41 M0.2 docs state normal Composer caret semantics", "^0.6.0` means `>=0.6.0 <0.7.0" in verification and "Core `0.7.0` does **not** satisfy `^0.6.0`" in verification)
check("42 changelog contains no Core-specific SemVer widening claim", "pre-1.0 compatibility rule" not in changelog and "0.7.0` satisfies a `^0.6.0" not in changelog)
check("43 verification separates deferred M0.3 clean-install checks", "M0.3 checks intentionally pending" in verification and "not** part of the M0.2 pass gate" in verification)

if errors:
    print(f"FAILED: {len(errors)} contract(s) failed; {len(passed)} passed")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print(f"PASS: {len(passed)} M0.2 installed-state, identity, SemVer, dependency-graph and lifecycle contracts")
