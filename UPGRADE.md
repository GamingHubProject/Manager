# Upgrade to 0.1.5

Gaming Hub Manager 0.1.5 finalizes M0.4 database resilience and M0.5 package-lifecycle verification without adding a new Manager database migration.

1. Back up the Azuriom database, `plugins/gaming-hub-manager`, and `storage/app/gaming-hub-manager`.
2. Disable Gaming Hub Manager from **Administration → Extensions → Plugins**.
3. Replace only the `plugins/gaming-hub-manager` directory with the directory from `gaming-hub-manager-v0.1.5.zip`.
4. Re-enable Gaming Hub Manager.
5. Run the normal Azuriom migrations:

   ```bash
   php artisan migrate --force
   ```

   Version 0.1.5 does not add a new Manager migration. If migration history says a Manager migration already ran while its physical table is missing, Manager intentionally reports `SCHEMA_INCONSISTENT` instead of rewriting migration history or recreating tables automatically.

6. Clear application and plugin caches:

   ```bash
   php artisan optimize:clear
   php artisan plugin:cache
   ```

7. Open **Gaming Hub Manager → Overview** and confirm schema health is `READY`.
8. Open **Registries** and confirm exactly one protected **GamingHubProject Official Registry** using:

   ```text
   https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json
   ```

9. Confirm the official catalog contains the current packages:
   - `gaming-hub-core` → `https://github.com/GamingHubProject/Core`
   - `gaming-hub-panel` → `https://github.com/GamingHubProject/Panel`
10. Open **Installed Packages**. Filesystem manifests remain authoritative; valid manual installations are discovered and stale Manager rows are reconciled.
11. For a disposable test environment, verify Core → Panel consecutive installation, dependency protection, reinstall/update rollback, backup restore, and uninstall before rolling the release into production.

Existing administrator-created custom registries are preserved. Gaming Hub Core, Gaming Hub Panel, and Azuriom core are not modified by this Manager upgrade.
