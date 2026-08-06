# Gaming Hub Manager

Gaming Hub Manager is the standalone package manager for the Gaming Hub ecosystem on Azuriom.

It installs, updates, verifies, backs up, restores, enables, disables, and uninstalls supported Gaming Hub packages directly from the Azuriom administration panel.

Gaming Hub Manager does **not** require Gaming Hub Core.

Once the Manager is installed manually, other Gaming Hub packages can be installed from the web interface.

---

## Features

- Install Gaming Hub packages from trusted registries
- Discover published GitHub Releases automatically
- Detect available updates
- Verify package SHA-256 checksums
- Create backups before updates and removals
- Restore package files from backups
- Enable and disable installed packages
- Check installed-file integrity
- View install and update logs
- Resolve package requirements and dependencies
- Import legacy package metadata from older Gaming Hub Core versions

---

## Requirements

Before installing Gaming Hub Manager, make sure your server has:

- Azuriom
- PHP 8.2 or newer
- PHP ZIP extension
- Internet access to GitHub
- Write access to the Azuriom plugin and storage directories
- Access to the server terminal, SSH, Docker console, or hosting file manager

---

# Installation

Gaming Hub Manager is the only Gaming Hub package that must currently be installed manually.

After installation, Gaming Hub Core and supported extensions can be installed through the Manager interface.

---

## Step 1 — Download Gaming Hub Manager

Open the latest release page:

https://github.com/RosesOfDorns/GamingHub-Manager/releases/latest

Under **Assets**, download the dedicated release file named similar to:

```text
gaming-hub-manager-v0.1.2.zip
```

The version number may be newer.

Do **not** download:

```text
Source code (zip)
Source code (tar.gz)
```

Those are automatic GitHub source archives and are not the packaged Azuriom plugin.

Use the ZIP file uploaded under the release assets.

---

# Docker installation

Use this section when Azuriom runs in Docker or Docker Compose.

The commands below assume:

```text
Azuriom container name:
azuriom_app

Azuriom directory inside the container:
/var/www/azuriom

Local download directory:
~/Downloads
```

---

## Step 2 — Open the folder containing your Docker Compose project

Example:

```bash
cd ~/Azuriom
```

Check the running containers:

```bash
docker compose ps
```

You should see your Azuriom application container.

The examples below use:

```text
azuriom_app
```

---

## Step 3 — Automatically find the downloaded Manager ZIP

Run:

```bash
MANAGER_ZIP="$(find "$HOME/Downloads" -maxdepth 1 -type f -name 'gaming-hub-manager-v*.zip' | sort -V | tail -n 1)"

if [ -z "$MANAGER_ZIP" ]; then
  echo "Gaming Hub Manager ZIP was not found in ~/Downloads"
  exit 1
fi

echo "Using: $MANAGER_ZIP"
```

This automatically selects the newest matching Manager ZIP from your Downloads folder.

---

## Step 4 — Copy the ZIP into the Azuriom container

Run:

```bash
docker cp "$MANAGER_ZIP" azuriom_app:/tmp/gaming-hub-manager.zip
```

Confirm that the ZIP exists inside the container:

```bash
docker exec azuriom_app \
  ls -lh /tmp/gaming-hub-manager.zip
```

---

## Step 5 — Install the Manager files

Run:

```bash
docker exec -u root -it azuriom_app sh -lc '
set -eu

cd /var/www/azuriom/plugins

rm -rf gaming-hub-manager

mkdir -p /tmp/gaming-hub-manager-install
rm -rf /tmp/gaming-hub-manager-install/*

unzip -q /tmp/gaming-hub-manager.zip \
  -d /tmp/gaming-hub-manager-install

if [ -d /tmp/gaming-hub-manager-install/gaming-hub-manager ]; then
  mv /tmp/gaming-hub-manager-install/gaming-hub-manager \
    /var/www/azuriom/plugins/gaming-hub-manager
else
  echo "The ZIP did not contain the expected gaming-hub-manager directory."
  exit 1
fi

chown -R www-data:www-data \
  /var/www/azuriom/plugins/gaming-hub-manager

find /var/www/azuriom/plugins/gaming-hub-manager \
  -type d -exec chmod 755 {} \;

find /var/www/azuriom/plugins/gaming-hub-manager \
  -type f -exec chmod 644 {} \;

test -f /var/www/azuriom/plugins/gaming-hub-manager/plugin.json

echo "Gaming Hub Manager files installed successfully."
'
```

