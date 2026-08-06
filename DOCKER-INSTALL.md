# Docker Installation

This guide installs Gaming Hub Manager into an existing Azuriom Docker installation.

Gaming Hub Manager is installed manually once.

Afterward, Gaming Hub Core, Gaming Hub Panel, and other supported Gaming Hub packages can be installed and updated from the Azuriom administration panel.

---

## Requirements

You need:

- a working Azuriom Docker installation;
- Docker Compose;
- terminal access to the host;
- an Azuriom application container;
- internet access to GitHub;
- PHP 8.2 or newer;
- the PHP ZIP extension.

This guide assumes:

```text
Azuriom project directory:
~/Azuriom

Azuriom application container:
azuriom_app

Azuriom path inside the container:
/var/www/azuriom

Local Downloads directory:
~/Downloads
```

Your names may be different.

Check your containers with:

```bash
docker ps
```

or:

```bash
docker compose ps
```

---

# Step 1 — Download Gaming Hub Manager

Open:

https://github.com/GamingHubProject/Manager/releases/latest

Under **Assets**, download the packaged release ZIP.

The file name should look similar to:

```text
gaming-hub-manager-v0.1.2.zip
```

The version may be newer.

Do not download:

```text
Source code (zip)
Source code (tar.gz)
```

Use the dedicated release asset.

---

# Step 2 — Open your Azuriom project directory

Example:

```bash
cd ~/Azuriom
```

Confirm the application container is running:

```bash
docker compose ps
```

---

# Step 3 — Automatically select the downloaded Manager ZIP

Run:

```bash
set MANAGER_ZIP (find ~/Downloads \
    -maxdepth 1 \
    -type f \
    -name 'gaming-hub-manager-v*.zip' \
    | sort -V \
    | tail -n 1)

if test -z "$MANAGER_ZIP"
    echo "No Gaming Hub Manager ZIP was found in ~/Downloads"
    exit 1
end

echo "Using: $MANAGER_ZIP"
```

This selects the newest matching Manager release ZIP in your Downloads folder.

---

# Step 4 — Copy the ZIP into the container

Run:

```bash
docker cp "$MANAGER_ZIP" \
  azuriom_app:/tmp/gaming-hub-manager.zip
```

Verify:

```bash
docker exec azuriom_app \
  ls -lh /tmp/gaming-hub-manager.zip
```

---

# Step 5 — Install the Manager plugin files

Run:

```bash
docker exec -u root -it azuriom_app sh -lc '
set -eu

AZURIOM_ROOT="/var/www/azuriom"
PLUGIN_DIR="$AZURIOM_ROOT/plugins/gaming-hub-manager"
STAGING_DIR="/tmp/gaming-hub-manager-install"

rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"

unzip -q /tmp/gaming-hub-manager.zip \
  -d "$STAGING_DIR"

if [ ! -d "$STAGING_DIR/gaming-hub-manager" ]; then
  echo "Installation failed."
  echo "The ZIP does not contain the expected gaming-hub-manager directory."
  exit 1
fi

rm -rf "$PLUGIN_DIR"

mv "$STAGING_DIR/gaming-hub-manager" \
  "$PLUGIN_DIR"

chown -R www-data:www-data \
  "$PLUGIN_DIR"

find "$PLUGIN_DIR" \
  -type d \
  -exec chmod 755 {} \;

find "$PLUGIN_DIR" \
  -type f \
  -exec chmod 644 {} \;

if [ ! -f "$PLUGIN_DIR/plugin.json" ]; then
  echo "Installation failed."
  echo "plugin.json was not found."
  exit 1
fi

echo "Gaming Hub Manager files installed successfully."
'
```

The final directory must be:

```text
/var/www/azuriom/plugins/gaming-hub-manager
```

---

# Step 6 — Run migrations and clear caches

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

# Step 7 — Restart Azuriom

Run:

```bash
cd ~/Azuriom
docker compose restart
```

Wait a few seconds.

---

# Step 8 — Enable Gaming Hub Manager

Open the Azuriom administration panel.

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

A new sidebar section should appear:

```text
Gaming Hub Manager
```

---

# Step 9 — Add the official registry

Open:

```text
Administration
→ Gaming Hub Manager
→ Registries
```

Add:

```text
Name:
GamingHubProject Official Registry
```

```text
URL:
https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json
```

Recommended options:

```text
Enabled:
Yes
```

```text
Trusted:
Yes
```

Save the registry.

Then click:

```text
Refresh
```

---

# Step 10 — Install Gaming Hub Core

Open:

```text
Administration
→ Gaming Hub Manager
→ Available Packages
```

Find:

```text
Gaming Hub Core
```

Review the requirements.

Click:

```text
Install
```

Gaming Hub Manager will:

- resolve the newest compatible GitHub Release;
- select the dedicated release ZIP;
- verify the SHA-256 digest;
- validate the plugin manifest;
- check dependencies;
- install the plugin;
- record the operation.

