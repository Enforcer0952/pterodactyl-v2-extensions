# Minecraft Content: plugins and mods from Modrinth

Adds **Plugins** and **Mods** pages to Minecraft servers in Pterodactyl 2.0. Search Modrinth and install with one click. Files go straight into `/plugins` or `/mods`.

## Features

- Search Modrinth filtered to the server's loader and Minecraft version (both detected automatically).
- Works out what the server really runs from its files, so a Fabric server started from a Paper egg gets the Mods page, not Plugins. Each server only shows the page it needs.
- One-click install, update and uninstall. Required dependencies are installed too (can be turned off).
- Recognises jars you added by hand (SHA-1 lookup on Modrinth), so they show as installed and get update checks.
- Updates only offer stable releases for the server's Minecraft version, never a backport (unless you are already on a beta, or turn pre-releases on).
- Version picker with release, beta and alpha builds (pre-releases are optional).
- Plugin loaders: Paper, Purpur, Folia, Spigot, Bukkit, Sponge. Proxies: Velocity, Waterfall, BungeeCord. Mod loaders: Forge, NeoForge, Fabric, Quilt.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/mc-content-1.5.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/mc-content-1.5.0.pteroext --enable --replace
```

Settings and data are kept.


## Setup: egg tags

The pages appear based on the **egg tags** of a server's egg (Admin → Eggs → Tags):

| Page | Tags (any of) |
|---|---|
| Plugins | `paper`, `purpur`, `folia`, `spigot`, `bukkit`, `sponge`, `velocity`, `waterfall`, `bungeecord`, `proxy`, `plugins` |
| Mods | `forge`, `neoforge`, `fabric`, `quilt`, `mods` |

Hybrid eggs (Arclight, Mohist, Magma) can carry both a mod tag and `plugins`.

## Permissions

Users need the **file.read** permission to see the pages, and file create/delete permissions to install or remove content.

## Settings (Admin → Extensions → Minecraft Content)

- Install required dependencies (default on)
- Offer beta and alpha versions (default off)
- Search results per page (default 20)

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
