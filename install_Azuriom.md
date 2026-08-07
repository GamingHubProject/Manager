# Fresh Docker Installation

This guide installs:

- the official **Azuriom 1.2.12** release;
- PostgreSQL through Azuriom's official Docker Compose setup;
- the latest stable packaged **Gaming Hub Manager** release as a separate Azuriom plugin.

Azuriom is downloaded directly from the official Azuriom GitHub release. Its original project files, name, copyright notices, and `LICENSE` file remain intact. Gaming Hub Manager is installed only under `plugins/gaming-hub-manager`.

## Before starting

The installer uses this permanent directory:

```text
/opt/azuriom
```

It asks for:

- the public HTTP port;
- the PostgreSQL database name;
- the PostgreSQL username;
- the PostgreSQL password, or it generates one automatically.

The installer saves the selected/generated database credentials immediately to:

```text
~/.azuriom-install-credentials
```

The file is created with permission mode `600`, so the values can be recovered if the terminal closes during installation.

The installer also:

- refuses to overwrite an existing `/opt/azuriom` directory;
- checks whether the selected HTTP port is already in use;
- keeps a copy of the original Compose file as `docker-compose.yml.upstream`;
- configures the selected port and database values;
- downloads only the packaged `gaming-hub-manager-v*.zip` release asset;
- ignores GitHub source-code archives;
- grants the Azuriom container the write permissions required by the browser installer;
- validates the Compose configuration before starting it.

---

## CachyOS / Arch Linux with Fish

Paste the complete block into a Fish terminal:

