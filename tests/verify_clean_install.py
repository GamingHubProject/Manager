#!/usr/bin/env python3
"""Focused static contracts for clean installation and filesystem authority."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
errors: list[str] = []


def require(condition: bool, message: str) -> None:
    if not condition:
        errors.append(message)


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


OFFICIAL_URL = "https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json"
OFFICIAL_NAME = "GamingHubProject Official Registry"
OFFICIAL_ID = "gaminghubproject-official"

EXPECTED_OFFICIAL_PACKAGES = {
    "gaming-hub-core": {
        "id": "gaming-hub-core",
        "name": "Gaming Hub Core",
        "description": "Gaming Hub Core",
        "author": "GamingHubProject",
        "category": "Core",
        "repository": "https://github.com/GamingHubProject/Core",
        "release_asset": "gaming-hub-core-v*.zip",
        "checksum_asset": "SHA256SUMS",
        "verified": True,
        "official": True,
    },
    "gaming-hub-panel": {
        "id": "gaming-hub-panel",
        "name": "Gaming Hub Panel",
        "description": "Pelican / Pterodactyl integration",
        "author": "GamingHubProject",
        "category": "Integrations",
        "repository": "https://github.com/GamingHubProject/Panel",
        "release_asset": "gaming-hub-panel-v*.zip",
        "checksum_asset": "SHA256SUMS",
        "verified": True,
        "official": True,
        "requires": {"gaming-hub-core": ">=0.7.0"},
    },
}
STALE_REPOSITORIES = {
    "https://github.com/GamingHubProject/" + "gaming-hub-core",
    "https://github.com/GamingHubProject/" + "gaming-hub-panel",
}


config = text("config/manager.php")
source_manager = text("src/Services/ExtensionSourceManager.php")
legacy = text("src/Services/LegacyMetadataImporter.php")
resolver = text("src/Services/InstalledExtensionResolver.php")
runtime = text("src/Services/ManagerRuntime.php")
schema = text("src/Services/ManagerSchema.php")
catalog = text("src/Services/PackageCatalog.php")
provider = text("src/Providers/GamingHubManagerServiceProvider.php")

require(f"\'official_registry_url\' => \'{OFFICIAL_URL}\'" in config, "default official registry URL is not fixed to current canonical value")
require("GAMING_HUB_MANAGER_OFFICIAL_REGISTRY_URL" not in config, "official registry URL still has an environment override")
require(OFFICIAL_NAME in source_manager, "official registry name is not exact")
require(OFFICIAL_ID in source_manager, "official source ID is not current")
require("candidate->type === 'official'" in source_manager, "official bootstrap does not target protected Manager official rows")
require("isObsoleteManagedStoredSource" in source_manager, "obsolete Manager/Core official artifacts are not reconciled")
require("isOfficialRegistryUrl" in source_manager, "canonical official URL normalization is not exposed for duplicate prevention")

for registry_path in ("resources/registry/official.json", "examples/registry.json"):
    registry = json.loads((ROOT / registry_path).read_text(encoding="utf-8"))
    require(registry.get("id") == OFFICIAL_ID, f"{registry_path} has wrong ID")
    require(registry.get("name") == OFFICIAL_NAME, f"{registry_path} has wrong name")
    extensions = registry.get("extensions", [])
    extension_map = {entry.get("id"): entry for entry in extensions if isinstance(entry, dict)}
    require(
        set(extension_map) == set(EXPECTED_OFFICIAL_PACKAGES),
        f"{registry_path} official package IDs do not match current Registry fixture: {sorted(extension_map)}",
    )
    for package_id, expected in EXPECTED_OFFICIAL_PACKAGES.items():
        entry = extension_map.get(package_id, {})
        require(entry == expected, f"{registry_path} {package_id} does not match the current official Registry fixture")
        require(entry.get("repository") not in STALE_REPOSITORIES, f"{registry_path} {package_id} still uses stale repository path")

legacy_owner = "roses" + "ofdorns"
legacy_repositories = ("gaming-hub-" + "registry", "gaming-hub-" + "community")
allowed_legacy_literal_files = {
    Path("src/Services/LegacyRegistryPolicy.php"),
    Path("tests/run-m03-registry-policy.php"),
}
for path in ROOT.rglob("*"):
    if not path.is_file() or ".git" in path.parts:
        continue
    try:
        content = path.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        continue
    rel = path.relative_to(ROOT)
    lowered = content.lower()
    if legacy_owner in lowered or any(repo in lowered for repo in legacy_repositories):
        require(rel in allowed_legacy_literal_files, f"legacy registry identifier escaped isolated migration compatibility files: {rel}")

# The import gate must run before throttling or any import method.
gate_position = legacy.find("if (! $this->legacyMetadataExists())")
last_run_position = legacy.find("legacy_import_last_run")
source_import_position = legacy.find("$this->importSources($summary)")
require(gate_position >= 0, "legacy metadata presence gate is missing")
require(gate_position < last_run_position < source_import_position, "legacy gate does not precede importer side effects")
for table in ("gaminghub_extension_sources", "gaminghub_installed_extensions", "gaminghub_extension_operations"):
    require(f"$this->schema->tableExists('{table}')" in legacy, f"legacy table guard missing for {table}")
require("DB::table($table)->limit(1)->exists()" in legacy, "empty legacy tables can still trigger import")
require("return $summary + ['detected' => false]" in legacy, "clean installation does not return a silent no-import result")
require("suppressLegacyImport($values)" in legacy, "legacy official Core source is not suppressed before import")
require("legacyBackupMetadataExists" in legacy and "readManifest" in legacy, "legacy backup marker is not validated")

# Filesystem must invalidate metadata before catalog state is computed.
require("foreach (InstalledExtension::query()->get() as $record)" in resolver, "stale installed rows are not reconciled")
require("$record?->delete();" in resolver, "missing package files do not delete stale metadata")
require("Installed package is missing plugin.json" in resolver, "plugin.json is not required")
require("Installed package ID does not match its directory" in resolver, "manifest/directory ID is not validated")
require("$this->installedResolver->reconcileFilesystem();" in catalog, "catalog does not reconcile filesystem first")
require(catalog.find("reconcileFilesystem") < catalog.find("InstalledExtension::query()"), "catalog reads installed metadata before filesystem reconciliation")

# Runtime must stop before any Manager query when the schema is incomplete.
status_position = runtime.find("$status = $this->schema->status(true)")
return_position = runtime.find("if (! $status['schema_ready'])")
operation_position = runtime.find("$this->closeInterruptedOperations()")
require(0 <= status_position < return_position < operation_position, "runtime readiness check does not precede Manager queries")
for table in ("sources", "packages", "operations", "backups", "settings"):
    require(f"gaminghub_manager_{table}" in schema, f"required Manager table missing from readiness contract: {table}")
require("42P01" in schema, "PostgreSQL missing-table SQLSTATE is not handled")
require("throw $exception" in schema, "unrelated schema exceptions are silently swallowed")
require("ManagerSchema::class" in provider, "ManagerSchema is not registered")
require((ROOT / "resources/views/admin/migration-required.blade.php").is_file(), "safe migration warning view is missing")

# Route parameters must not trigger Eloquent implicit binding before readiness checks.
for controller in (ROOT / "src/Controllers/Admin").glob("*.php"):
    content = controller.read_text(encoding="utf-8")
    require(
        not re.search(r"public function \w+\([^)]*\b(?:InstalledExtension|ExtensionSource|PackageBackup) \$", content),
        f"implicit Manager model binding remains in {controller.name}",
    )

plugin = json.loads((ROOT / "plugin.json").read_text(encoding="utf-8"))
manifest = json.loads((ROOT / "gaming-hub-extension.json").read_text(encoding="utf-8"))
require(plugin.get("version") == "0.1.5", "plugin version is not 0.1.5")
require(manifest.get("version") == "0.1.5", "manifest version is not 0.1.5")

if errors:
    print("FAILED")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print("PASS: M0.3 clean-install registry ownership, isolated legacy compatibility, filesystem authority, and migration readiness contracts")
