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

config = text("config/manager.php")
source_manager = text("src/Services/ExtensionSourceManager.php")
legacy = text("src/Services/LegacyMetadataImporter.php")
resolver = text("src/Services/InstalledExtensionResolver.php")
runtime = text("src/Services/ManagerRuntime.php")
schema = text("src/Services/ManagerSchema.php")
catalog = text("src/Services/PackageCatalog.php")
provider = text("src/Providers/GamingHubManagerServiceProvider.php")

require(OFFICIAL_URL in config, "default official registry URL is not current")
require(OFFICIAL_NAME in source_manager, "official registry name is not exact")
require(OFFICIAL_ID in source_manager, "official source ID is not current")
require("where('type', 'official')" in source_manager, "official bootstrap does not target only managed official rows")
require("where('url'" not in source_manager, "official bootstrap risks rewriting custom registry URLs")
require("->where('type', 'official')" in source_manager and "->delete();" in source_manager, "duplicate official rows are not reconciled")

for registry_path in ("resources/registry/official.json", "examples/registry.json"):
    registry = json.loads((ROOT / registry_path).read_text(encoding="utf-8"))
    require(registry.get("id") == OFFICIAL_ID, f"{registry_path} has wrong ID")
    require(registry.get("name") == OFFICIAL_NAME, f"{registry_path} has wrong name")
    for extension in registry.get("extensions", []):
        require(
            str(extension.get("repository", "")).startswith("https://github.com/GamingHubProject/"),
            f"{registry_path} contains a non-GamingHubProject repository",
        )

legacy_owner = "roses" + "ofdorns"
legacy_registry_repo = "gaming-hub-" + "registry"
for path in ROOT.rglob("*"):
    if not path.is_file() or ".git" in path.parts:
        continue
    try:
        content = path.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        continue
    require(legacy_owner not in content.lower(), f"legacy owner reference remains in {path.relative_to(ROOT)}")
    require(legacy_registry_repo not in content.lower(), f"legacy registry repository remains in {path.relative_to(ROOT)}")

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
require(plugin.get("version") == "0.1.4", "plugin version is not 0.1.4")
require(manifest.get("version") == "0.1.4", "manifest version is not 0.1.4")

if errors:
    print("FAILED")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print("PASS: clean installation, legacy gate, filesystem authority, and migration readiness contracts")
