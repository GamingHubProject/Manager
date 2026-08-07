# Gaming Hub Manager — Fresh Azuriom Docker Installer

This installer uses the official Azuriom 1.2.12 release and adds Gaming Hub Manager as a separate plugin.

It does **not** rename or redistribute Azuriom as a Gaming Hub product. The official Azuriom source tree and license remain in `/opt/azuriom`.

## Repository files

Upload the installer as:

```text
scripts/install.sh
```

The same installer supports:

- CachyOS / Arch Linux, including users whose interactive shell is Fish;
- Ubuntu / Debian using Bash.

The installer itself is Bash. Fish users explicitly launch it with `bash`, so Fish never parses the installer code.

## Recommended install command — Fish / CachyOS

```fish
curl -fsSL https://raw.githubusercontent.com/GamingHubProject/Manager/main/scripts/install.sh -o /tmp/gaming-hub-install.sh; and bash /tmp/gaming-hub-install.sh
```

## Recommended install command — Ubuntu / Bash

```bash
curl -fsSL https://raw.githubusercontent.com/GamingHubProject/Manager/main/scripts/install.sh -o /tmp/gaming-hub-install.sh && bash /tmp/gaming-hub-install.sh
```

## Inspect before running

Users who want to review the installer first can download it without executing it:

```bash
curl -fsSL https://raw.githubusercontent.com/GamingHubProject/Manager/main/scripts/install.sh -o /tmp/gaming-hub-install.sh
less /tmp/gaming-hub-install.sh
bash /tmp/gaming-hub-install.sh
```

## What the installer asks

The script asks interactively for:

1. public HTTP port, default `8086`;
2. PostgreSQL database name, default `azuriom`;
3. PostgreSQL username, default `azuriom`;
4. application timezone, defaulting to the host timezone;
5. whether to generate a secure PostgreSQL password automatically.

The generated or supplied database credentials are saved immediately to:

```text
~/.azuriom-install-credentials
```

with mode `600`, before Azuriom is downloaded or Docker is built.

## What the installer does

The installer progresses through eight visible steps:

```text
[1/8] Checking system requirements
[2/8] Installation settings
[3/8] Downloading official Azuriom 1.2.12
[4/8] Configuring Docker and PostgreSQL
[5/8] Downloading latest stable Gaming Hub Manager
[6/8] Building Azuriom and setting permissions
[7/8] Validating and starting containers
[8/8] Installation ready
```

It installs required terminal packages, installs Docker/Compose when missing (after asking), downloads the official Azuriom 1.2.12 release, keeps the upstream Compose file as `docker-compose.yml.upstream`, configures the selected port/database, downloads the latest packaged Manager release asset, applies ACL write permissions for the PHP `www-data` user, validates Compose, and starts the containers.

No `chmod 777` is used.

## After the installer finishes

Open:

```text
http://SERVER-IP:SELECTED_PORT
```

Use:

```text
Driver: PostgreSQL
Host: db
Port: 5432
Database: selected database name
Username: selected database username
Password: generated/supplied password
```

After completing Azuriom's normal browser setup:

```text
Administration
→ Extensions
→ Plugins
→ Gaming Hub Manager
→ Enable
```

## Installation directory

Azuriom is stored permanently at:

```text
/opt/azuriom
```

Normal Docker management is therefore:

```bash
cd /opt/azuriom
docker compose ps
docker compose logs -f
docker compose restart
```

Use `sudo docker compose` instead if your user does not have direct Docker access.

## Clean reset

This permanently deletes the database and Azuriom installation:

```bash
cd /opt/azuriom
sudo docker compose down -v --remove-orphans
cd /
sudo rm -rf /opt/azuriom
```

The saved installer credentials can also be removed:

```bash
rm -f ~/.azuriom-install-credentials
```
