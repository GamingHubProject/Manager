# Admin View Contracts

Gaming Hub Manager reserves Laravel's shared view variables for their framework-defined purpose. In particular, `$errors` is the normal Laravel `ViewErrorBag`; Manager domain failures and diagnostics use `$managerAlerts`.

## Shared partials

### `admin/partials/alerts.blade.php`

- `$errors`: `Illuminate\Support\ViewErrorBag` supplied by Laravel, or absent.
- `$managerAlerts`: absent, an array of strings, an `Illuminate\Support\Collection` of strings, or structured records with `level`, `message`, and optional context-safe `label`.
- flash `success`, `warning`, `error`: optional scalar or `Stringable` message.

The partial verifies types before invoking object methods. Unsupported values are ignored. Text is escaped by Blade and normalized to remove markup, traces, and common secret-bearing token forms.

### `admin/partials/package-warning.blade.php`

No variables.

## Page contracts

### `admin/overview.blade.php`

- `$sources`: `Collection<int, ExtensionSource>`.
- `$installed`: `Collection<int, InstalledExtension>`.
- `$items`: list of normalized catalog records.
- `$updates`: map keyed by package ID.
- `$managerAlerts`: list of Manager alert records.
- `$legacy`: import summary with integer counters and `warnings: list<string>`.
- `$recentOperations`: `Collection<int, ExtensionOperation>`.
- `$backupCount`: integer.
- `$changedCount`: integer.

### `admin/installed.blade.php`

- `$sources`: `Collection<int, ExtensionSource>`.
- `$installed`: `Collection<int, InstalledExtension>`.
- `$items`: list of normalized catalog records.
- `$updates`: map keyed by package ID.
- `$managerAlerts`: list of Manager alert records.

### `admin/available.blade.php`

- `$sources`: `Collection<int, ExtensionSource>`.
- `$installed`: `Collection<int, InstalledExtension>`.
- `$items`: list of normalized catalog records.
- `$updates`: map keyed by package ID.
- `$managerAlerts`: list of Manager alert records.

### `admin/registries.blade.php`

- `$sources`: `Collection<int, ExtensionSource>`.
- `$installed`: `Collection<int, InstalledExtension>`.
- `$items`: list of normalized catalog records.
- `$updates`: map keyed by package ID.
- `$managerAlerts`: list of Manager alert records.

### `admin/logs.blade.php`

- `$operations`: `LengthAwarePaginator<ExtensionOperation>`.

### `admin/backups.blade.php`

- `$backups`: `LengthAwarePaginator<PackageBackup>`.
- `$backupPath`: absolute storage path string intended for administrators.

### `admin/settings.blade.php`

- `$settings`: associative array of normalized Manager setting values.
- `$diagnostics`: associative array containing PHP version, Zip availability, plugin/storage paths, and boolean writability checks.

### `admin/package.blade.php`

- `$extension`: `InstalledExtension`.
- `$enabled`: boolean.
- `$dependents`: list of dependency records with `id` and `constraint`.
- `$catalogItem`: normalized catalog record or `null`.
- `$protectedPackage`: boolean; true for Gaming Hub Manager itself.
- `$backups`: `Collection<int, PackageBackup>`.
- `$operations`: `Collection<int, ExtensionOperation>`.

### `admin/release.blade.php`

- `$source`: `ExtensionSource`.
- `$packageId`: package identifier string.
- `$release`: normalized GitHub release array.
- `$asset`: selected release ZIP asset array.
- `$checksum`: validated SHA-256 string.
- `$checksumSource`: one of `explicit_checksum_asset`, `github_asset_digest`, or `registry_pinned`.
- `$checksumAsset`: selected checksum asset array or `null` when another checksum source is used.
- `$selectedVersion`: semantic version selected from the GitHub release tag.
- `$metadata`: normalized registry/direct-source metadata array including the selected version and exact asset identity.

### `admin/uninstall.blade.php`

- `$extension`: `InstalledExtension`.
- `$enabled`: boolean.
- `$dependents`: list of dependency records with `id` and `constraint`.

## Reserved-variable review

- `$errors`: Laravel validation errors only; never Manager domain data.
- `$message`: not used as a top-level Manager view variable; nested operation/alert records may contain a `message` field.
- `$session`: not used as Manager view data.
- `$request`: not used as Manager view data.
- `$app`: not used as Manager view data.
- `$auth`: not used as Manager view data.
- `$user`: not used as Manager view data.