```fish
#!/usr/bin/env fish

set -g AZURIOM_VERSION 1.2.12
set -g INSTALL_DIR /opt/azuriom
set -g AZURIOM_ZIP_URL "https://github.com/Azuriom/Azuriom/releases/download/v$AZURIOM_VERSION/Azuriom-$AZURIOM_VERSION.zip"
set -g MANAGER_API_URL https://api.github.com/repos/GamingHubProject/Manager/releases/latest

function fail
    echo "ERROR: $argv" >&2
    exit 1
end

echo "Gaming Hub Manager — official Azuriom $AZURIOM_VERSION Docker installer"
echo "Azuriom will be downloaded directly from the official Azuriom GitHub release."
echo

echo "Installing required terminal tools..."
sudo pacman -S --needed --noconfirm ca-certificates curl unzip python acl openssl
or fail "Package installation failed."

if not command -q docker
    echo "Docker is not installed; installing Docker and Docker Compose..."
    sudo pacman -S --needed --noconfirm docker docker-compose
    or fail "Docker installation failed."
else if not docker compose version >/dev/null 2>&1; and not sudo docker compose version >/dev/null 2>&1
    echo "Docker Compose is missing; installing it..."
    sudo pacman -S --needed --noconfirm docker-compose
    or fail "Docker Compose installation failed."
end

sudo systemctl enable --now docker
or fail "Docker could not be started."

if docker info >/dev/null 2>&1
    set -g DOCKER docker
else if sudo docker info >/dev/null 2>&1
    set -g DOCKER sudo docker
else
    fail "Docker is installed but the Docker daemon is not available."
end

while true
    read -P "Public HTTP port [8086]: " APP_PORT
    if test -z "$APP_PORT"
        set APP_PORT 8086
    end

    if not string match -rq '^[0-9]+$' -- "$APP_PORT"
        echo "Enter a port from 1 to 65535."
        continue
    end

    if test "$APP_PORT" -lt 1; or test "$APP_PORT" -gt 65535
        echo "Enter a port from 1 to 65535."
        continue
    end

    if command -q ss
        set PORT_IN_USE (ss -ltnH "sport = :$APP_PORT" 2>/dev/null)
        if test (count $PORT_IN_USE) -gt 0
            echo "Port $APP_PORT is already in use. Choose another port."
            continue
        end
    end

    break
end

while true
    read -P "PostgreSQL database name [azuriom]: " POSTGRES_DB
    if test -z "$POSTGRES_DB"
        set POSTGRES_DB azuriom
    end

    if string match -rq '^[A-Za-z0-9_]+$' -- "$POSTGRES_DB"
        break
    end

    echo "Use only letters, numbers, and underscores."
end

while true
    read -P "PostgreSQL username [azuriom]: " POSTGRES_USER
    if test -z "$POSTGRES_USER"
        set POSTGRES_USER azuriom
    end

    if string match -rq '^[A-Za-z0-9_]+$' -- "$POSTGRES_USER"
        break
    end

    echo "Use only letters, numbers, and underscores."
end

while true
    read -s -P "PostgreSQL password (leave empty to generate one): " POSTGRES_PASSWORD
    echo

    if test -z "$POSTGRES_PASSWORD"
        set POSTGRES_PASSWORD (openssl rand -hex 32)
        break
    end

    if string match -rq '^[A-Za-z0-9._~-]{16,}$' -- "$POSTGRES_PASSWORD"
        break
    end

    echo "Use at least 16 characters containing only letters, numbers, dot, underscore, tilde, or hyphen."
end

set -g CREDENTIAL_FILE "$HOME/.azuriom-install-credentials"
umask 077
printf 'APP_PORT=%s\nPOSTGRES_DB=%s\nPOSTGRES_USER=%s\nPOSTGRES_PASSWORD=%s\n' \
    "$APP_PORT" \
    "$POSTGRES_DB" \
    "$POSTGRES_USER" \
    "$POSTGRES_PASSWORD" \
    > "$CREDENTIAL_FILE"
chmod 600 "$CREDENTIAL_FILE"
echo "Credentials saved immediately to $CREDENTIAL_FILE"
echo

if test -e "$INSTALL_DIR"
    fail "$INSTALL_DIR already exists. Move or delete it before running this fresh installer."
end

set -g TMP_DIR (mktemp -d)
function cleanup_temp --on-event fish_exit
    if set -q TMP_DIR; and test -n "$TMP_DIR"
        rm -rf "$TMP_DIR"
    end
end

echo "Downloading official Azuriom $AZURIOM_VERSION release..."
curl -fL --retry 3 --retry-delay 2 "$AZURIOM_ZIP_URL" -o "$TMP_DIR/azuriom.zip"
or fail "Azuriom download failed."

mkdir -p "$TMP_DIR/azuriom"
unzip -q "$TMP_DIR/azuriom.zip" -d "$TMP_DIR/azuriom"
or fail "Azuriom extraction failed."

set ARTISAN_PATH (find "$TMP_DIR/azuriom" -maxdepth 3 -type f -name artisan -print -quit)
test -n "$ARTISAN_PATH"
or fail "The official Azuriom archive did not contain artisan."

set AZURIOM_ROOT (dirname "$ARTISAN_PATH")
sudo mkdir -p "$INSTALL_DIR"
sudo cp -a "$AZURIOM_ROOT/." "$INSTALL_DIR/"
sudo chown -R (id -u):(id -g) "$INSTALL_DIR"

cd "$INSTALL_DIR"
or fail "Could not enter $INSTALL_DIR."

test -f docker-compose.yml
or fail "docker-compose.yml is missing after extraction."
test -f docker/nginx.conf
or fail "docker/nginx.conf is missing after extraction."
test -f .env.example
or fail ".env.example is missing after extraction."
test -f LICENSE
or fail "The official Azuriom LICENSE file is missing after extraction."

cp docker-compose.yml docker-compose.yml.upstream
cp .env.example .env

sed -i \
    -e 's|"8000:80"|"${APP_PORT}:80"|' \
    -e 's|- DB_DATABASE=azuriom|- DB_DATABASE=${POSTGRES_DB}|' \
    -e 's|- DB_USERNAME=azuriom|- DB_USERNAME=${POSTGRES_USER}|' \
    -e 's|- DB_PASSWORD=password|- DB_PASSWORD=${POSTGRES_PASSWORD}|' \
    -e 's|POSTGRES_DB: azuriom|POSTGRES_DB: ${POSTGRES_DB}|' \
    -e 's|POSTGRES_USER: azuriom|POSTGRES_USER: ${POSTGRES_USER}|' \
    -e 's|POSTGRES_PASSWORD: password|POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}|' \
    docker-compose.yml
or fail "Could not apply Docker Compose settings."

for REQUIRED in '${APP_PORT}' '${POSTGRES_DB}' '${POSTGRES_USER}' '${POSTGRES_PASSWORD}'
    grep -qF "$REQUIRED" docker-compose.yml
    or fail "Could not apply required Compose setting: $REQUIRED"
end

printf '\n# Docker Compose installer settings\nAPP_PORT=%s\nPOSTGRES_DB=%s\nPOSTGRES_USER=%s\nPOSTGRES_PASSWORD=%s\n' \
    "$APP_PORT" "$POSTGRES_DB" "$POSTGRES_USER" "$POSTGRES_PASSWORD" >> .env
chmod 600 .env

echo "Downloading the latest stable packaged Gaming Hub Manager release..."
set MANAGER_URL (
    curl -fsSL --retry 3 --retry-delay 2 \
        -H 'Accept: application/vnd.github+json' \
        -H 'X-GitHub-Api-Version: 2022-11-28' \
        -H 'User-Agent: Gaming-Hub-Manager-Installer' \
        "$MANAGER_API_URL" \
    | python -c 'import json,re,sys; data=json.load(sys.stdin); assets=[a["browser_download_url"] for a in data.get("assets",[]) if a.get("state")=="uploaded" and re.fullmatch(r"gaming-hub-manager-v[^/]+\.zip",a.get("name",""))]; sys.stdout.write(assets[0] if len(assets)==1 else ""); sys.exit(0 if len(assets)==1 else 1)'
)
or fail "GitHub did not return exactly one packaged gaming-hub-manager-v*.zip asset."

curl -fL --retry 3 --retry-delay 2 "$MANAGER_URL" -o "$TMP_DIR/manager.zip"
or fail "Manager download failed."

mkdir -p "$TMP_DIR/manager"
unzip -q "$TMP_DIR/manager.zip" -d "$TMP_DIR/manager"
or fail "Manager extraction failed."

test -f "$TMP_DIR/manager/gaming-hub-manager/plugin.json"
or fail "The Manager archive has an unexpected structure."

mkdir -p plugins
cp -a "$TMP_DIR/manager/gaming-hub-manager" plugins/gaming-hub-manager

echo "Building the official Azuriom application container..."
$DOCKER compose build app
or fail "The Azuriom application image could not be built."

set WWW_UID ($DOCKER compose run --rm --no-deps --entrypoint sh app -c 'id -u www-data' | tail -n 1 | string trim)
string match -rq '^[0-9]+$' -- "$WWW_UID"
or fail "Could not determine the container www-data UID."

sudo setfacl -R -m "u:$WWW_UID:rwX" "$INSTALL_DIR"
or fail "Could not grant the container write access."

sudo find "$INSTALL_DIR" -type d -exec setfacl -m "d:u:$WWW_UID:rwX" '{}' +
or fail "Could not set inherited directory permissions."

$DOCKER compose config >/dev/null
or fail "Docker Compose validation failed."

$DOCKER compose up -d
or fail "Docker Compose startup failed."

echo
echo "Installation started successfully."
echo "Open: http://SERVER-IP:$APP_PORT"
echo
echo "Azuriom browser installer database values:"
echo "  Driver:   PostgreSQL"
echo "  Host:     db"
echo "  Port:     5432"
echo "  Database: $POSTGRES_DB"
echo "  Username: $POSTGRES_USER"
echo "  Password: $POSTGRES_PASSWORD"
echo
echo "After Azuriom setup: Administration -> Extensions -> Plugins -> enable Gaming Hub Manager."
echo "The credentials are also stored in $INSTALL_DIR/.env with mode 600."
```

