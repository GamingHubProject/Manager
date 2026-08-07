# Root Architecture

```text
Azuriom
├── Gaming Hub Manager
│   ├── registries and GitHub Releases
│   ├── install/update/reinstall/uninstall
│   ├── backup/rollback/integrity
│   └── operation logs and settings
├── Gaming Hub Core
│   ├── games/clusters/servers
│   ├── providers
│   ├── public pages
│   └── Shared Data Gateway
├── Gaming Hub Panel
├── Gaming Hub Palworld
├── Gaming Hub ARK
└── future packages
```

## Independence boundary

Manager has no class, service-provider, route, database, or container dependency on Gaming Hub Core. Core is treated like any other managed package. The only Core-specific behavior is optional legacy metadata import and optional `gaming-hub-core` version constraints in package manifests.

## Components

- `ExtensionSourceManager`: official/custom registry cache and coordinated GitHub release-cache invalidation.
- `PackageCatalog`: reconciles the filesystem before merging enabled sources with installed package records; selected GitHub release tags provide available versions while deprecated registry hints are fallback-only.
- `GitHubReleaseClient`: selects the highest semantic published release with a matching uploaded ZIP asset and enforces stable/prerelease policy.
- `PackageReleaseResolver`: resolves the exact GitHub release/asset and checksum source in explicit-file → GitHub asset digest → exact registry pin order.
- `GitHubAssetDigestValidator` / `RegistryChecksumResolver`: validate exact selected-asset checksum bindings.
- `ReleaseVersionValidator`: enforces GitHub tag, versioned asset filename, `plugin.json`, and package-manifest consistency.
- `ExtensionArchiveInspector`: validates and extracts a one-root Azuriom plugin ZIP.
- `ExtensionManifestValidator`: normalizes modern Manager manifests or synthesizes one from `plugin.json` plus registry metadata.
- `ExtensionCompatibility` / `ExtensionDependencyGuard`: enforce Azuriom, PHP, Core, and package requirements.
- `ExtensionInstaller`: transactional install/update/reinstall with same-filesystem swaps and automatic file rollback.
- `ExtensionUninstaller`: dependency-safe file uninstall with a verified recovery backup and retained data.
- `BackupManager`: manual/pre-update/pre-uninstall/pre-rollback backups and verified restoration.
- `InstalledExtensionResolver`: makes package directories and valid `plugin.json` manifests authoritative, removes stale installed rows, discovers existing Gaming Hub packages including Manager's own installed presence, and reconciles supplemental metadata. Manager self-modification remains blocked.
- `LegacyMetadataImporter`: idempotent, non-destructive bridge that runs only after non-empty legitimate Core installer metadata or a validated legacy backup is detected.
- `DirectoryHasher`: deterministic integrity baseline over package files.
- `ManagerSchema`: checks database availability and all five Manager tables, including PostgreSQL missing-table handling, without suppressing unrelated schema exceptions.
- `ManagerRuntime`: schema-gated one-request preparation, interrupted-operation closure, official-source bootstrap, reconciliation, staging cleanup, and log retention.

## Persistence

Manager owns only:

- `gaminghub_manager_sources`
- `gaminghub_manager_packages`
- `gaminghub_manager_operations`
- `gaminghub_manager_backups`
- `gaminghub_manager_settings`
- `storage/app/gaming-hub-manager/`

## Lifecycle sequence

### Install

Discover authoritative GitHub release → resolve exact asset/checksum source → download → checksum/tag/asset/archive/manifest validation → compatibility/dependency validation → same-filesystem staging → atomic move → migrations → optional enable → metadata/integrity baseline → cache refresh.

### Update/reinstall

Resolve installed state → validate candidate → create verified backup → disable if needed → atomic old/new directory swap → migrations → restore enabled state → metadata/integrity update. A failure restores previous files and state where possible.

### Uninstall

Dependency guard → verified backup → disable → guarded directory move → metadata removal → cache refresh → guarded directory deletion. Package database data and migrations are retained.

### Rollback

Verify backup hash/manifest → create current-state recovery backup → stage backup → disable current package → atomic replacement → restore captured enabled state → update Manager metadata. Database migrations are not reversed.


## Migration readiness boundary

Every Manager administration entry checks `ManagerSchema` before querying Manager models. When the connection or schema is incomplete, runtime preparation is skipped and the administration renders a migration-required warning. Route parameters are resolved only after this check, preventing implicit model binding from querying absent tables.

## Admin view boundary

Laravel's shared `$errors` variable remains the validation `ViewErrorBag`. Catalog, registry, legacy-import, and diagnostic messages use explicit Manager contracts such as `$managerAlerts` and `warnings`. The supported Azuriom admin sidebar entry is the sole primary Manager navigation; page views do not render a duplicate tab bar or duplicate layout heading.
