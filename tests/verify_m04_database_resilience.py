#!/usr/bin/env python3
"""M0.4 source-contract checks complementing executable schema/runtime harnesses."""
from __future__ import annotations

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
    match = re.search(rf"public function {re.escape(name)}\s*\([^)]*\)[^{{]*\{{", source)
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


schema = text("src/Services/ManagerSchema.php")
runtime = text("src/Services/ManagerRuntime.php")
view = text("resources/views/admin/migration-required.blade.php")

for state in ("READY", "MIGRATIONS_PENDING", "SCHEMA_INCONSISTENT", "DATABASE_UNAVAILABLE"):
    require(f"public const {state} = '{state}';" in schema, f"ManagerSchema missing {state} state")

for table in (
    "gaminghub_manager_sources",
    "gaminghub_manager_packages",
    "gaminghub_manager_operations",
    "gaminghub_manager_backups",
    "gaminghub_manager_settings",
):
    require(table in schema, f"ManagerSchema missing required table {table}")

for migration in (
    "2026_08_06_000000_create_gaminghub_manager_sources_table",
    "2026_08_06_001000_create_gaminghub_manager_packages_table",
    "2026_08_06_002000_create_gaminghub_manager_operations_table",
    "2026_08_06_003000_create_gaminghub_manager_backups_table",
    "2026_08_06_004000_create_gaminghub_manager_settings_table",
):
    require(migration in schema, f"ManagerSchema missing migration mapping {migration}")

require("Schema::hasTable($table)" in schema, "physical table detection must use Laravel Schema::hasTable")
require("app('migration.repository')" in schema, "migration history must use Laravel migration repository")
require("repositoryExists" in schema and "getRan" in schema, "migration repository must be inspected safely")
require("recorded_missing_tables" in schema, "recorded-but-missing tables must be reported")
require("unrecorded_existing_tables" in schema, "existing tables without migration records must be reported")
require("Log::warning" in schema, "inconsistent schema must log through Laravel logging")
require("42P01" in schema, "PostgreSQL undefined-table state must remain explicitly safe")
require("str_starts_with($this->sqlState($exception), '08')" in schema, "database connection SQLSTATE class must be recognized")
require("delete migration" not in schema.lower(), "schema health code must not manipulate migration history")
require("drop table" not in schema.lower(), "schema health code must not drop tables")

status_pos = runtime.find("$status = $this->schema->status(true);")
ready_stop_pos = runtime.find("if (! $status['schema_ready'])")
require(status_pos >= 0 and ready_stop_pos > status_pos, "ManagerRuntime must check schema before preparation")
for token in (
    "$this->settings->applyToConfig();",
    "$this->closeInterruptedOperations();",
    "$this->sources->ensureOfficial();",
    "$this->legacy->import();",
    "$this->installed->reconcileFilesystem();",
    "$this->pruneLogs();",
):
    pos = runtime.find(token)
    require(pos > ready_stop_pos, f"{token} must occur only after not-ready early return")
require("recoveryMessage" in runtime and "SCHEMA_INCONSISTENT" in runtime, "runtime must provide state-aware mutation recovery message")

for label in (
    "Gaming Hub Manager database is not ready",
    "Schema health",
    "Database available",
    "Migration history available",
    "Missing Manager tables",
    "Pending Manager migrations",
    "Recorded migrations with missing tables",
    "Automatic repair was intentionally not attempted",
):
    require(label in view, f"recovery page missing controlled diagnostic: {label}")
for forbidden in (
    "DB_PASSWORD",
    "DATABASE_URL",
    "PDO(",
    "ExtensionOperation::",
    "InstalledExtension::",
    "ExtensionSource::",
    "PackageBackup::",
):
    require(forbidden not in view, f"recovery view must not access secret/Manager DB content: {forbidden}")

controllers = {
    "src/Controllers/Admin/DashboardController.php": [
        "overview", "installed", "available", "registries", "logs", "backups", "settings", "updateSettings"
    ],
    "src/Controllers/Admin/PackageController.php": ["show", "confirmUninstall", "destroy"],
    "src/Controllers/Admin/ReleaseController.php": ["show"],
    "src/Controllers/Admin/SourceController.php": ["store", "refresh", "toggle", "trust", "destroy"],
    "src/Controllers/Admin/PackageActionController.php": ["install", "update", "reinstall", "enable", "disable", "verify", "backup"],
    "src/Controllers/Admin/BackupController.php": ["restore", "destroy"],
}
for path, methods in controllers.items():
    source = text(path)
    for name in methods:
        body = method_body(source, name)
        require(body != "", f"could not inspect {path}::{name}")
        prepare = body.find("$this->runtime->prepare()")
        indirect = body.find("$this->notReady()")
        guard = min([p for p in (prepare, indirect) if p >= 0], default=-1)
        require(guard >= 0, f"{path}::{name} lacks Manager runtime readiness guard")
        dangerous_positions = [
            p for token in (
                "::query()", "::create(", "->findInstalled(", "->snapshot(", "->resolve(",
                "->install(", "->update(", "->uninstall(", "->restore(", "->delete(", "->ensureOfficial("
            ) if (p := body.find(token)) >= 0
        ]
        if dangerous_positions:
            require(guard < min(dangerous_positions), f"{path}::{name} touches Manager state before readiness guard")

package_action = text("src/Controllers/Admin/PackageActionController.php")
require("private function newOperation" in package_action, "operation creation helper missing")
for action in ("install", "verify", "backup"):
    body = method_body(package_action, action)
    require(body.find("$this->notReady()") < body.find("$this->newOperation("), f"{action} must block before creating operation row")

package_controller = text("src/Controllers/Admin/PackageController.php")
destroy_body = method_body(package_controller, "destroy")
require(destroy_body.find("$this->runtime->prepare()") < destroy_body.find("ExtensionOperation::create("), "uninstall must block before operation row creation")

require((ROOT / "tests/run-m04-schema-health.php").is_file(), "missing executable schema-health regression")
require((ROOT / "tests/run-m04-schema-exception-policy.php").is_file(), "missing executable schema exception-policy regression")
require((ROOT / "tests/run-m04-runtime-guard.php").is_file(), "missing executable runtime-guard regression")

if errors:
    print("FAILED: M0.4 database resilience contracts")
    for error in errors:
        print(f"- {error}")
    sys.exit(1)

print(f"PASS: M0.4 database resilience contracts ({checks} checks)")
