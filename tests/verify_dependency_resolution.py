#!/usr/bin/env python3
"""Focused static contracts for immediate dependency resolution after install."""
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors: list[str] = []


def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


validator = text("src/Services/ExtensionManifestValidator.php")
installer = text("src/Services/ExtensionInstaller.php")
guard = text("src/Services/ExtensionDependencyGuard.php")
compatibility = text("src/Services/ExtensionCompatibility.php")
policy = text("src/Services/ExtensionVersionPolicy.php")
catalog = text("src/Services/PackageCatalog.php")

# package.json/plugin.json, Manager manifest, registry and stored metadata all retain the canonical ID.
require("($plugin['id'] ?? null) !== $id" in validator, "plugin ID is not checked against normalized package ID")
require("'extension_id' => $manifest->id" in installer, "installed metadata does not persist the manifest ID")
require("'installed_version' => $manifest->version" in installer, "installed metadata does not persist the installed manifest version")
require("where('extension_id', $dependencyId)" in guard, "dependency resolver does not use the exact dependency ID")

# Refresh from filesystem before validation, then read the reconciled installed metadata rather than registry release data.
refresh = installer.find("$this->installed->reconcileFilesystem();")
compat = installer.find("$this->compatibility->assertCompatible(")
require(0 <= refresh < compat, "filesystem reconciliation does not run before dependency compatibility validation")
require("where('extension_id', 'gaming-hub-core')" in installer, "Core lookup does not use the canonical package ID")
require("->value('installed_version')" in installer, "Core version is not read from installed-package metadata")
require("latest_version" not in installer[installer.find("private function coreVersion"):], "Core version lookup uses registry release data")

# Required clean-install compatibility result while preserving Composer behavior elsewhere.
require("$dependencyId === 'gaming-hub-core'" in policy, "Core-specific pre-1.0 dependency rule is missing")
require("version_compare($version, $minimum, '>=')" in policy, "Core dependency lower bound is missing")
require("version_compare($version, '1.0.0', '<')" in policy, "Core dependency upper bound is missing")
require("return $this->satisfies($version, $constraint);" in policy, "non-Core package dependency behavior no longer falls back to the base comparator")
require("satisfiesPackageDependency('gaming-hub-core'" in compatibility, "top-level Core dependency does not use package dependency policy")
require("satisfiesPackageDependency($dependencyId" in guard, "extension dependency lookup does not use package dependency policy")
require("satisfiesPackageDependency('gaming-hub-core'" in catalog, "catalog preview disagrees with Core dependency validation")

# Failed validation must leave detailed diagnostics in the Manager operation failure log.
for source, name in ((guard, "dependency guard"), (compatibility, "Core compatibility")):
    for marker in (
        "package=",
        "requested=",
        "installed_packages=",
        "installed_version=",
        "constraint=",
        "comparison=",
    ):
        require(marker in source, f"{name} failure diagnostics omit {marker}")

# Successful install metadata is written before install() returns; there is no deferred persistence queue.
create_pos = installer.find("$record = InstalledExtension::create(")
return_pos = installer.find("return $record;", create_pos)
require(0 <= create_pos < return_pos, "successful install can return before installed metadata is persisted")

if errors:
    print("FAILED")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print("PASS: dependency IDs, installed metadata, Core 0.7.0 compatibility, immediate visibility, and failure diagnostics")
