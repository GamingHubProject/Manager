#!/usr/bin/env python3
"""Focused static contracts for Manager admin views and independence."""
from __future__ import annotations

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


page_names = (
    "overview",
    "installed",
    "available",
    "registries",
    "logs",
    "backups",
    "settings",
    "package",
    "release",
    "uninstall",
)

for name in page_names:
    path = ROOT / f"resources/views/admin/{name}.blade.php"
    require(path.is_file(), f"missing admin view {name}")
    if not path.is_file():
        continue
    source = path.read_text(encoding="utf-8")
    require("admin.partials.alerts" in source, f"{name} does not include the alerts partial")
    require("admin.partials.package-warning" in source, f"{name} does not include the package warning")
    require(source.count("@section('title'") == 1, f"{name} must declare exactly one page title")
    require(not re.search(r"<h1\b", source, re.I), f"{name} duplicates the Azuriom layout h1")
    require("partials.navigation" not in source, f"{name} still includes horizontal Manager navigation")
    require("nav-tabs" not in source, f"{name} still contains a tab navigation bar")

all_php = list((ROOT / "src").rglob("*.php")) + list((ROOT / "routes").rglob("*.php"))
all_blade = list((ROOT / "resources/views").rglob("*.blade.php"))

for path in all_php + all_blade:
    source = path.read_text(encoding="utf-8")
    relative = path.relative_to(ROOT)
    require("->any()" not in source, f"{relative} still calls any()")
    require("withErrors(" not in source, f"{relative} routes domain failures through the validation bag")
    require(not re.search(r"['\"]errors['\"]\s*=>", source), f"{relative} passes domain data as reserved errors")
    require(not re.search(r"compact\s*\([^)]*\berrors\b", source), f"{relative} compacts a reserved errors variable")
    require(not re.search(r"\$errors\s*=", source), f"{relative} assigns the reserved errors variable")

error_references = []
for path in all_php + all_blade:
    if re.search(r"\$errors\b", path.read_text(encoding="utf-8")):
        error_references.append(path.relative_to(ROOT).as_posix())
require(error_references == ["resources/views/admin/partials/alerts.blade.php"], f"unexpected $errors references: {error_references}")

alerts = text("resources/views/admin/partials/alerts.blade.php")
normalizer = text("src/Support/ManagerAlertNormalizer.php")
require("validation($errors ?? null)" in alerts, "alerts partial does not isolate Laravel validation errors")
require("custom($managerAlerts ?? [])" in alerts, "alerts partial does not use managerAlerts")
for flash_key in ("success", "warning", "error"):
    require(f"session('{flash_key}')" in alerts, f"alerts partial does not render {flash_key} flash messages")
require("instanceof ViewErrorBag" in normalizer, "ViewErrorBag is not type-checked")
require("instanceof Collection" in normalizer, "Manager collections are not type-checked")
require("Stack trace:" in normalizer and "[redacted]" in normalizer, "alert text safety normalization is incomplete")
require(not (ROOT / "resources/views/admin/partials/navigation.blade.php").exists(), "horizontal navigation partial still exists")

catalog = text("src/Services/PackageCatalog.php")
dashboard = text("src/Controllers/Admin/DashboardController.php")
legacy = text("src/Services/LegacyMetadataImporter.php")
require("managerAlerts" in catalog and "'managerAlerts' =>" in catalog, "catalog does not expose managerAlerts")
require("snapshotWithLegacyAlerts" in dashboard and "$snapshot['managerAlerts'][]" in dashboard, "catalog pages do not pass managerAlerts")
require("'warnings' => []" in legacy and "legacy['warnings']" in dashboard, "legacy import warnings are not separated from validation errors")
require("legacy_import_last_summary" in legacy and "$summary['warnings'][]" in legacy, "throttled legacy warnings are not retained safely")
for table in ("gaminghub_extension_sources", "gaminghub_installed_extensions", "gaminghub_extension_operations"):
    require(f"Schema::hasTable('{table}')" in legacy, f"legacy importer does not safely guard missing {table}")
