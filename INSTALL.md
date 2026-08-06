# Installation

## Requirements

- an Azuriom installation compatible with plugin API 1.2.0;
- PHP 8.2 or newer;
- PHP Zip extension (`ext-zip`);
- outbound HTTPS access to the configured registry and GitHub Releases;
- write access to Azuriom's `plugins` directory and `storage/app`.

## Install

1. Back up the Azuriom database and files.
2. Extract the release ZIP. It contains one directory named `gaming-hub-manager`.
3. Copy that directory to Azuriom's `plugins/` directory.
4. In Azuriom Administration, open **Extensions/Plugins** and enable **Gaming Hub Manager**.
5. Run normal Azuriom migrations if the installation does not run plugin migrations automatically:

   ```bash
   php artisan migrate --force
   ```

6. Clear Azuriom caches:

   ```bash
   php artisan optimize:clear
   php artisan plugin:cache
   ```

7. Open **Administration → Gaming Hub Manager → Settings** and verify that the plugin and storage directories are writable.
8. Open **Overview**. Existing Gaming Hub Core installer metadata is imported automatically when the legacy tables exist.

## Existing Gaming Hub Core installations

Core remains enabled and unchanged. Manager reads the legacy installer tables and old backup directory, copies compatible metadata into its own tables/storage, and discovers installed `gaming-hub-*` plugin directories. It never drops or edits Core's legacy installer tables.

Do not remove Core's existing installer UI until a later Core release explicitly replaces it with an “Open Gaming Hub Manager” link.

## Uninstalling Manager

Disable Manager first. Removing Manager does not remove any managed package. Manager's own database tables and `storage/app/gaming-hub-manager` backups should be retained until they are no longer needed.


## Upgrading

Replace only the `gaming-hub-manager` plugin directory, re-enable the plugin, and clear Azuriom caches. Version 0.1.2 adds no migration. Refresh a registry after upgrading to invalidate its GitHub release cache. See [UPGRADE.md](UPGRADE.md) for the full checklist.