---

## Ubuntu 24.04 with Bash

Paste the complete block into a Bash terminal:

```bash
#!/usr/bin/env bash
set -Eeuo pipefail

AZURIOM_VERSION="1.2.12"
INSTALL_DIR="/opt/azuriom"
AZURIOM_ZIP_URL="https://github.com/Azuriom/Azuriom/releases/download/v${AZURIOM_VERSION}/Azuriom-${AZURIOM_VERSION}.zip"
MANAGER_API_URL="https://api.github.com/repos/GamingHubProject/Manager/releases/latest"

fail() {
    echo "ERROR: $*" >&2
    exit 1
}

prompt_identifier() {
    local prompt="$1"
    local default_value="$2"
    local value

    while true; do
        read -r -p "${prompt} [${default_value}]: " value
        value="${value:-$default_value}"

        if [[ "$value" =~ ^[A-Za-z0-9_]+$ ]]; then
            printf '%s' "$value"
            return 0
        fi

        echo "Use only letters, numbers, and underscores." >&2
    done
}

echo "Gaming Hub Manager — official Azuriom ${AZURIOM_VERSION} Docker installer"
echo "Azuriom will be downloaded directly from the official Azuriom GitHub release."
echo

echo "Installing required terminal tools..."
sudo apt-get update
sudo apt-get install -y ca-certificates curl unzip python3 acl openssl

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker is not installed; installing Ubuntu's Docker packages..."
    sudo apt-get install -y docker.io docker-compose-v2
elif ! docker compose version >/dev/null 2>&1 && ! sudo docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is missing; installing it..."
    sudo apt-get install -y docker-compose-v2
fi

sudo systemctl enable --now docker

if docker info >/dev/null 2>&1; then
    DOCKER=(docker)
elif sudo docker info >/dev/null 2>&1; then
    DOCKER=(sudo docker)
else
    fail "Docker is installed but the Docker daemon is not available."
fi

while true; do
    read -r -p "Public HTTP port [8086]: " APP_PORT
    APP_PORT="${APP_PORT:-8086}"

    if ! [[ "$APP_PORT" =~ ^[0-9]+$ ]] || (( APP_PORT < 1 || APP_PORT > 65535 )); then
        echo "Enter a port from 1 to 65535."
        continue
    fi

    if command -v ss >/dev/null 2>&1 && ss -ltnH "sport = :${APP_PORT}" 2>/dev/null | grep -q .; then
        echo "Port ${APP_PORT} is already in use. Choose another port."
        continue
    fi

    break
done

POSTGRES_DB="$(prompt_identifier "PostgreSQL database name" "azuriom")"
echo
POSTGRES_USER="$(prompt_identifier "PostgreSQL username" "azuriom")"
echo

while true; do
    read -r -s -p "PostgreSQL password (leave empty to generate one): " POSTGRES_PASSWORD
    echo

    if [[ -z "$POSTGRES_PASSWORD" ]]; then
        POSTGRES_PASSWORD="$(openssl rand -hex 32)"
        break
    fi

    if [[ "$POSTGRES_PASSWORD" =~ ^[A-Za-z0-9._~-]{16,}$ ]]; then
        break
    fi

    echo "Use at least 16 characters containing only letters, numbers, dot, underscore, tilde, or hyphen."
done

CREDENTIAL_FILE="$HOME/.azuriom-install-credentials"
umask 077
printf 'APP_PORT=%s\nPOSTGRES_DB=%s\nPOSTGRES_USER=%s\nPOSTGRES_PASSWORD=%s\n' \
    "$APP_PORT" \
    "$POSTGRES_DB" \
    "$POSTGRES_USER" \
    "$POSTGRES_PASSWORD" \
    > "$CREDENTIAL_FILE"
chmod 600 "$CREDENTIAL_FILE"
echo "Credentials saved immediately to $CREDENTIAL_FILE"
echo

if [[ -e "$INSTALL_DIR" ]]; then
    fail "$INSTALL_DIR already exists. Move or delete it before running this fresh installer."
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

echo "Downloading official Azuriom ${AZURIOM_VERSION} release..."
curl -fL --retry 3 --retry-delay 2 "$AZURIOM_ZIP_URL" -o "$TMP_DIR/azuriom.zip"
mkdir -p "$TMP_DIR/azuriom"
unzip -q "$TMP_DIR/azuriom.zip" -d "$TMP_DIR/azuriom"

ARTISAN_PATH="$(find "$TMP_DIR/azuriom" -maxdepth 3 -type f -name artisan -print -quit)"
[[ -n "$ARTISAN_PATH" ]] || fail "The official Azuriom archive did not contain artisan."
AZURIOM_ROOT="$(dirname "$ARTISAN_PATH")"

sudo mkdir -p "$INSTALL_DIR"
sudo cp -a "$AZURIOM_ROOT/." "$INSTALL_DIR/"
sudo chown -R "$(id -u):$(id -g)" "$INSTALL_DIR"

cd "$INSTALL_DIR"
[[ -f docker-compose.yml ]] || fail "docker-compose.yml is missing after extraction."
[[ -f docker/nginx.conf ]] || fail "docker/nginx.conf is missing after extraction."
[[ -f .env.example ]] || fail ".env.example is missing after extraction."
[[ -f LICENSE ]] || fail "The official Azuriom LICENSE file is missing after extraction."

cp docker-compose.yml docker-compose.yml.upstream
cp .env.example .env

sed -i \
    -e 's|"8000:80"|"${APP_PORT}:80"|' \
    -e 's|- DB_DATABASE=azuriom|- DB_DATABASE=${POSTGRES_DB}|' \
    -e 's|- DB_USERNAME=azuriom|- DB_USERNAME=${POSTGRES_USER}|' \
    -e 's|- DB_PASSWORD=password|- DB_PASSWORD=${POSTGRES_PASSWORD}|' \
    -e 's|POSTGRES_DB: azuriom|POSTGRES_DB: ${POSTGRES_DB}|' \
    -e 's|POSTGRES_USER: azuriom|POSTGRES_USER: ${POSTGRES_USER}|' \
    -e 's|POSTGRES_PASSWORD: password|POSTGRES_PASSWORD: ${POSTGRES_PASSWORD}|' \
    docker-compose.yml

for required in '${APP_PORT}' '${POSTGRES_DB}' '${POSTGRES_USER}' '${POSTGRES_PASSWORD}'; do
    grep -qF "$required" docker-compose.yml || fail "Could not apply required Compose setting: $required"
done

cat >> .env <<ENVVARS

# Docker Compose installer settings
APP_PORT=${APP_PORT}
POSTGRES_DB=${POSTGRES_DB}
POSTGRES_USER=${POSTGRES_USER}
POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
ENVVARS
chmod 600 .env

echo "Downloading the latest stable packaged Gaming Hub Manager release..."
MANAGER_URL="$(
    curl -fsSL --retry 3 --retry-delay 2 \
        -H 'Accept: application/vnd.github+json' \
        -H 'X-GitHub-Api-Version: 2022-11-28' \
        -H 'User-Agent: Gaming-Hub-Manager-Installer' \
        "$MANAGER_API_URL" \
    | python3 -c 'import json,re,sys; data=json.load(sys.stdin); assets=[a["browser_download_url"] for a in data.get("assets",[]) if a.get("state")=="uploaded" and re.fullmatch(r"gaming-hub-manager-v[^/]+\.zip",a.get("name",""))]; sys.stdout.write(assets[0] if len(assets)==1 else ""); sys.exit(0 if len(assets)==1 else 1)'
)" || fail "GitHub did not return exactly one packaged gaming-hub-manager-v*.zip asset."

curl -fL --retry 3 --retry-delay 2 "$MANAGER_URL" -o "$TMP_DIR/manager.zip"
mkdir -p "$TMP_DIR/manager"
unzip -q "$TMP_DIR/manager.zip" -d "$TMP_DIR/manager"
[[ -f "$TMP_DIR/manager/gaming-hub-manager/plugin.json" ]] || fail "The Manager archive has an unexpected structure."
mkdir -p plugins
cp -a "$TMP_DIR/manager/gaming-hub-manager" plugins/gaming-hub-manager

echo "Building the official Azuriom application container..."
"${DOCKER[@]}" compose build app

WWW_UID="$("${DOCKER[@]}" compose run --rm --no-deps --entrypoint sh app -c 'id -u www-data' | tail -n 1 | tr -d '[:space:]')"
[[ "$WWW_UID" =~ ^[0-9]+$ ]] || fail "Could not determine the container www-data UID."

sudo setfacl -R -m "u:${WWW_UID}:rwX" "$INSTALL_DIR"
sudo find "$INSTALL_DIR" -type d -exec setfacl -m "d:u:${WWW_UID}:rwX" {} +

"${DOCKER[@]}" compose config >/dev/null
"${DOCKER[@]}" compose up -d

echo
echo "Installation started successfully."
echo "Open: http://SERVER-IP:${APP_PORT}"
echo
echo "Azuriom browser installer database values:"
echo "  Driver:   PostgreSQL"
echo "  Host:     db"
echo "  Port:     5432"
echo "  Database: ${POSTGRES_DB}"
echo "  Username: ${POSTGRES_USER}"
echo "  Password: ${POSTGRES_PASSWORD}"
echo
echo "After Azuriom setup: Administration -> Extensions -> Plugins -> enable Gaming Hub Manager."
echo "The credentials are also stored in ${INSTALL_DIR}/.env with mode 600."
```

