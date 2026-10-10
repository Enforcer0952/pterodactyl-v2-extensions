# Minecraft Players: players, whitelist, bans and ops on the console page

Adds a **Players** card under the console of Minecraft Java servers in Pterodactyl 2.0.

## Features

- **Online:** who is on right now (read with `list`), with Kick, Ban, Op and Whitelist buttons.
- **Offline:** everyone who has joined before (from `usercache.json`), with last-seen date and all actions.
- **Whitelist:** add or remove players, and turn the whitelist on or off.
- **Bans:** player and IP bans with reason, source and date, plus Unban.
- **Operators:** op and deop.
- Works while the server is **stopped** too: changes are written to `whitelist.json`, `ops.json`, `banned-players.json` and `server.properties`, and apply on the next start.
- Online-mode servers look players up with Mojang; offline-mode servers get the correct offline UUIDs.
- Updates live from the console (joins, leaves, list changes), with no polling.
- Input is validated, so a name or reason can never inject a second console command.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/mc-players-1.1.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/mc-players-1.1.0.pteroext --enable --replace
```

Settings and data are kept.


## Setup

The card appears on servers whose egg has the `minecraft` tag, or the `eula` feature that every Java egg has. It's hidden for Bedrock (`bedrock` tag) and for proxies (BungeeCord, Waterfall, Velocity).

## Permissions (Subusers)

| Permission | Allows |
|---|---|
| `ext.mc-players.view` | See the card |
| `ext.mc-players.kick` | Kick |
| `ext.mc-players.ban` | Ban, unban, IP bans |
| `ext.mc-players.whitelist` | Whitelist changes |
| `ext.mc-players.op` | Op and deop |

Server owners and admins have all of them.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
