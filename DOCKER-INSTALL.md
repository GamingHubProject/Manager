# Gaming Hub Manager — Fresh Azuriom Docker Installer

This installer downloads the official Azuriom 1.2.12 release and adds Gaming Hub Manager as a separate plugin.

Azuriom remains the upstream application. The installer does not rename Azuriom or remove its license and attribution.

## Repository file

Upload the installer as:

```text
scripts/install.sh
```

The same Bash installer supports:

- CachyOS / Arch Linux, including Fish users;
- Ubuntu / Debian.

Fish only downloads the file and launches Bash. Fish does not parse the installer itself.

## Run on Fish / CachyOS

```fish
rm -f /tmp/gaming-hub-install.sh; and curl -fL -H "Cache-Control: no-cache" "https://raw.githubusercontent.com/GamingHubProject/Manager/main/scripts/install.sh?nocache="(date +%s) -o /tmp/gaming-hub-install.sh; and bash /tmp/gaming-hub-install.sh
```

## Run on Ubuntu / Bash

```bash
rm -f /tmp/gaming-hub-install.sh && curl -fL -H "Cache-Control: no-cache" "https://raw.githubusercontent.com/GamingHubProject/Manager/main/scripts/install.sh?nocache=$(date +%s)" -o /tmp/gaming-hub-install.sh && bash /tmp/gaming-hub-install.sh
```

## Main menu

The installer starts with:

```text
1) Install a new Azuriom instance
2) Reinstall / clean reset
3) Configure or change domain, HTTPS, and reverse proxy
4) Create first/admin login
5) Uninstall completely
6) Exit
```

Reinstall and uninstall require explicit destructive confirmation. Menu option `4` creates a verified Azuriom administrator using Azuriom's built-in `user:create --admin` command.

## Fresh installation

The installer asks for:

1. public HTTP port, default `8086`;
2. PostgreSQL database name, default `azuriom`;
3. PostgreSQL username, default `azuriom`;
4. application timezone;
5. whether to generate a secure database password.

Credentials are written immediately to:

```text
~/.azuriom-install-credentials
```

The file is stored with mode `600`.

The installer then:

- downloads the official Azuriom 1.2.12 release;
- preserves the upstream Compose file as `docker-compose.yml.upstream`;
- configures PostgreSQL and the selected HTTP port;
- corrects the shared-container Nginx PHP path;
- downloads the latest packaged Gaming Hub Manager release asset;
- grants PHP write access through ACLs without `chmod 777`;
- validates Nginx and confirms both Nginx and PHP-FPM can see `public/index.php`;
- starts the containers.

After installation, the installer asks whether to configure a domain and HTTPS immediately.

## Browser setup

Without a domain, open:

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
Password: generated or supplied password
```

### Gaming Hub / Custom Game installation

For Gaming Hub Manager, use Azuriom's hidden Custom Game installer page instead of selecting Minecraft/Steam/etc.:

```text
http://SERVER-IP:SELECTED_PORT/install/game/custom
```

With a configured domain:

```text
https://YOUR-DOMAIN/install/game/custom
```

Choose the locale, press **Install**, and confirm. The Custom Game path intentionally finishes Azuriom without creating the normal game-linked administrator account.

After the success page, create the first administrator. Either rerun `scripts/install.sh` and choose:

```text
4) Create first/admin login
```

or run Azuriom's supported command directly:

```bash
cd /opt/azuriom
docker compose exec app php artisan user:create --admin
```

Azuriom will ask for the username, email address, and password. The account is created as a verified administrator.

Then sign in at `/login` and enable Gaming Hub Manager:

```text
Administration
→ Extensions
→ Plugins
→ Gaming Hub Manager
→ Enable
```

## Create or recover an administrator login

Choose menu option `4` after the Azuriom browser installer has completed. The installer refuses to run this step while `APP_KEY` is still empty, preventing user creation before the database setup is finished.

This option can also create a new administrator later if access to the original admin account is lost.

Direct equivalent:

```bash
cd /opt/azuriom
docker compose exec app php artisan user:create --admin
```

## Domain, HTTPS, and reverse proxy

Choose menu option `3` to add a domain or change the current domain.

The installer uses the official Caddy Docker image as a managed reverse proxy. It:

- asks for the domain;
- optionally asks for a certificate contact email;
- checks whether ports `80` and `443` are already occupied;
- creates `docker/caddy/Caddyfile`;
- creates `docker-compose.proxy.yml`;
- stores certificates in persistent Docker volumes;
- updates Azuriom's `APP_URL` to `https://DOMAIN`;
- adds the proxy overlay to normal `docker compose` commands;
- validates the Caddy configuration;
- starts or recreates the Caddy container.

Before enabling this feature:

- create the domain's DNS record pointing to the server's public IP;
- forward public TCP ports `80` and `443` to the machine;
- ensure no other reverse proxy owns ports `80` or `443`.

Caddy automatically obtains, renews, and serves the HTTPS certificate when DNS and networking are correct.

The current domain settings are stored at:

```text
/opt/azuriom/.azuriom-domain
```

To inspect certificate or reverse-proxy status:

```bash
cd /opt/azuriom
docker compose logs --tail=100 caddy
```

## Installation directory

```text
/opt/azuriom
```

Normal management:

```bash
cd /opt/azuriom
docker compose ps
docker compose logs -f
docker compose restart
```

Use `sudo docker compose` where direct Docker access is unavailable.
