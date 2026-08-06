# Upgrade to 0.1.2

1. Back up the Azuriom database, `plugins/gaming-hub-manager`, and `storage/app/gaming-hub-manager`.
2. Disable Gaming Hub Manager from **Administration → Extensions → Plugins**.
3. Replace only the `plugins/gaming-hub-manager` directory with the directory from the 0.1.2 ZIP.
4. Re-enable Gaming Hub Manager.
5. Clear application and plugin caches:

   ```bash
   php artisan optimize:clear
   php artisan plugin:cache
   ```

6. No database migration is included in 0.1.2. Running `php artisan migrate --force` is safe but not required when the existing Manager migrations already completed.
7. Open **Registries** and refresh the official registry. This also invalidates cached GitHub release metadata.
8. Open **Available Packages** or the installed Core package page. A published matching `v0.7.0` release should be shown as version `0.7.0` even when the registry still contains the deprecated `latest_version: 0.6.6` hint.
9. Inspect the release. The checksum source should display `explicit_checksum_asset`, `github_asset_digest`, or `registry_pinned`.
10. Perform the update normally. A separate `.sha256` upload is not required when the exact selected GitHub asset exposes a valid SHA-256 digest.

Do not modify Gaming Hub Core or Gaming Hub Panel during this Manager upgrade.