require("stale legacy record skipped" in legacy, "stale legacy package metadata does not produce a safe warning")
require("requiredPackageId" in legacy and "catch (\\Throwable $exception)" in legacy, "malformed legacy records are not isolated")

routes = text("routes/admin.php")
controllers = "\n".join(path.read_text(encoding="utf-8") for path in (ROOT / "src/Controllers/Admin").glob("*.php"))
for route_name in ("overview", "installed", "available", "registries", "logs", "backups", "settings"):
    require(f"name('{route_name}')" in routes, f"missing route {route_name}")
    require(f"admin.{route_name}'" in controllers, f"no controller renders {route_name}")
for view_name in ("package", "release", "uninstall"):
    require(f"admin.{view_name}'" in controllers, f"no controller renders {view_name}")

provider = text("src/Providers/GamingHubManagerServiceProvider.php")
for label in ("Overview", "Installed Packages", "Available Packages", "Registries", "Install Logs", "Backups", "Settings"):
    require(f"'name' => '{label}'" in provider, f"sidebar entry missing: {label}")
require("registerAdminNavigation();" in provider and "protected function adminNavigation" in provider, "supported Azuriom admin navigation registration is missing")
require("native Extensions group" in provider and "supported standalone entry" in provider, "admin placement fallback is not documented")
require("position:" not in provider and "order:" not in provider, "unsupported navigation positioning metadata was added")
for path in all_php + all_blade:
    source = path.read_text(encoding="utf-8")
    require("style=\"order:" not in source, f"navigation order CSS hack in {path.relative_to(ROOT)}")
    require(".sidebar" not in source and "#sidebar" not in source, f"sidebar CSS positioning hack in {path.relative_to(ROOT)}")

# Independence: references to package IDs as generic domain data are allowed, but Core classes/services/routes/views are not.
for forbidden in (
    "Azuriom\\Plugin\\GamingHubCore",
    "GamingHubCore\\",
    "gaming-hub-core::",
    "gaming-hub-core.admin",
    "SharedDataGateway",
    "GamingHubPanel\\",
):
    for path in all_php + all_blade:
        require(forbidden not in path.read_text(encoding="utf-8"), f"forbidden dependency {forbidden} in {path.relative_to(ROOT)}")

resolver = text("src/Services/InstalledExtensionResolver.php")
validator = text("src/Services/ExtensionManifestValidator.php")
package_controller = text("src/Controllers/Admin/PackageController.php")
actions = text("src/Controllers/Admin/PackageActionController.php")
require("$entry === 'gaming-hub-manager'" not in resolver, "filesystem reconciliation still skips Manager")
require("allowManagerInspection" in validator and "! $allowManagerInspection" in validator, "self-inspection/self-management split is missing")
require("$expectedId === 'gaming-hub-manager'" in resolver, "installed Manager manifest inspection is not enabled")
require("$protectedPackage" in package_controller, "package detail does not expose Manager self-protection")
require("protectedPackage(" in actions, "lifecycle actions do not protect Manager from self-modification")

contracts = ROOT / "docs/VIEW_CONTRACTS.md"
require(contracts.is_file(), "view contract documentation is missing")
if contracts.is_file():
    contract_text = contracts.read_text(encoding="utf-8")
    for name in page_names:
        require(f"`admin/{name}.blade.php`" in contract_text, f"view contract missing {name}")
    for reserved in ("errors", "message", "session", "request", "app", "auth", "user"):
        require(f"`${reserved}`" in contract_text, f"reserved-variable review missing ${reserved}")

if failures:
    print("FAILED")
    for failure in failures:
        print(f"- {failure}")
    sys.exit(1)

print("PASS: Manager view contracts, navigation, self-detection, legacy guards, and independence")
