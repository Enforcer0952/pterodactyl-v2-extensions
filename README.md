# Pterodactyl 2.0 Extensions

Free, open-source extensions for the **Pterodactyl Panel 2.0** extension system — built for and used on a real home-hosted game-server panel.

> **Pterodactyl 2.0 only.** These use the new native extension API (`p:extension:*`). They do **not** work on Pterodactyl 1.x or with Blueprint.

| Extension | What it does |
|---|---|
| [**Minecraft Content**](extensions/mc-content) | Plugins / Mods pages: search Modrinth and install, update or remove plugins and mods in one click. Detects loader and Minecraft version, installs dependencies, recognises jars you uploaded yourself. Paper, Purpur, Folia, Spigot, Bukkit, Sponge, Velocity, Waterfall, BungeeCord, Forge, NeoForge, Fabric, Quilt. |
| [**Minecraft Players**](extensions/mc-players) | Players card under the console: online and offline players, whitelist, bans (player + IP) and operators — with kick/ban/op/whitelist buttons. Works while the server is stopped. |
| [**Minecraft Console**](extensions/mc-console) | In-game style command autocomplete in the console: vanilla commands, plugin commands from `plugin.yml`, player names, Tab to complete. |
| [**ARK Tools**](extensions/ark-tools) | ARK: Survival Evolved: Workshop mod manager with update detection, players/whitelist/bans/admins, admin buttons, safe restarts with in-game countdown, daily restarts, RCON command autocomplete. |

## Install

1. Download the `.pteroext` file from the [latest release](../../releases/latest).
2. On your panel host, from the panel directory (usually `/var/www/pterodactyl`):
   ```bash
   sudo -u www-data php artisan p:extension:install /path/to/<extension>.pteroext --enable
   ```
   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel (Ctrl+Shift+R).

Update the same way with `--replace`. Each extension's README lists the egg tags it needs and its subuser permissions.

## Build from source

The SDK ships with the panel, so build inside your panel checkout:

```bash
cp -r extensions/mc-content /var/www/pterodactyl/extensions-src/   # or anywhere two levels below the panel root
cd /var/www/pterodactyl/extensions-src/mc-content
npm install --legacy-peer-deps      # "@pterodactyl/sdk": "file:../../packages/sdk"
npm run build && npm test
php ../../artisan p:extension:doctor .
php ../../artisan p:extension:pack . --output=mc-content.pteroext
```

## Support & contributing

Bugs and ideas: [open an issue](../../issues). Pull requests welcome.

If these save you time, you can support development with the **Sponsor** button at the top of this page. ❤️

## About

Made by [Enforcer0952](https://github.com/Enforcer0952). Tested and run on my own Pterodactyl 2.0 panel.

Not affiliated with or endorsed by Pterodactyl, Modrinth, Mojang/Microsoft or Valve/Studio Wildcard.

## License

[GPL-3.0](LICENSE)
