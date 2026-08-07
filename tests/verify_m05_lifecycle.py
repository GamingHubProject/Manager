#!/usr/bin/env python3
"""M0.5 lifecycle contracts complementing executable standalone lifecycle primitives.

This file deliberately does not claim to boot Azuriom/Eloquent. It verifies the
production orchestration and phase invariants that cannot execute without the
Azuriom Composer runtime, while tests/run-m05-filesystem-lifecycle.php executes
filesystem/manifest/integrity/restore-order behavior directly.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors: list[str] = []
checks = 0


def require(condition: bool, message: str) -> None:
    global checks
    checks += 1
    if not condition:
        errors.append(message)


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def method_body(source: str, name: str) -> str:
    match = re.search(rf"(?:public|private) function {re.escape(name)}\s*\([^)]*\)[^{{]*\{{", source)
    if not match:
        return ""
    start = match.end() - 1
    depth = 0
    for pos in range(start, len(source)):
        if source[pos] == "{":
            depth += 1
        elif source[pos] == "}":
            depth -= 1
            if depth == 0:
                return source[start + 1 : pos]
    return ""


def ordered(body: str, markers: list[str], label: str) -> None:
    positions: list[int] = []
    cursor = 0
    missing: list[str] = []
    for marker in markers:
        pos = body.find(marker, cursor)
        positions.append(pos)
        if pos < 0:
            missing.append(marker)
            continue
        cursor = pos + len(marker)
    require(not missing, f"{label}: lifecycle markers missing: {missing}")
    if not missing:
        require(positions == sorted(positions), f"{label}: lifecycle order is incorrect")


plugin = json.loads(text("plugin.json"))
manifest = json.loads(text("gaming-hub-extension.json"))
require(plugin.get("id") == "gaming-hub-manager", "Manager plugin ID changed")
require(plugin.get("version") == "0.1.5", "Manager plugin version must be 0.1.5")
require(manifest.get("version") == "0.1.5", "Manager package manifest version must be 0.1.5")
require(plugin.get("version") == manifest.get("version"), "plugin/package manifest version mismatch")
require(manifest.get("repository") == "https://github.com/GamingHubProject/Manager", "Manager package repository metadata is not the real repository")
require(manifest.get("package", {}).get("plugin_directory") == "gaming-hub-manager", "Manager package directory metadata changed")

# Current official Core/Panel relationship used by lifecycle verification.
registry = json.loads(text("resources/registry/official.json"))
entries = {entry["id"]: entry for entry in registry.get("extensions", [])}
require(set(entries) == {"gaming-hub-core", "gaming-hub-panel"}, "official registry must contain exactly Core and Panel")
require(entries.get("gaming-hub-core", {}).get("repository") == "https://github.com/GamingHubProject/Core", "Core repository mapping regressed")
require(entries.get("gaming-hub-panel", {}).get("repository") == "https://github.com/GamingHubProject/Panel", "Panel repository mapping regressed")
require(entries.get("gaming-hub-panel", {}).get("requires", {}).get("gaming-hub-core") == ">=0.7.0", "Panel/Core official dependency fixture changed")

installer = text("src/Services/ExtensionInstaller.php")
install = method_body(installer, "install")
update = method_body(installer, "update")
prepare = method_body(installer, "preparePackage")
rollback_update = method_body(installer, "rollbackUpdate")
require(install != "", "install method missing")
require(update != "", "update method missing")
require(prepare != "", "preparePackage method missing")

# Install: filesystem reconciliation -> candidate validation -> atomic move -> migrate ->
# optional enable -> metadata -> immediate refresh/reconciliation/resolve.
ordered(install, [
    "$this->installed->reconcileFilesystem();",
    "$this->preparePackage(",
    "rename($incomingSwap, $live)",
    "$this->lifecycle->migrate($manifest->id);",
    "InstalledExtension::updateOrCreate(",
    "$this->lifecycle->refresh();",
    "$this->installed->reconcileFilesystem();",
    "$this->installed->resolve($manifest->id, true, false);",
], "install")
require("assertManifestEnableAllowed($manifest)" in install, "install enable path does not revalidate dependencies against installed filesystem")
require("Package identity mismatch: downloaded manifest ID" in install, "selected package/downloaded manifest ID mismatch is not blocked")
require("Removing the partial installation" in install and "deleteExtension" in install, "failed install does not remove partial installation")
require("$record?->delete();" in install, "failed install does not remove partial Manager metadata")

# Package validation must finish before install/update mutation.
ordered(prepare, [
    "$this->http->download",
    "$this->checksums->verify",
    "$this->archives->inspect",
    "$this->releaseVersions->assertConsistent",
    "$this->installed->reconcileFilesystem();",
    "$this->compatibility->assertCompatible",
    "$this->dependencies->assertCandidateDependencies",
    "$this->paths->assertStagedDirectory",
], "preparePackage")
require("Invalid ZIP archive" in text("src/Services/ExtensionArchiveInspector.php"), "invalid ZIP rejection missing")
for phrase in (
    "Unsafe archive path",
    "Symlinks are not allowed",
    "Package archive must contain exactly one root directory",
    "Missing plugin.json",
    "Archive root does not match the plugin identifier",
):
    require(phrase in text("src/Services/ExtensionArchiveInspector.php"), f"archive safety check missing: {phrase}")

# Update/reinstall orchestration and rollback.
ordered(update, [
    "$this->installed->reconcileFilesystem();",
    "$this->installed->resolve($extensionId, true, false);",
    "$this->dependencies->enabledStateSnapshot($extensionId);",
    "$this->preparePackage(",
    "$this->dependencies->assertUpdateAllowed($manifest);",
    "$this->paths->copyDirectory($live, $backupPath);",
    "$this->disableDependents($enabledSnapshot, $operation);",
    "$this->lifecycle->disable($extensionId)",
    "rename($live, $previousSwap)",
    "rename($incomingSwap, $live)",
    "$this->lifecycle->migrate($extensionId);",
    "$this->restoreTargetState($enabledSnapshot, $operation, $manifest);",
    "$this->restoreDependentStates($enabledSnapshot, $operation);",
    "$this->installed->reconcileFilesystem();",
    "$this->installed->resolve($extensionId, true, false);",
], "update/reinstall")
require("$comparison === 0 && ! $allowSameVersion" in update, "reinstall does not explicitly allow same-version replacement")
require("Package downgrades require a verified backup rollback" in update, "normal update downgrade protection missing")
require("$rollbackNeeded = $disabled || $dependentsDisabled || $oldMoved || $newMoved || $metadataWritten" in update, "update rollback trigger omits mutated lifecycle state")
for marker in (
    "deleteExtension($extensionId)",
    "rename($previousSwap, (string) $live)",
    "$this->restoreTargetState($snapshot, $operation);",
    "$this->restoreDependentStates($snapshot, $operation);",
    "$this->installed->reconcileFilesystem();",
):
    require(marker in rollback_update, f"failed update rollback missing {marker}")

# Dependency policy remains Composer-only and reverse protection remains physical-state based.
version_policy = text("src/Services/ExtensionVersionPolicy.php")
deps = text("src/Services/ExtensionDependencyGuard.php")
require("Semver::satisfies($this->normalize($version), trim($constraint))" in version_policy, "Composer SemVer policy regressed")
require("satisfiesPackageDependency" not in version_policy, "special Core SemVer widening returned")
require("$this->installed->reconcileFilesystem();" in deps, "dependency package map does not reconcile filesystem first")
require("scandir($this->paths->pluginsRoot())" in deps, "dependency graph does not scan physical plugin directories")
require("Uninstall blocked because installed packages depend on it" in deps, "uninstall reverse-dependency protection missing")
require("enabledDependentsOf" in deps and "dependentsOf" in deps, "enabled/direct dependency protection API missing")
require("Cannot enable" in deps and "mandatory dependency" in deps, "enable does not require mandatory dependencies to be enabled")

# Controller lifecycle state changes must refresh immediately.
actions = text("src/Controllers/Admin/PackageActionController.php")
change = method_body(actions, "changeLifecycle")
verify = method_body(actions, "verify")
ordered(change, [
    "$this->installed->reconcileFilesystem();",
    "$this->installed->resolve(",
    "$this->lifecycle->refresh();",
    "$this->installed->reconcileFilesystem();",
    "$this->installed->resolve(",
], "enable/disable immediate refresh")
require("enabledStateSnapshot" in change, "disable lifecycle does not snapshot dependents")
require("disable_order" in change, "disable lifecycle does not use dependency-safe reverse order")
require("restoreLifecycleSnapshot" in actions, "failed disable has no enabled-state restoration")
require("integrity_verification_failed" in verify, "integrity mismatch is not logged as verification failure")
require("$operation->fail(" in verify and "$operation->complete('Package integrity verified.')" in verify, "verify operation does not distinguish pass/failure")

# Backup lifecycle: artifact verification, dependency-safe restore, user-visible restore log,
# immediate installed-state refresh, rollback to previous working package.
backup = text("src/Services/BackupManager.php")
create_from = method_body(backup, "createFromPath")
restore = method_body(backup, "restore")
backup_delete = method_body(backup, "delete")
ordered(create_from, [
    "$this->paths->copyDirectory($sourcePath, $destination);",
    "$this->installed->readManifest($destination, $packageId);",
    "$this->hasher->hash($destination);",
    "PackageBackup::create([",
], "backup creation")
require("$this->dependencies->assertUpdateAllowed($candidateManifest);" in restore, "backup restore does not preflight candidate dependencies")
require("manifestWithStoredContract" in restore, "backup restore does not preserve legacy registry dependency contract")
require("Backup restore blocked because the backup would leave a required dependency disabled" in restore, "restore can disable a dependency while enabled dependents require it")
ordered(restore, [
    "$this->path($backup)",
    "$this->hasher->hash($backupPath)",
    "$this->installed->readManifest($backupPath, $packageId)",
    "$this->dependencies->assertUpdateAllowed($candidateManifest);",
    "$this->createFromPath(",
    "$this->paths->copyDirectory($backupPath, $incoming);",
    "$this->disableDependents($dependentSnapshot, $operation);",
    "$this->lifecycle->disable($packageId)",
    "rename($incoming, $live)",
    "$this->installed->reconcileFilesystem();",
    "$this->dependencies->assertManifestEnableAllowed($candidateManifest);",
    "$this->restoreDependentStates($dependentSnapshot, $operation);",
    "$this->installed->reconcileFilesystem();",
    "$this->installed->resolve($packageId, true, false);",
], "backup restore")
require("'last_operation_result' => 'restored'" in restore, "backup restore metadata is not distinguished from automatic rollback")
require("$rollbackAttempted = $oldMoved || $newMoved || $dependentsChanged || $targetChanged" in restore, "restore rollback accounting misses lifecycle-only mutations")
require("restoreDependentStates($dependentSnapshot, $operation)" in restore, "restore failure path does not restore dependent state")
require("manual_restore_failed" in restore, "user restore failure category is not distinct")
require("$this->paths->deleteDirectory(dirname($path));" in backup_delete and "$backup->delete();" in backup_delete, "backup delete does not remove artifact and metadata")

backup_controller = text("src/Controllers/Admin/BackupController.php")
require("'operation' => 'restore'" in backup_controller, "backup restore is still logged as rollback")
require("'operation' => 'rollback'" not in backup_controller, "user restore log still uses rollback operation type")
require("restore_failed" in backup_controller, "backup restore controller failure category missing")

# Uninstall dependency check -> backup -> disable -> guarded file removal -> metadata deletion ->
# filesystem reconciliation; rollback restores files/metadata/enabled state.
uninstaller = text("src/Services/ExtensionUninstaller.php")
uninstall = method_body(uninstaller, "uninstall")
ordered(uninstall, [
    "$this->dependencies->assertUninstallAllowed($extensionId);",
    "$this->backups->createFromPath(",
    "$this->lifecycle->disable($extensionId)",
    "rename($live, $quarantine)",
    "$extension->delete();",
    "$this->installed->reconcileFilesystem();",
], "uninstall")
require("new InstalledExtension()" in uninstall and "$restored->forceFill($metadata)" in uninstall, "failed uninstall cannot restore Manager metadata")
require("rename($quarantine, $live)" in uninstall, "failed uninstall cannot restore package files")
require("$this->lifecycle->enable($extensionId)" in uninstall, "failed uninstall cannot restore prior enabled state")
require(uninstall.count("$this->installed->reconcileFilesystem();") >= 2, "uninstall success/failure paths do not both reconcile filesystem state")

# Filesystem authority/manual installs.
resolver = text("src/Services/InstalledExtensionResolver.php")
ordered(method_body(resolver, "reconcileFilesystem"), [
    "InstalledExtension::query()->get()",
    "scandir($this->paths->pluginsRoot())",
], "filesystem reconciliation")
require("$record?->delete();" in method_body(resolver, "resolve"), "missing package directory does not delete stale Manager row")
require("'installed_version' => $manifest->version" in method_body(resolver, "resolve"), "filesystem manifest version is not authoritative")
require("'source_type' => 'local'" in method_body(resolver, "resolve"), "manual filesystem package is not represented as a local install")
require("Installed package ID does not match its directory exactly" in resolver, "manual package canonical directory identity check missing")

# M0.4 gate remains before every DB-backed lifecycle route.
manager_runtime = text("src/Services/ManagerRuntime.php")
require("if (! $status['schema_ready'])" in manager_runtime, "M0.4 early schema stop missing")
require(manager_runtime.find("if (! $status['schema_ready'])") < manager_runtime.find("$this->closeInterruptedOperations();"), "runtime DB work can execute before schema guard")
manager_schema = text("src/Services/ManagerSchema.php")
for state in ("READY", "MIGRATIONS_PENDING", "SCHEMA_INCONSISTENT", "DATABASE_UNAVAILABLE"):
    require(f"public const {state} = '{state}';" in manager_schema, f"M0.4 schema state regressed: {state}")
require("gaminghub_manager_operations" in manager_schema and "42P01" in manager_schema, "historical missing-operations protection regressed")

# Interrupted operations remain bounded and do not stay running forever.
require("where('result', 'running')" in manager_runtime, "interrupted operation cleanup missing running filter")
require("subMinutes(10)" in manager_runtime, "interrupted operation timeout contract changed")
require("marked as interrupted" in manager_runtime, "interrupted operation failure reason missing")

# Existing behavioral harnesses and phase gates are part of the release contract.
for required in (
    "tests/run-m05-filesystem-lifecycle.php",
    "tests/run-m04-schema-health.php",
    "tests/run-m04-schema-exception-policy.php",
    "tests/run-m04-runtime-guard.php",
    "tests/run-m03-registry-policy.php",
    "tests/run-m02-dependency-graph.php",
    "tests/run-manifest-inspection.php",
    "tests/run-release-security.php",
    "tests/verify_m04_database_resilience.py",
    "tests/verify_m03_registry_cleanup.py",
    "tests/verify_m02_package_state.py",
):
    require((ROOT / required).is_file(), f"release missing regression test {required}")

# Version-specific docs should identify the actual release, not milestone suffixes.
for path in ("UPGRADE.md", "FILES.md", "VERIFICATION.md"):
    content = text(path)
    require("0.1.5" in content, f"{path} does not identify release 0.1.5")
require("-m0.5" not in plugin.get("version", "") and "M0.5" not in plugin.get("version", ""), "milestone leaked into SemVer")

if errors:
    print("FAILED: M0.5 lifecycle contracts")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print(f"PASS: M0.5 lifecycle orchestration, rollback, backup/restore, uninstall, immediate refresh, and phase regression contracts ({checks} checks)")