---

## Complete the browser setup

After the terminal installer finishes, open:

```text
http://SERVER-IP:YOUR_SELECTED_PORT
```

Use these database values in the Azuriom installer:

```text
Driver: PostgreSQL
Host: db
Port: 5432
Database: the database name entered in the terminal
Username: the database username entered in the terminal
Password: the password entered or generated in the terminal
```

The same values are stored in:

```text
/opt/azuriom/.env
```

That file is created with permission mode `600`.

After Azuriom setup is complete, open:

```text
Administration
→ Extensions
→ Plugins
```

Enable:

```text
Gaming Hub Manager
```

Do not enable Gaming Hub Manager before completing the normal Azuriom browser setup.

---

## Check the installation

### Fish

```fish
cd /opt/azuriom
docker compose ps
docker compose logs --tail=100
```

Use `sudo docker compose` instead when your user does not have Docker access.

### Ubuntu / Bash

```bash
cd /opt/azuriom
docker compose ps
docker compose logs --tail=100
```

Use `sudo docker compose` instead when your user does not have Docker access.

---

## Start, stop, and restart

Run these commands from `/opt/azuriom`:

```bash
cd /opt/azuriom

# Stop without deleting the database
docker compose down

# Start again
docker compose up -d

# Restart the running containers
docker compose restart
```

Use `sudo docker compose` when required by your Docker installation.

---

## Clean reset

**Warning:** This deletes the Azuriom database, users, settings, uploads, plugins, and all other installation data.

### Fish

```fish
if test -d /opt/azuriom
    cd /opt/azuriom
    sudo docker compose down -v --remove-orphans
    cd /
    sudo rm -rf /opt/azuriom
end
```

### Ubuntu / Bash

```bash
if [ -d /opt/azuriom ]; then
    cd /opt/azuriom
    sudo docker compose down -v --remove-orphans
    cd /
    sudo rm -rf /opt/azuriom
fi
```

After a clean reset, run the appropriate installer again.

---

## Attribution

Azuriom is an independent upstream project and is not part of Gaming Hub Project.

Official Azuriom project:

https://github.com/Azuriom/Azuriom

Official Azuriom releases:

https://github.com/Azuriom/Azuriom/releases

Gaming Hub Manager is a separate Azuriom plugin:

https://github.com/GamingHubProject/Manager
