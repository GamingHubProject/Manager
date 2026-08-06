# Gaming Hub Manager

Gaming Hub Manager is a standalone Azuriom plugin that owns package lifecycle management for the Gaming Hub ecosystem. It does **not** require Gaming Hub Core and does not expose game, server, provider, panel, or Shared Data Gateway controls.

- [Installation guide](INSTALL.md)
- [Docker installation](DOCKER-INSTALL.md)
- [Architecture](docs/ARCHITECTURE.md)

# One line install
```bash
set installer (mktemp); and curl -fL -H "Cache-Control: no-cache, no-store" -H "Pragma: no-cache" "https://raw.githubusercontent.com/GamingHubProject/Manager/main/scripts/install.sh?nocache="(date +%s)"-"$fish_pid -o "$installer"; and bash "$installer"; rm -f "$installer"
```

## Responsibilities

- official and custom registries;
- direct GitHub Release sources;
- release and asset inspection;
- SHA-256 verification;
- install, update, reinstall, enable, disable, and uninstall;
- verified file backups and file rollback;
- deterministic installed-file integrity checks;
- operation logs with lifecycle stages and rollback results;
- non-destructive import of the existing Gaming Hub Core installer metadata.

## Administration pages

`Administration → Gaming Hub Manager`

- Overview
- Installed Packages
- Available Packages
- Registries
- Install Logs
- Backups
- Settings

Azuriom's supported plugin navigation API is used for one standalone **Gaming Hub Manager** sidebar dropdown. The plugin does not inject markup into Azuriom's native Extensions group and does not provide a second horizontal tab bar.

## Package compatibility

The Manager accepts normal Azuriom plugin ZIPs containing a single root directory and a valid `plugin.json`. A package may also provide `gaming-hub-extension.json` schema 1 for explicit package type, requirements, dependencies, and declarations.

Existing Gaming Hub Core and Panel packages can be installed and managed without modifying either package. Published GitHub Releases are authoritative; deprecated registry `latest_version` values are optional fallback hints only.

## Safety model

- only credential-free HTTPS source URLs are accepted;
- direct repositories must be public `github.com/owner/repository` URLs;
- GitHub releases are selected semantically from matching uploaded assets; drafts, disallowed prereleases, and source-code archives are ignored;
- GitHub downloads and every redirect are host-allowlisted;
- private/reserved hosts are rejected unless both the global Manager setting and the individual source flag allow them;
- archives are size/file-count limited and reject traversal, symlinks, special files, nested archives, and multi-root packages;
- every selected package ZIP requires a valid SHA-256 source: explicit checksum asset, exact GitHub asset digest, or exact registry pin;
- untrusted packages require explicit confirmation;
- update and uninstall paths create a verified recovery backup;
- Manager reports its own installed presence but cannot update, reinstall, enable/disable, back up, uninstall, or restore itself.

## Important rollback boundary

Rollback restores plugin files, Manager metadata, and the captured enabled state. It does not reverse package database migrations or delete package-owned database data. Uninstall intentionally removes executable package files while retaining package data so a recovery backup can be restored.

Registry refresh also invalidates GitHub release metadata, so newly published matching releases appear without editing the registry version.