After installation, enable Gaming Hub Core if Azuriom does not enable it automatically.

---

# Step 11 — Install Gaming Hub Panel

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

Install it after Gaming Hub Core.

Gaming Hub Manager will check the Core requirement automatically.

---

# Updating Gaming Hub Manager

Gaming Hub Manager cannot update itself from inside its own interface.

Download the latest Manager release ZIP and repeat the installation process.

Your Manager database records, registries, logs, and settings are stored outside the plugin directory and should remain available.

Quick update commands:

```bash
MANAGER_ZIP="$(find "$HOME/Downloads" \
  -maxdepth 1 \
  -type f \
  -name 'gaming-hub-manager-v*.zip' \
  | sort -V \
  | tail -n 1)"

docker cp "$MANAGER_ZIP" \
  azuriom_app:/tmp/gaming-hub-manager.zip
```

Then:

```bash
docker exec -u root -it azuriom_app sh -lc '
set -eu

AZURIOM_ROOT="/var/www/azuriom"
PLUGIN_DIR="$AZURIOM_ROOT/plugins/gaming-hub-manager"
STAGING_DIR="/tmp/gaming-hub-manager-install"

rm -rf "$STAGING_DIR"
mkdir -p "$STAGING_DIR"

unzip -q /tmp/gaming-hub-manager.zip \
  -d "$STAGING_DIR"

test -d "$STAGING_DIR/gaming-hub-manager"

rm -rf "$PLUGIN_DIR"

mv "$STAGING_DIR/gaming-hub-manager" \
  "$PLUGIN_DIR"

chown -R www-data:www-data \
  "$PLUGIN_DIR"

find "$PLUGIN_DIR" \
  -type d \
  -exec chmod 755 {} \;

find "$PLUGIN_DIR" \
  -type f \
  -exec chmod 644 {} \;
'
```

Then:

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
cd ~/Azuriom
docker compose restart
```

---

# Troubleshooting

## Container name is different

List containers:

```bash
docker ps --format "table {{.Names}}\t{{.Image}}\t{{.Status}}"
```

Replace:

```text
azuriom_app
```

with your actual application container name.

---

## Azuriom path is different

Check likely paths:

```bash
docker exec -it azuriom_app sh -lc '
find /var/www /app /srv \
  -maxdepth 3 \
  -type f \
  -name artisan \
  2>/dev/null
'
```

The directory containing `artisan` is the Azuriom root.

---

## ZIP not found

Check your Downloads directory:

```bash
find "$HOME/Downloads" \
  -maxdepth 1 \
  -type f \
  -name '*.zip' \
  -print
```

---

## Plugin does not appear

Check the plugin files:

```bash
docker exec azuriom_app \
  ls -la /var/www/azuriom/plugins/gaming-hub-manager
```

Check the manifest:

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

## HTTP 500 error

Check the newest Laravel log:

```bash
docker exec -it azuriom_app sh -lc '
LOG=$(ls -1t \
  /var/www/azuriom/storage/logs/laravel-*.log \
  2>/dev/null \
  | head -n 1)

echo "Using log: $LOG"

tail -n 200 "$LOG"
'
```

---

## Registry does not load

Verify the registry URL:

```bash
curl -i \
  https://raw.githubusercontent.com/GamingHubProject/Registry/main/registry.json
```

Expected:

```text
HTTP/2 200
```

Then refresh the registry inside Gaming Hub Manager.

---

## Package release does not appear

Confirm that the package repository contains:

- a published GitHub Release;
- a semantic version tag such as `v0.7.0`;
- a dedicated release ZIP asset;
- a matching release-asset filename;
- a valid SHA-256 source;
- no draft state.

GitHub source-code archives are ignored.

---

## Manager is installed but not enabled

Open:

```text
Administration
→ Extensions
→ Plugins
```

Enable Gaming Hub Manager manually.

---

# Uninstalling Gaming Hub Manager

Gaming Hub Manager cannot uninstall itself.

Disable it in Azuriom first.

Then run:

```bash
docker exec -u root -it azuriom_app sh -lc '
rm -rf /var/www/azuriom/plugins/gaming-hub-manager

cd /var/www/azuriom

php artisan plugin:clear
php artisan optimize:clear
'
```

Restart:

```bash
docker compose restart
```

Manager database tables, logs, backups, and settings are not removed automatically.

---

# Security

Installed packages execute PHP with the same access as the Azuriom application.

Only install packages from registries and repositories you trust.

Gaming Hub Manager verifies package integrity, but a valid checksum only proves that the downloaded file matches the published file. It does not prove that the package is safe.

---

# Next step

A dedicated fresh-install Docker repository is planned:

```text
GamingHubProject/Docker
```

That repository will provide:

- Azuriom;
- database;
- persistent storage;
- Gaming Hub Manager preinstallation;
- automatic Manager release download;
- beginner-friendly Docker Compose setup.
