#!/usr/bin/env python3
"""Static release contract checks; does not boot Azuriom."""
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


for name in ("plugin.json", "composer.json", "gaming-hub-extension.json", "resources/registry/official.json"):
    path = ROOT / name
    require(path.is_file(), f"missing {name}")
    if path.is_file():
        try:
            json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001
            errors.append(f"invalid JSON {name}: {exc}")

plugin = json.loads((ROOT / "plugin.json").read_text(encoding="utf-8"))
manifest = json.loads((ROOT / "gaming-hub-extension.json").read_text(encoding="utf-8"))
require(plugin.get("id") == "gaming-hub-manager", "unexpected plugin ID")
require(plugin.get("version") == "0.1.2", "unexpected release version")
require(plugin.get("version") == manifest.get("version"), "plugin/manifest version mismatch")
require(manifest.get("package", {}).get("plugin_directory") == plugin.get("id"), "plugin directory mismatch")

required_pages = {"overview", "installed", "available", "registries", "logs", "backups", "settings", "package", "release", "uninstall"}
views = {p.name.removesuffix(".blade.php") for p in (ROOT / "resources/views/admin").glob("*.blade.php")}
require(required_pages <= views, f"missing admin views: {sorted(required_pages - views)}")


for required_file in (
    "UPGRADE.md",
    "docs/VIEW_CONTRACTS.md",
    "src/Support/ManagerAlertNormalizer.php",
    "resources/views/admin/partials/package-warning.blade.php",
    "tests/run-alert-normalizer.php",
    "tests/run-manifest-inspection.php",
    "tests/verify_view_contract.py",
    "tests/run-release-security.php",
    "tests/verify_release_pipeline.py",
    "src/Services/GitHubAssetDigestValidator.php",
    "src/Services/RegistryChecksumResolver.php",
    "src/Services/ReleaseVersionValidator.php",
):
    require((ROOT / required_file).is_file(), f"missing {required_file}")
require(not (ROOT / "resources/views/admin/partials/navigation.blade.php").exists(), "obsolete horizontal navigation partial remains")

routes = (ROOT / "routes/admin.php").read_text(encoding="utf-8")
for route_name in {"overview", "installed", "available", "registries", "logs", "backups", "settings"}:
    require(f"name('{route_name}')" in routes, f"missing route {route_name}")

defined_route_names = {
    "gaming-hub-manager.admin." + name
    for name in re.findall(r"->name\(['\"]([^'\"]+)['\"]\)", routes)
}
referenced_route_names: set[str] = set()
for path in list((ROOT / "resources/views").rglob("*.php")) + list((ROOT / "src").rglob("*.php")):
    referenced_route_names.update(
        re.findall(r"route\(['\"](gaming-hub-manager\.admin\.[^'\"]+)['\"]", path.read_text(encoding="utf-8"))
    )
require(
    referenced_route_names <= defined_route_names,
    f"undefined route references: {sorted(referenced_route_names - defined_route_names)}",
)

for path in ROOT.rglob("*.blade.php"):
    text = path.read_text(encoding="utf-8")
    # Inline @section('title', '...') declarations do not open a block.
    block_text = re.sub(r"@section\s*\(\s*(['\"])[^'\"]+\1\s*,.*?\)", "", text)
    tokens = re.findall(
        r"@(forelse|endforelse|foreach|endforeach|if|elseif|else|endif|unless|endunless|can|endcan|section|endsection|show|empty)\b",
        block_text,
    )
    stack: list[str] = []
    openers = {"forelse", "foreach", "if", "unless", "can", "section"}
    closers = {
        "endforelse": "forelse",
        "endforeach": "foreach",
        "endif": "if",
        "endunless": "unless",
        "endcan": "can",
        "endsection": "section",
        "show": "section",
    }
    for token in tokens:
        if token in openers:
            stack.append(token)
        elif token in closers:
            expected = closers[token]
            if not stack or stack[-1] != expected:
                errors.append(
                    f"{path.relative_to(ROOT)}: @{token} does not close @{expected} in current nesting"
                )
                break
            stack.pop()
        elif token in {"else", "elseif"}:
            require(bool(stack) and stack[-1] == "if", f"{path.relative_to(ROOT)}: @{token} outside @if")
        elif token == "empty":
            require(bool(stack) and stack[-1] == "forelse", f"{path.relative_to(ROOT)}: @empty outside @forelse")
    require(not stack, f"{path.relative_to(ROOT)}: unclosed Blade directives {stack}")

for forbidden in (
    "GamingHubCore\\Providers",
    "GamingHubCore\\Services",
    "gaming-hub-core.extensions",
    "gaming-hub-panel",
    "SharedDataGateway",
):
    for path in list((ROOT / "src").rglob("*.php")) + list((ROOT / "routes").rglob("*.php")):
        require(forbidden not in path.read_text(encoding="utf-8"), f"forbidden coupling {forbidden} in {path.relative_to(ROOT)}")

if errors:
    print("FAILED")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print("PASS: Gaming Hub Manager static release contract")