The installed plugin directory should now be:

```text
/var/www/azuriom/plugins/gaming-hub-manager
```

---

## Step 6 — Run migrations and clear Azuriom caches

Run:

```bash
docker exec -it azuriom_app sh -lc '
set -eu

cd /var/www/azuriom

php artisan migrate --force
php artisan plugin:clear
php artisan optimize:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear

echo "Migrations and cache cleanup completed."
'
```

---

## Step 7 — Restart Azuriom

Run from the directory containing your Compose file:

```bash
docker compose restart
```

Wait a few seconds for Azuriom to start again.

---

## Step 8 — Enable Gaming Hub Manager

Open your Azuriom administration panel.

Go to:

```text
Administration
→ Extensions
→ Plugins
```

Find:

```text
Gaming Hub Manager
```

Enable it.

A new administration section named **Gaming Hub Manager** should appear in the sidebar.

---

# Non-Docker installation

Use this section when Azuriom is installed directly on the server without Docker.

---

## Step 1 — Locate the Azuriom directory

Typical examples:

```text
/var/www/azuriom
/var/www/html
/home/example/public_html
```

The correct directory contains files such as:

```text
artisan
composer.json
plugins
storage
```

---

## Step 2 — Extract the Manager ZIP

Extract the downloaded release ZIP into the Azuriom `plugins` directory.

The final structure must be:

```text
plugins/
└── gaming-hub-manager/
    ├── plugin.json
    ├── src/
    ├── resources/
    └── database/
```

Do not leave the plugin inside an extra nested directory such as:

```text
plugins/gaming-hub-manager-v0.1.2/gaming-hub-manager
```

The directory must be named exactly:

```text
gaming-hub-manager
```

---

## Step 3 — Set permissions

From the Azuriom root directory, adjust the web-server user when needed:

```bash
sudo chown -R www-data:www-data plugins/gaming-hub-manager
sudo find plugins/gaming-hub-manager -type d -exec chmod 755 {} \;
sudo find plugins/gaming-hub-manager -type f -exec chmod 644 {} \;
```

On some systems the web-server user may be:

```text
nginx
apache
httpd
```

Use the user that owns the rest of your Azuriom files.

---

## Step 4 — Run migrations and clear caches

From the Azuriom root directory:

```bash
php artisan migrate --force
php artisan plugin:clear
php artisan optimize:clear
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
```

---

## Step 5 — Enable the Manager

Open:

```text
Administration
→ Extensions
→ Plugins
```

Enable:

```text
Gaming Hub Manager
```

---

# First setup

After enabling the Manager, open:

```text
Administration
→ Gaming Hub Manager
→ Registries
```

Add the official Gaming Hub registry.

Use:

```text
Name:
RosesOfDorns Official Registry
```

```text
Registry URL:
https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-registry/main/registry.json
```

Recommended settings:

```text
Enabled:
Yes
```

```text
Trusted:
Yes
```

Only mark a registry as trusted when you trust its owner and the packages it publishes.

Save the registry.

Then click:

```text
Refresh
```

---

# Install Gaming Hub Core

After refreshing the registry, open:

```text
Administration
→ Gaming Hub Manager
→ Available Packages
```

Find:

```text
Gaming Hub Core
```

The Manager will display package information such as:

- version
- required PHP version
- required Azuriom version
- package dependencies
- repository
- release asset
- integrity status

Click:

```text
Install
```

Gaming Hub Manager will:

1. resolve the newest compatible release;
2. select the correct GitHub release asset;
3. verify the SHA-256 digest;
4. inspect the package manifest;
5. validate requirements;
6. install the plugin files;
7. run the supported plugin lifecycle;
8. record the operation in Install Logs.

