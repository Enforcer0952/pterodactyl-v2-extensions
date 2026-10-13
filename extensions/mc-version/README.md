# Minecraft Version: switch software and version, and fix the Java version

Adds a **Version** page to Minecraft Java servers in Pterodactyl 2.0. It shows what a server really runs, switches it between Paper, Purpur, Fabric and Vanilla, and sets the Java version that fits.

## Features

- **Current:** the software and Minecraft version, read from the server's files (the log of the last start first, then `version_history.json`, then the newest folder under `versions/`), next to the egg's startup variables `MINECRAFT_VERSION`, `BUILD_NUMBER` and `SERVER_JARFILE`.
- **Mismatches are flagged:** Fabric files on a Paper egg, `MINECRAFT_VERSION` saying something other than what ran, a server jar that is missing.
- **Switch software or version:** Paper, Purpur, Fabric or Vanilla, with version lists fetched live from PaperMC (Fill API), Purpur, Fabric and Mojang. Lists are cached for 10 minutes. Releases only by default; **Show snapshots and experimental builds** adds snapshots, release candidates and non-stable builds.
- **A plan before anything changes:** the page lists what will happen (copy of the old jar, download, variable changes, Java change) and warns about downgrades, switching between plugin and mod software (Paper and Fabric), snapshots, and Vanilla. Warnings that can cost you a world must be ticked off before the button works. A link to **Backups** is right there; take one first.
- **Safe replace:** the running jar is renamed to `server.jar.bak-<time>` (the two newest copies are kept), the new one is downloaded with the panel's own pull, and the file is checked. If anything fails, the old jar is put back.
- **Startup variables:** `MINECRAFT_VERSION` and `BUILD_NUMBER` are updated when the egg lets users edit them and its rules accept the value. Paper and Purpur get the build number, Fabric and Vanilla get `latest`, so a later reinstall from the egg does not ask for a build that does not exist.
- **Java:** shows the Docker image in use, the recommended one for the Minecraft version, and switches with one click. The mapping is: up to 1.16 Java 11 (Java 8 if the egg has no 11), 1.17 Java 16, 1.18 to 1.20.4 Java 17, 1.20.5 to 1.21.x Java 21, 26.x and newer Java 25. Only images the egg offers are used; if an exact match is missing, the oldest offered image that is still new enough is recommended.
- **Previous version:** restores the newest `.bak` jar together with the startup variables and Java image it had. The jar being replaced is kept as a copy too, so restoring twice undoes itself.
- **Stop the server first:** changes only run on a stopped server. The page says so, with a Stop button.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- The panel server must reach `fill.papermc.io`, `api.purpurmc.org`, `meta.fabricmc.net` and `piston-meta.mojang.com`, and the Wings node must reach the matching download hosts (`fill-data.papermc.io`, `api.purpurmc.org`, `meta.fabricmc.net`, `piston-data.mojang.com`).
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/mc-version-1.0.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/mc-version-1.0.0.pteroext --enable --replace
```

Nothing is stored in the panel's database, so there is nothing to migrate.

## Setup

The **Version** page appears on servers whose egg has the `minecraft` tag, or the `eula` feature that every Java egg has. It is hidden for Bedrock (`bedrock` tag) and for proxies (BungeeCord, Waterfall, Velocity).

Things worth knowing:

- Switching **between plugin and mod software** (Paper and Fabric) does not touch your `plugins/` or `mods/` folders, but nothing in them will load. Fabric also downloads its libraries on the first start, which can take a minute.
- **Downgrading** is the risky one: worlds saved by a newer version often cannot be opened by an older one. Restore a world backup from that version first.
- The server's **egg** is not changed. A Paper egg running a Fabric jar keeps working, and the page tells you about it. Reinstalling the server from the egg puts the egg's software back.
- A Docker image an administrator typed in by hand (one the egg does not list) is left alone.
- **Checksums:** Wings cannot check a checksum itself. After the download the panel checks the file size against what the project published, and for jars up to 24 MB it also reads the file back and compares the checksum (SHA-256, SHA-1 or MD5, whichever the project publishes). Larger jars are checked by size only, and Fabric publishes neither. The download is always HTTPS from the project's own host.
- The panel's activity log records the rename, download, variable and image changes, plus a **version switch** and **version rollback** entry per change.

## Permissions

This extension has no permissions of its own. It uses the panel's.

| Needs | Permissions |
|---|---|
| See the page | `file.read` and `startup.read` |
| Switch version or restore a jar | `file.update`, `file.create` and `startup.update`, besides the above |
| Change the Java version (alone or with a switch) | `startup.docker-image` |
| Stop the server from the page | `control.stop` |
| Delete old copies (keeps the two newest) | `file.delete` |

Without `file.delete`, old copies are not removed and pile up until someone deletes them in the file manager. Without `startup.docker-image`, a switch still works and says that Java was left alone. Server owners and admins have all of them.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
