#!/usr/bin/env bash
set -Eeuo pipefail

AZURIOM_VERSION="1.2.12"
INSTALL_DIR="/opt/azuriom"
AZURIOM_URL="https://github.com/Azuriom/Azuriom/releases/download/v${AZURIOM_VERSION}/Azuriom-${AZURIOM_VERSION}.zip"
MANAGER_API="https://api.github.com/repos/GamingHubProject/Manager/releases/latest"
CREDENTIAL_FILE="${HOME}/.azuriom-install-credentials"

STEP=0
TOTAL_STEPS=8

step() {
    STEP=$((STEP + 1))
    printf '\n\033[1;36m[%s/%s] %s\033[0m\n' "$STEP" "$TOTAL_STEPS" "$1"
}

info() {
    printf '\033[0;32m%s\033[0m\n' "$1"
}

warn() {
    printf '\033[1;33m%s\033[0m\n' "$1"
}

fail() {
    printf '\n\033[1;31mERROR: %s\033[0m\n' "$1" >&2
    exit 1
}

ask_default() {
    local prompt="$1"
    local default_value="$2"
    local answer
    read -r -p "${prompt} [${default_value}]: " answer
    printf '%s' "${answer:-$default_value}"
}

valid_identifier() {
    [[ "$1" =~ ^[A-Za-z0-9_]+$ ]]
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

if [[ $EUID -eq 0 ]]; then
    SUDO=()
else
    command_exists sudo || fail "sudo is required."
    SUDO=(sudo)
fi

clear
printf '\033[1;35mGaming Hub Manager - Fresh Azuriom Installer\033[0m\n'
printf 'Official Azuriom %s + PostgreSQL + latest stable Gaming Hub Manager\n' "$AZURIOM_VERSION"
printf '\nAzuriom is downloaded unchanged from its official GitHub release.\n'
printf 'Gaming Hub Manager is added only as a separate plugin.\n\n'

# -----------------------------------------------------------------------------
# Step 1: Detect system and install prerequisites
# -----------------------------------------------------------------------------
step "Checking system requirements"

if command_exists pacman; then
    DISTRO_FAMILY="arch"
    PYTHON_BIN="python"
    info "Detected Arch/CachyOS-style system."

    "${SUDO[@]}" pacman -Sy --needed --noconfirm \
        ca-certificates curl unzip python acl openssl

elif command_exists apt-get; then
    DISTRO_FAMILY="ubuntu"
    PYTHON_BIN="python3"
    info "Detected Ubuntu/Debian-style system."

    "${SUDO[@]}" apt-get update
    "${SUDO[@]}" apt-get install -y \
        ca-certificates curl unzip python3 acl openssl
else
    fail "This installer currently supports Arch/CachyOS and Ubuntu/Debian."
fi

if ! command_exists docker; then
    warn "Docker is not installed."
    read -r -p "Install Docker and Docker Compose now? [Y/n]: " INSTALL_DOCKER
    INSTALL_DOCKER="${INSTALL_DOCKER:-Y}"

    if [[ ! "$INSTALL_DOCKER" =~ ^[Yy]$ ]]; then
        fail "Docker is required."
    fi

    if [[ "$DISTRO_FAMILY" == "arch" ]]; then
        "${SUDO[@]}" pacman -S --needed --noconfirm \
            docker docker-compose docker-buildx
    else
        "${SUDO[@]}" apt-get install -y docker.io docker-compose-v2
    fi
fi

if ! docker compose version >/dev/null 2>&1 && ! "${SUDO[@]}" docker compose version >/dev/null 2>&1; then
    warn "Docker Compose v2 is missing. Installing it."
    if [[ "$DISTRO_FAMILY" == "arch" ]]; then
        "${SUDO[@]}" pacman -S --needed --noconfirm docker-compose
    else
        "${SUDO[@]}" apt-get install -y docker-compose-v2
    fi
fi

"${SUDO[@]}" systemctl enable --now docker

if docker info >/dev/null 2>&1; then
    DOCKER=(docker)
elif "${SUDO[@]}" docker info >/dev/null 2>&1; then
    DOCKER=("${SUDO[@]}" docker)
else
    fail "Docker is installed, but the Docker daemon is unavailable."
fi

info "Docker is ready."

# -----------------------------------------------------------------------------
# Step 2: Ask configuration
# -----------------------------------------------------------------------------
step "Installation settings"

while true; do
    APP_PORT="$(ask_default "Public HTTP port" "8086")"
    if [[ ! "$APP_PORT" =~ ^[0-9]+$ ]] || (( APP_PORT < 1 || APP_PORT > 65535 )); then
        warn "Enter a port between 1 and 65535."
        continue
    fi

    if command_exists ss && ss -ltnH "sport = :${APP_PORT}" 2>/dev/null | grep -q .; then
        warn "Port ${APP_PORT} is already in use."
        continue
    fi
    break
done

while true; do
    DB_DATABASE="$(ask_default "PostgreSQL database name" "azuriom")"
    valid_identifier "$DB_DATABASE" && break
    warn "Use only letters, numbers, and underscores."
done

while true; do
    DB_USERNAME="$(ask_default "PostgreSQL username" "azuriom")"
    valid_identifier "$DB_USERNAME" && break
    warn "Use only letters, numbers, and underscores."
done

SYSTEM_TIMEZONE="$(timedatectl show -p Timezone --value 2>/dev/null || true)"
SYSTEM_TIMEZONE="${SYSTEM_TIMEZONE:-UTC}"
APP_TIMEZONE="$(ask_default "Application timezone" "$SYSTEM_TIMEZONE")"

read -r -p "Generate a secure PostgreSQL password automatically? [Y/n]: " AUTO_PASSWORD
AUTO_PASSWORD="${AUTO_PASSWORD:-Y}"

if [[ "$AUTO_PASSWORD" =~ ^[Yy]$ ]]; then
    DB_PASSWORD="$(openssl rand -hex 24)"
    info "Generated a secure database password."
else
    while true; do
        read -r -s -p "PostgreSQL password (minimum 16 characters): " DB_PASSWORD
        printf '\n'
        if (( ${#DB_PASSWORD} >= 16 )) && [[ "$DB_PASSWORD" =~ ^[A-Za-z0-9._~-]+$ ]]; then
            break
        fi
        warn "Use at least 16 characters: letters, numbers, dot, underscore, tilde or hyphen."
    done
fi

# Save immediately, before downloads/builds.
umask 077
cat > "$CREDENTIAL_FILE" <<CREDS
APP_PORT=${APP_PORT}
APP_TIMEZONE=${APP_TIMEZONE}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
CREDS
chmod 600 "$CREDENTIAL_FILE"
info "Credentials saved to ${CREDENTIAL_FILE}"

printf '\nSelected settings:\n'
printf '  Install directory: %s\n' "$INSTALL_DIR"
printf '  HTTP port:         %s\n' "$APP_PORT"
printf '  Database:          %s\n' "$DB_DATABASE"
printf '  Database user:     %s\n' "$DB_USERNAME"
printf '  Timezone:          %s\n' "$APP_TIMEZONE"
printf '  Password:          saved in %s\n' "$CREDENTIAL_FILE"

if [[ -e "$INSTALL_DIR" ]]; then
    fail "${INSTALL_DIR} already exists. This installer will not overwrite an existing installation."
fi

# -----------------------------------------------------------------------------
# Step 3: Download official Azuriom
# -----------------------------------------------------------------------------
step "Downloading official Azuriom ${AZURIOM_VERSION}"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

curl -fL --retry 3 --retry-delay 2 \
    "$AZURIOM_URL" \
    -o "$TMP_DIR/azuriom.zip"

mkdir -p "$TMP_DIR/azuriom"
unzip -q "$TMP_DIR/azuriom.zip" -d "$TMP_DIR/azuriom"

ARTISAN_PATH="$(find "$TMP_DIR/azuriom" -maxdepth 3 -type f -name artisan -print -quit)"
[[ -n "$ARTISAN_PATH" ]] || fail "The Azuriom archive does not contain artisan."

AZURIOM_ROOT="$(dirname "$ARTISAN_PATH")"
[[ -f "$AZURIOM_ROOT/LICENSE" ]] || fail "The official Azuriom LICENSE file is missing."
[[ -f "$AZURIOM_ROOT/docker-compose.yml" ]] || fail "The official docker-compose.yml is missing."
[[ -f "$AZURIOM_ROOT/docker/nginx.conf" ]] || fail "The official docker/nginx.conf is missing."

"${SUDO[@]}" mkdir -p "$INSTALL_DIR"
"${SUDO[@]}" cp -a "$AZURIOM_ROOT/." "$INSTALL_DIR/"
"${SUDO[@]}" chown -R "$(id -u):$(id -g)" "$INSTALL_DIR"

cd "$INSTALL_DIR"
cp docker-compose.yml docker-compose.yml.upstream
cp .env.example .env

info "Official Azuriom files installed in ${INSTALL_DIR}."

# -----------------------------------------------------------------------------
# Step 4: Configure official Docker setup
# -----------------------------------------------------------------------------
step "Configuring Docker and PostgreSQL"

export APP_PORT APP_TIMEZONE DB_DATABASE DB_USERNAME DB_PASSWORD

"$PYTHON_BIN" <<'PY'
from pathlib import Path
import os

compose = Path("docker-compose.yml")
text = compose.read_text()

replacements = {
    '"8000:80"': '"${APP_PORT}:80"',
    '- DB_DATABASE=azuriom': '- DB_DATABASE=${DB_DATABASE}',
    '- DB_USERNAME=azuriom': '- DB_USERNAME=${DB_USERNAME}',
    '- DB_PASSWORD=password': '- DB_PASSWORD=${DB_PASSWORD}',
    'POSTGRES_DB: azuriom': 'POSTGRES_DB: ${DB_DATABASE}',
    'POSTGRES_USER: azuriom': 'POSTGRES_USER: ${DB_USERNAME}',
    'POSTGRES_PASSWORD: password': 'POSTGRES_PASSWORD: ${DB_PASSWORD}',
}

for old, new in replacements.items():
    if old not in text:
        raise SystemExit(f"Expected upstream Compose line not found: {old}")
    text = text.replace(old, new, 1)

compose.write_text(text)

env_path = Path(".env")
env_lines = env_path.read_text().splitlines()
values = {
    "APP_TIMEZONE": os.environ["APP_TIMEZONE"],
    "DB_CONNECTION": "pgsql",
    "DB_HOST": "db",
    "DB_PORT": "5432",
    "DB_DATABASE": os.environ["DB_DATABASE"],
    "DB_USERNAME": os.environ["DB_USERNAME"],
    "DB_PASSWORD": os.environ["DB_PASSWORD"],
}

out = []
seen = set()
for line in env_lines:
    if "=" in line and not line.lstrip().startswith("#"):
        key = line.split("=", 1)[0]
        if key in values:
            out.append(f"{key}={values[key]}")
            seen.add(key)
            continue
    out.append(line)

for key, value in values.items():
    if key not in seen:
        out.append(f"{key}={value}")

out.append(f"APP_PORT={os.environ['APP_PORT']}")
env_path.write_text("\n".join(out) + "\n")
PY

chmod 600 .env

for required in '${APP_PORT}' '${DB_DATABASE}' '${DB_USERNAME}' '${DB_PASSWORD}'; do
    grep -qF "$required" docker-compose.yml || fail "Compose configuration patch failed for ${required}."
done

info "Port and PostgreSQL settings configured."

# -----------------------------------------------------------------------------
# Step 5: Download Manager
# -----------------------------------------------------------------------------
step "Downloading latest stable Gaming Hub Manager"

MANAGER_URL="$({
    curl -fsSL --retry 3 --retry-delay 2 \
        -H 'Accept: application/vnd.github+json' \
        -H 'X-GitHub-Api-Version: 2022-11-28' \
        -H 'User-Agent: Gaming-Hub-Manager-Installer' \
        "$MANAGER_API"
} | "$PYTHON_BIN" -c '
import json, re, sys
release = json.load(sys.stdin)
assets = [
    a["browser_download_url"]
    for a in release.get("assets", [])
    if a.get("state") == "uploaded"
    and re.fullmatch(r"gaming-hub-manager-v[^/]+\.zip", a.get("name", ""))
]
if len(assets) != 1:
    raise SystemExit(1)
print(assets[0])
')" || fail "Could not find exactly one packaged gaming-hub-manager-v*.zip release asset."

curl -fL --retry 3 --retry-delay 2 \
    "$MANAGER_URL" \
    -o "$TMP_DIR/manager.zip"

mkdir -p "$TMP_DIR/manager"
unzip -q "$TMP_DIR/manager.zip" -d "$TMP_DIR/manager"

[[ -f "$TMP_DIR/manager/gaming-hub-manager/plugin.json" ]] \
    || fail "Manager ZIP does not contain gaming-hub-manager/plugin.json."

mkdir -p plugins
cp -a "$TMP_DIR/manager/gaming-hub-manager" plugins/gaming-hub-manager

info "Gaming Hub Manager plugin files installed."

# -----------------------------------------------------------------------------
# Step 6: Build and permissions
# -----------------------------------------------------------------------------
step "Building Azuriom and setting permissions"

"${DOCKER[@]}" compose build app

WWW_UID="$(
    "${DOCKER[@]}" compose run --rm --no-deps --entrypoint sh app -c 'id -u www-data' 2>/dev/null \
        | tail -n 1 \
        | tr -d '[:space:]'
)"

[[ "$WWW_UID" =~ ^[0-9]+$ ]] || fail "Could not determine the container www-data UID."

"${SUDO[@]}" setfacl -R -m "u:${WWW_UID}:rwX" "$INSTALL_DIR"
"${SUDO[@]}" find "$INSTALL_DIR" -type d -exec setfacl -m "d:u:${WWW_UID}:rwX" {} +

info "Azuriom is writable by the PHP container without chmod 777."

# -----------------------------------------------------------------------------
# Step 7: Validate and start
# -----------------------------------------------------------------------------
step "Validating and starting containers"

"${DOCKER[@]}" compose config >/dev/null
"${DOCKER[@]}" compose up -d

info "Containers started."

# -----------------------------------------------------------------------------
# Step 8: Finish
# -----------------------------------------------------------------------------
step "Installation ready"

printf '\nOpen Azuriom in your browser:\n'
printf '  http://SERVER-IP:%s\n\n' "$APP_PORT"
printf 'Use these values in the Azuriom browser installer:\n'
printf '  Driver:   PostgreSQL\n'
printf '  Host:     db\n'
printf '  Port:     5432\n'
printf '  Database: %s\n' "$DB_DATABASE"
printf '  Username: %s\n' "$DB_USERNAME"
printf '  Password: %s\n\n' "$DB_PASSWORD"
printf 'After Azuriom setup:\n'
printf '  Administration -> Extensions -> Plugins -> Gaming Hub Manager -> Enable\n\n'
printf 'Credentials are stored at:\n'
printf '  %s\n\n' "$CREDENTIAL_FILE"
printf 'Project directory:\n'
printf '  %s\n' "$INSTALL_DIR"