After installation, enable Gaming Hub Core through Azuriom's Plugins page if it is not already enabled.

---

# Install Gaming Hub Panel

Open:

```text
Administration
→ Gaming Hub Manager
→ Available Packages
```

Find:

```text
Gaming Hub Panel
```

The Manager will show its dependency on Gaming Hub Core.

Install Core first when required.

Then click:

```text
Install
```

---

# Updating packages

Open:

```text
Administration
→ Gaming Hub Manager
→ Installed Packages
```

When a newer compatible version is available, the package will display an update action.

Click:

```text
Update
```

Before changing the installed package, the Manager creates a recovery backup when supported.

The update process verifies:

- release version
- package identity
- release asset
- SHA-256 digest
- package manifest
- dependencies
- installed version

The Manager does not silently install GitHub source archives.

---

# Updating Gaming Hub Manager itself

Gaming Hub Manager cannot update itself from inside its own interface.

Update it manually using the same installation process.

For Docker installations:

```bash
MANAGER_ZIP="$(find "$HOME/Downloads" -maxdepth 1 -type f -name 'gaming-hub-manager-v*.zip' | sort -V | tail -n 1)"

docker cp "$MANAGER_ZIP" \
  azuriom_app:/tmp/gaming-hub-manager.zip
```

Then replace the plugin files:

```bash
docker exec -u root -it azuriom_app sh -lc '
set -eu

cd /var/www/azuriom/plugins

rm -rf gaming-hub-manager

rm -rf /tmp/gaming-hub-manager-install
mkdir -p /tmp/gaming-hub-manager-install

unzip -q /tmp/gaming-hub-manager.zip \
  -d /tmp/gaming-hub-manager-install

mv /tmp/gaming-hub-manager-install/gaming-hub-manager \
  /var/www/azuriom/plugins/gaming-hub-manager

chown -R www-data:www-data \
  /var/www/azuriom/plugins/gaming-hub-manager

find /var/www/azuriom/plugins/gaming-hub-manager \
  -type d -exec chmod 755 {} \;

find /var/www/azuriom/plugins/gaming-hub-manager \
  -type f -exec chmod 644 {} \;
'
```

Then run:

```bash
docker exec -it azuriom_app sh -lc '
cd /var/www/azuriom

php artisan migrate --force
php artisan plugin:clear
php artisan optimize:clear
'
```

Restart:

```bash
docker compose restart
```

Manager settings, registries, operation logs, and database records are not stored inside the plugin directory and should remain available.

---

# Backups and rollback

Open:

```text
Administration
→ Gaming Hub Manager
→ Backups
```

Backups may be created before:

- updates
- reinstalls
- uninstalls
- manual backup actions

A backup can restore package files and the captured package state.

Important limitation:

> Rollback restores plugin files and Manager metadata. It does not reverse database migrations performed by another package.

Package-owned database data is intentionally retained during uninstall so that package files can be restored.

---

# Installed-file verification

Open:

```text
Administration
→ Gaming Hub Manager
→ Installed Packages
```

Use the package verification action to check whether installed files still match the recorded installation state.

This can detect:

- missing files
- modified files
- additional files
- corrupted installations

---

# Install logs

Open:

```text
Administration
→ Gaming Hub Manager
→ Install Logs
```

Logs include lifecycle stages such as:

```text
queued
resolving
downloading
validating
backing up
installing
updating
rolling back
completed
failed
```

Sensitive credentials and raw secret values should not appear in operation logs.

---

# Security notice

Installed Azuriom packages execute PHP with the same access as the Azuriom installation.

Only install packages from registries and repositories you trust.

Gaming Hub Manager applies protections including:

- HTTPS-only remote sources
- GitHub release validation
- release asset matching
- draft-release rejection
- source-archive rejection
- SHA-256 verification
- redirect host validation
- archive size limits
- extracted-file limits
- path-traversal protection
- symlink rejection
- dependency checks
- backup and rollback support

A valid checksum proves that the downloaded file matches the published file.

It does not prove that the package itself is safe.

---

# Troubleshooting

## The Manager does not appear in Plugins

