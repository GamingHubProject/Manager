#!/usr/bin/env python3
"""Focused M0.3 static contracts for registry ownership and legacy cleanup."""
from __future__ import annotations

import json
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
OFFICIAL_ID = "gaminghubproject-official"
OFFICIAL_NAME = "GamingHubProject Official Registry"

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
manager = text("src/Services/ExtensionSourceManager.php")
policy = text("src/Services/LegacyRegistryPolicy.php")
legacy = text("src/Services/LegacyMetadataImporter.php")
runtime = text("src/Services/ManagerRuntime.php")
controller = text("src/Controllers/Admin/SourceController.php")
provider = text("src/Providers/GamingHubManagerServiceProvider.php")
install = text("INSTALL.md")
migration = text("docs/MIGRATION.md")

require(f"'official_registry_url' => '{OFFICIAL_URL}'" in config, "canonical official registry is not a fixed built-in value")
require("GAMING_HUB_MANAGER_OFFICIAL_REGISTRY_URL" not in config, "official registry can still be silently replaced by environment override")
require(OFFICIAL_URL in manager and "public const OFFICIAL_URL" in manager, "source manager does not own the exact canonical official URL")
require(OFFICIAL_ID in manager, "canonical official source ID is missing")
require(OFFICIAL_NAME in manager, "canonical official source name is missing")
require("LegacyRegistryPolicy" in manager and "isObsoleteManagedStoredSource" in manager, "official bootstrap does not use isolated legacy ownership policy")
require("isOfficialRegistryUrl" in manager, "canonical URL normalization is not exposed to source creation")
require("str_starts_with((string) $source->source_id, self::OFFICIAL_SOURCE_ID.'-managed-')" in manager, "official source collision fallback is not stable across repeated initialization")
require("isOfficialRegistryUrl($data['url'])" in controller, "custom source creation does not block canonical official duplicates")
require("LegacyRegistryPolicy::class" in provider, "legacy registry policy is not registered")

# Runtime must create/reconcile the Manager official source before considering legacy import.
require(runtime.find("$this->sources->ensureOfficial();") >= 0, "runtime does not ensure Manager official registry")
require(runtime.find("$this->sources->ensureOfficial();") < runtime.find("$this->legacy->import();"), "legacy import runs before Manager official ownership is established")

# Legacy import still requires concrete evidence, and official Core rows are suppressed before creation.
gate = legacy.find("if (! $this->legacyMetadataExists())")
last_run = legacy.find("legacy_import_last_run")
source_import = legacy.find("$this->importSources($summary)")
suppress = legacy.find("$this->legacyRegistry->suppressLegacyImport($values)")
create = legacy.find("ExtensionSource::create([", suppress)
require(0 <= gate < last_run < source_import, "legacy evidence gate does not precede importer side effects")
require("DB::table($table)->limit(1)->exists()" in legacy, "empty legacy tables can trigger import")
require("return $summary + ['detected' => false]" in legacy, "fresh installation does not return a silent no-import result")
require("legacyBackupMetadataExists" in legacy and "readManifest" in legacy, "legacy backup evidence is not validated")
require(0 <= suppress < create, "legacy official suppression does not happen before source creation")
require("str_ends_with($name, ' (imported from Core)')" in policy, "Core-import artifact detection is not exact")
require("$type === 'official'" in policy, "historical protected official rows are not recognized")

# Active/bundled current data must use GamingHubProject.
for registry_path in ("resources/registry/official.json", "examples/registry.json"):
    registry = json.loads((ROOT / registry_path).read_text(encoding="utf-8"))
    require(registry.get("id") == OFFICIAL_ID, f"{registry_path} has wrong official ID")
    require(registry.get("name") == OFFICIAL_NAME, f"{registry_path} has wrong official name")
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

require(OFFICIAL_URL in install and OFFICIAL_NAME in install, "installation documentation does not describe the current built-in registry")
require("Do **not** add a second copy manually." in install, "installation documentation still encourages duplicate official registry creation")
require("LegacyRegistryPolicy" in migration and "Genuine legacy custom sources" in migration, "migration documentation does not explain M0.3 ownership boundary")

# Historical literals may exist only in isolated migration recognition code/fixture.
allowed_legacy_literal_files = {
    Path("src/Services/LegacyRegistryPolicy.php"),
    Path("tests/run-m03-registry-policy.php"),
}
legacy_owner = "roses" + "ofdorns"
legacy_repositories = ("gaming-hub-" + "registry", "gaming-hub-" + "community")
for path in ROOT.rglob("*"):
    if not path.is_file() or ".git" in path.parts:
        continue
    try:
        content = path.read_text(encoding="utf-8").lower()
    except UnicodeDecodeError:
        continue
    rel = path.relative_to(ROOT)
    if legacy_owner in content or any(repo in content for repo in legacy_repositories):
        require(rel in allowed_legacy_literal_files, f"historical registry literal escaped isolated migration code/fixture: {rel}")

if errors:
    print("FAILED")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print("PASS: M0.3 official registry ownership, legacy gating/reconciliation, current bundled data, and custom-source protection contracts")
