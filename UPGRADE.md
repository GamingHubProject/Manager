# Upgrade to 0.1.3

1. Back up the Azuriom database, `plugins/gaming-hub-manager`, and `storage/app/gaming-hub-manager`.
2. Disable Gaming Hub Manager from **Administration → Extensions → Plugins**.
3. Replace only the `plugins/gaming-hub-manager` directory with the directory from the 0.1.3 ZIP.
4. Re-enable Gaming Hub Manager.
5. Run the existing Manager migrations. Version 0.1.3 adds no new migration, but this repairs installations where one or more Manager tables were never created or were removed:

   ```bash
   php artisan migrate --force
   ```

6. Clear application and plugin caches:

   ```bash
   php artisan optimize:clear
   php artisan plugin:cache
   ```

7. Open **Registries**. The protected default must be named **GamingHubProject Official Registry** and use:

   ```text
   https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json
   ```

8. Confirm that no legacy Core source was created unless actual non-empty Core installer metadata exists.
9. Open **Installed Packages**. Every listed package must have a matching plugin directory with a valid `plugin.json`; stale rows are removed during normal reconciliation.
10. Refresh the official registry to invalidate cached registry and GitHub release metadata.

Existing user-created custom registries are not rewritten. Gaming Hub Core and Gaming Hub Panel are not modified by this upgrade.
