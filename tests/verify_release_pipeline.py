#!/usr/bin/env python3
"""Static integration contracts for v0.1.3 release discovery and checksum flow."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
failures: list[str] = []


def require(condition: bool, message: str) -> None:
    if not condition:
        failures.append(message)


def text(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


resolver = text("src/Services/PackageReleaseResolver.php")
priority_markers = [
    "selectChecksumAsset",
    "githubDigests->resolve($asset)",
    "registryChecksums->resolve",
    "No valid published SHA-256 checksum source exists",
]
positions = [resolver.find(marker) for marker in priority_markers]
require(all(position >= 0 for position in positions), "checksum source priority markers are incomplete")
require(positions == sorted(positions), "checksum source priority is not explicit asset -> GitHub digest -> registry pin -> reject")
require("'github_asset_digest'" in resolver, "GitHub asset digest source is not named")
require("'explicit_checksum_asset'" in resolver, "explicit checksum source is not named")
require("'registry_pinned'" in resolver, "registry-pinned checksum source is not named")
for context_key in (
    "checksum_source",
    "package_asset_id",
    "package_asset_name",
    "checksum_asset_id",
    "checksum_asset_name",
):
    require(f"'{context_key}'" in resolver, f"operation context omits {context_key}")
require("githubDigests->resolve($asset)" in resolver, "digest is not taken from the exact selected asset")
require("if ($source->type === 'official'" not in resolver, "checksum rejection is incorrectly limited to official packages")
require("allow_official_without_checksum" not in resolver, "obsolete checksum bypass remains in the resolver")
require("$release['assets']" not in resolver.split("githubDigests->resolve($asset)")[0][-300:], "resolver scans unrelated assets for a digest")

client = text("src/Services/GitHubReleaseClient.php")
for marker in (
    "($release['draft'] ?? true) === true",
    "$release['prerelease']",
    "selectAssetOrNull",
    "version_compare",
    "releaseVersions->releaseVersion",
    "releaseVersions->isPrerelease",
):
    require(marker in client, f"release discovery missing {marker}")
require("zipball_url" not in client and "tarball_url" not in client, "GitHub source-code archives are used")
require("Cache::forget($this->cacheKey" in client, "GitHub release metadata cache invalidation is missing")
require("release_cache_ttl" in client, "GitHub release metadata cache TTL is missing")

source_manager = text("src/Services/ExtensionSourceManager.php")
require("invalidateRegistryReleases" in source_manager, "registry refresh does not invalidate release caches")
require("if ($force)" in source_manager and "invalidateReleaseMetadata" in source_manager, "forced refresh does not invalidate metadata")
require("github->discover" in source_manager, "direct GitHub sources do not use authoritative discovery")

catalog = text("src/Services/PackageCatalog.php")
require("github->discover" in catalog, "registry catalog does not discover GitHub releases")
require("$selection['version']" in catalog, "displayed version does not come from selected GitHub release")
require("$entry->latestVersion" in catalog and "legacy_hint" in catalog, "latest_version is not retained only as a legacy fallback")
selection_position = catalog.find("$selection['version']")
hint_position = catalog.find("$entry->latestVersion", selection_position)
require(selection_position >= 0 and hint_position > selection_position, "stale latest_version can precede GitHub release selection")

registry_validator = text("src/Services/ExtensionRegistryValidator.php")
registry_data = text("src/Data/RegistryExtension.php")
require("?string $latestVersion" in registry_data, "latest_version is not optional")
require("array_key_exists('latest_version'" in registry_validator, "optional latest_version validation is missing")
require("Invalid legacy latest_version hint" in registry_validator, "legacy latest_version is not explicitly deprecated")

installer = text("src/Services/ExtensionInstaller.php")
version_validator = text("src/Services/ReleaseVersionValidator.php")
require("releaseVersions->assertConsistent" in installer, "installer does not enforce tag/asset/manifest consistency")
for phrase in (
    "GitHub Release tag and plugin.json version do not match",
    "Versioned release asset filename and GitHub tag do not match",
    "Resolved release version changed before package validation",
):
    require(phrase in version_validator, f"version consistency check missing: {phrase}")

logs = text("resources/views/admin/logs.blade.php")
release_view = text("resources/views/admin/release.blade.php")
require("checksum_source" in logs, "operation log UI does not expose checksum source")
require("$checksumSource" in release_view and "$selectedVersion" in release_view, "release detail does not show authoritative version/checksum source")

for path in (
    "resources/registry/official.json",
    "examples/registry.json",
):
    registry = json.loads((ROOT / path).read_text(encoding="utf-8"))
    require(
        all("latest_version" not in entry for entry in registry.get("extensions", [])),
        f"{path} still teaches latest_version as authoritative",
    )

new_services = (
    "src/Services/GitHubAssetDigestValidator.php",
    "src/Services/RegistryChecksumResolver.php",
    "src/Services/ReleaseVersionValidator.php",
)
for path in new_services:
    require((ROOT / path).is_file(), f"missing {path}")
    source = text(path)
    for hardcoded in ("gaming-hub-core", "gaming-hub-panel", "gaming-hub-palworld", "gaming-hub-ark"):
        require(hardcoded not in source, f"generic service {path} hard-codes {hardcoded}")

plugin = json.loads((ROOT / "plugin.json").read_text(encoding="utf-8"))
manifest = json.loads((ROOT / "gaming-hub-extension.json").read_text(encoding="utf-8"))
require(plugin.get("version") == "0.1.3", "plugin version is not 0.1.3")
require(manifest.get("version") == "0.1.3", "package manifest version is not 0.1.3")

if failures:
    print("FAILED")
    for failure in failures:
        print(f"- {failure}")
    sys.exit(1)

print("PASS: checksum priority, authoritative GitHub discovery, cache invalidation, and generic package contracts")