Verify the installed directory:

```bash
docker exec azuriom_app \
  ls -la /var/www/azuriom/plugins/gaming-hub-manager
```

Verify the plugin manifest:

```bash
docker exec azuriom_app \
  cat /var/www/azuriom/plugins/gaming-hub-manager/plugin.json
```

Clear caches:

```bash
docker exec -it azuriom_app sh -lc '
cd /var/www/azuriom

php artisan plugin:clear
php artisan optimize:clear
'
```

Restart:

```bash
docker compose restart
```

---

## The Manager page returns HTTP 500

Check the latest Laravel log:

```bash
docker exec -it azuriom_app sh -lc '
LOG=$(ls -1t /var/www/azuriom/storage/logs/laravel-*.log 2>/dev/null | head -n 1)

echo "Using log: $LOG"
tail -n 200 "$LOG"
'
```

---

## The registry returns HTTP 404

Open the raw registry address in your browser:

```text
https://raw.githubusercontent.com/RosesOfDorns/gaming-hub-registry/main/registry.json
```

It should display JSON.

Then return to:

```text
Gaming Hub Manager
→ Registries
→ Refresh
```

---

## No packages appear

Check that:

- the registry is enabled;
- the registry is trusted when required;
- the registry URL is correct;
- the server can access GitHub;
- the registry refresh succeeded.

Review the registry warning shown in the Manager interface.

---

## A new release does not appear

Check that the package repository contains:

- a published GitHub Release;
- a semantic version tag such as `v0.7.0`;
- a dedicated packaged ZIP asset;
- an asset filename matching the registry pattern;
- a supported SHA-256 digest or checksum source.

Draft releases are ignored.

Prereleases are ignored unless the package or registry allows them.

GitHub's automatic source-code ZIPs are ignored.

Refresh the registry after publishing a release.

---

## Checksum verification fails

Do not disable checksum verification.

Confirm that:

- the selected ZIP is the dedicated release asset;
- the GitHub asset digest belongs to the exact ZIP;
- the local or downloaded ZIP was not replaced;
- the release asset was not changed after publication.

Publish a new release version instead of replacing an already published asset whenever possible.

---

## Package requirements are not satisfied

The Manager may block installation when requirements are missing.

Examples:

```text
PHP version too old
Azuriom version incompatible
Required package missing
Required PHP extension missing
Installed dependency version incompatible
```

Install or update the required dependency first.

---

# Uninstalling a package

Open:

```text
Administration
→ Gaming Hub Manager
→ Installed Packages
```

Select the package and use:

```text
Uninstall
```

The Manager checks package dependencies before removal.

Package database data may be retained intentionally.

---

# Removing Gaming Hub Manager

Gaming Hub Manager cannot uninstall itself.

To remove it:

1. Disable Gaming Hub Manager in Azuriom.
2. Remove the plugin directory.
3. Clear Azuriom caches.
4. Restart Azuriom.

Docker:

```bash
docker exec -u root -it azuriom_app sh -lc '
rm -rf /var/www/azuriom/plugins/gaming-hub-manager

cd /var/www/azuriom
php artisan plugin:clear
php artisan optimize:clear
'
```

Then:

```bash
docker compose restart
```

Manager database tables, retained backups, and operation history are not automatically deleted.

---

# Administration pages

Gaming Hub Manager adds these administration pages:

```text
Overview
Installed Packages
Available Packages
Registries
Install Logs
Backups
Settings
```

---

# Package compatibility

Gaming Hub Manager accepts packaged Azuriom plugins containing:

```text
one plugin root directory
valid plugin.json
```

A Gaming Hub package may additionally include:

```text
gaming-hub-extension.json
```

This file can define:

- package type
- version
- requirements
- dependencies
- package declarations
- compatibility information

---

# Support

Report bugs through GitHub Issues:

https://github.com/RosesOfDorns/GamingHub-Manager/issues

Releases:

https://github.com/RosesOfDorns/GamingHub-Manager/releases

Official registry:

https://github.com/RosesOfDorns/gaming-hub-registry

---

# License

See the repository license file for the applicable terms.
