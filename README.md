# Pterodactyl 2.0 Extensions

Free, open-source extensions for **Pterodactyl Panel 2.0** that turn the panel into a real game-server control room: install Minecraft plugins and mods from Modrinth, manage players across all your servers, switch Minecraft versions, back up and reset worlds, run ARK and Terraria servers without touching config files.

Built for and used every day on a home-hosted panel with Minecraft (Paper, Fabric, a Waterfall network), ARK: Survival Evolved and Terraria servers.

[![Latest release](https://img.shields.io/github/v/release/Enforcer0952/pterodactyl-v2-extensions?label=release)](https://github.com/Enforcer0952/pterodactyl-v2-extensions/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/Enforcer0952/pterodactyl-v2-extensions/total)](https://github.com/Enforcer0952/pterodactyl-v2-extensions/releases)
[![CI](https://github.com/Enforcer0952/pterodactyl-v2-extensions/actions/workflows/ci.yml/badge.svg)](https://github.com/Enforcer0952/pterodactyl-v2-extensions/actions/workflows/ci.yml)
[![License: GPL-3.0](https://img.shields.io/github/license/Enforcer0952/pterodactyl-v2-extensions)](LICENSE)
![Pterodactyl 2.0](https://img.shields.io/badge/Pterodactyl-2.0-0e4688)

| | |
|---|---|
| ![Modrinth project page inside the panel](docs/screenshots/mc-content-popup.png) | ![Player profile with 3D skin, stats and all servers](docs/screenshots/mc-players-profile.png) |
| Any Modrinth plugin or mod, opened right inside the panel | Every player: 3D skin, stats, bans and actions on all your servers |

> **Pterodactyl 2.0 only.** These use the panel's new native extension system (`p:extension:*`). They do not work on Pterodactyl 1.x or with Blueprint.

## Extensions

**[Download the latest release](https://github.com/Enforcer0952/pterodactyl-v2-extensions/releases/latest)**: one `.pteroext` file per extension. Install only the ones you need; each one only shows up on servers it fits.

### Minecraft

| Extension | What it does |
|---|---|
| [**Minecraft Content**](extensions/mc-content) | **Plugins** and **Mods** pages: search Modrinth, open any project in an in-panel page (description, gallery, versions, changelogs, dependencies), install, update or remove in one click. Detects the loader and Minecraft version, installs dependencies and recognises jars you uploaded yourself. Paper, Purpur, Folia, Spigot, Bukkit, Sponge, Velocity, Waterfall, BungeeCord, Forge, NeoForge, Fabric, Quilt. |
| [**Minecraft Players**](extensions/mc-players) | **Players** card under the console: online and offline players, whitelist, bans and operators with one-click actions, also while the server is stopped. Click a player for a profile with a 3D skin, stats, MCBans history, and the same player on every server you run, with actions on one or all of them. |
| [**Minecraft Console**](extensions/mc-console) | Command autocomplete in the console: vanilla and plugin commands with syntax hints, player names, Tab to complete. |
| [**Minecraft Version**](extensions/mc-version) | **Version** page: see what a server really runs, switch between Paper, Purpur, Fabric and Vanilla or to another Minecraft version, with the right Java image picked for you and a one-click way back. |
| [**Minecraft World**](extensions/mc-world) | **World** page: back up, restore, reset and switch worlds, a "new hardcore run" button, and a proper form for `server.properties`. |
| [**Minecraft Network**](extensions/mc-network) | **Proxy** page on BungeeCord and Waterfall proxies: every backend matched to its panel server with live state, broken entries flagged (wrong ports, duplicates, missing names) and fixed without editing YAML. |

### ARK: Survival Evolved

| Extension | What it does |
|---|---|
| [**ARK Tools**](extensions/ark-tools) | Steam Workshop mod manager with update checks, a **Config** page for `GameUserSettings.ini` and `Game.ini` (presets like 2x/5x/10x, problem check, safe apply with stop and start), players, whitelist, bans and admins, admin buttons, safe restarts with an in-game countdown, daily restarts and RCON command autocomplete. |

### Terraria

| Extension | What it does |
|---|---|
| [**Terraria Tools**](extensions/terraria-tools) | Online players with kick and ban, ban list, admin buttons (save, settle, time of day, broadcast), **Worlds** page with backups, restore and new worlds, a **Server settings** page for `serverconfig.txt`, and console autocomplete. For vanilla Terraria servers. |

More games are on the way. [Ask for yours](https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues/new?template=feature_request.yml).

## Screenshots

<details open>
<summary><b>Minecraft</b></summary>

| | |
|---|---|
| ![Plugins page](docs/screenshots/mc-content-browse.png) **Minecraft Content**: search Modrinth and install in one click | ![Installed plugins](docs/screenshots/mc-content-installed.png) Installed plugins, with updates ready to apply |
| ![Players card](docs/screenshots/mc-players-online.png) **Minecraft Players**: online players with actions | ![Bans](docs/screenshots/mc-players-bans.png) Bans with reason, source and date |
| ![Console autocomplete](docs/screenshots/mc-console-gamemode.png) **Minecraft Console**: command autocomplete and syntax hints | ![Player names](docs/screenshots/mc-console-player.png) Player names and plugin commands |
| ![Version page](docs/screenshots/mc-version-page.png) **Minecraft Version**: switch software and version | ![Change plan](docs/screenshots/mc-version-plan.png) See exactly what changes before it happens |
| ![Worlds](docs/screenshots/mc-world-worlds.png) **Minecraft World**: backups, resets, hardcore runs | ![server.properties form](docs/screenshots/mc-world-properties.png) A real form for server.properties |
| ![Proxy page](docs/screenshots/mc-network-proxy.png) **Minecraft Network**: every backend checked and fixable | |

</details>

<details open>
<summary><b>ARK: Survival Evolved</b></summary>

| | |
|---|---|
| ![ARK config](docs/screenshots/ark-config.png) **Config page** with presets | ![Workshop mods](docs/screenshots/ark-mods.png) Workshop mods with update checks |
| ![ARK players](docs/screenshots/ark-players.png) Players, whitelist, bans and admins | ![ARK admin](docs/screenshots/ark-admin.png) Admin actions and safe restarts |

</details>

<details open>
<summary><b>Terraria</b></summary>

| | |
|---|---|
| ![Terraria players](docs/screenshots/terraria-players.png) Online players and bans | ![Terraria admin](docs/screenshots/terraria-admin.png) Save, settle, time of day and broadcast |
| ![Terraria worlds](docs/screenshots/terraria-worlds.png) Worlds with backups and new worlds | ![Terraria settings](docs/screenshots/terraria-settings.png) serverconfig.txt as a form |

</details>

## Install

1. Download the `.pteroext` files you want from the [latest release](https://github.com/Enforcer0952/pterodactyl-v2-extensions/releases/latest).
2. On your panel host, from the panel directory (usually `/var/www/pterodactyl`):

   ```bash
   sudo -u www-data php artisan p:extension:install /path/to/<extension>.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel (Ctrl+Shift+R).

**Update** the same way with `--replace`. Settings and data are kept.

Each extension's README lists the eggs it works with, its settings and its subuser permissions.

### Verify a download

Every release lists a SHA-256 digest next to each file, and ships a `SHA256SUMS.txt`:

```bash
sha256sum --check --ignore-missing SHA256SUMS.txt
```

## Requirements

- Pterodactyl Panel 2.0 (2.0.0-beta or newer)
- PHP 8.3+ (same as the panel)
- The panel server needs outbound HTTPS for the extensions that talk to outside services (Modrinth, Steam, PaperMC, Mojang, mc-heads, MCBans). Everything else runs through the panel and Wings.

## Build from source

The extension SDK ships with the panel, so build next to a panel checkout:

```bash
git clone https://github.com/pterodactyl/panel.git -b 2.0-develop
git clone https://github.com/Enforcer0952/pterodactyl-v2-extensions.git
cd panel && npm ci && npm run sdk:generate && cd ..

# The extensions expect the SDK at ../../packages/sdk
ln -s ../panel/packages pterodactyl-v2-extensions/packages

cd pterodactyl-v2-extensions/extensions/mc-content
npm install --legacy-peer-deps
npm run build && npm test
cd ../.. && composer install && (cd extensions/mc-content && ../../vendor/bin/phpunit)

# Pack every built extension, with checksums
php scripts/build-release.php extensions out
```

Releases are built the same way by the [Release workflow](.github/workflows/release.yml).

## Support and contributing

- Bugs: [open an issue](https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues/new?template=bug_report.yml).
- Ideas and new games: [request a feature](https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues/new?template=feature_request.yml).
- Pull requests are welcome.

If these save you time, the **Sponsor** button at the top of the page helps keep them going.

## About

Made by [Enforcer0952](https://github.com/Enforcer0952). Every extension runs on my own Pterodactyl 2.0 panel before it is released.

Not affiliated with or endorsed by Pterodactyl, Modrinth, Mojang/Microsoft, Valve, Studio Wildcard, Re-Logic or MCBans.

## License

[GPL-3.0](LICENSE)
