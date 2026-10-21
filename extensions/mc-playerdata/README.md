# Minecraft Player Data: inventory, ender chest, position and effects

Adds a **Player data** page for Minecraft Java servers in Pterodactyl 2.0. It shows what the server has saved for a player, and, with the server stopped, lets you fix it: take out an item, clear the inventory, move the player to safety.

The page lives at `/server/<id>/player-data/<name or UUID>`. It has no menu entry of its own: other extensions link to it (for example the Minecraft Players profile), or you open the address directly.

## Features

- **Inventory** as a grid: main inventory, the hotbar set apart below it, armor and off hand in a column next to it.
- **Ender chest** as a 27-slot grid.
- **Item details:** click a slot for the item's name and id, count, enchantments (with levels), custom name, lore, durability and, for shulker boxes and bundles, what is inside. Items from mods and data packs are shown with their namespace (`create:brass_ingot`).
- **Details panel:** position and dimension, spawn point, experience level and progress, health, food, game mode and active effects with the time left.
- Understands **both item formats**: before Minecraft 1.20.5 (`tag`, `Count`) and from 1.20.5 on (data components, `count`), and where armor is stored: in the inventory list (before 1.21.5) or in an `equipment` compound (1.21.5 and later). Items in slots outside the normal inventory are listed too, never hidden.
- Items are drawn as **text tiles**, not game textures, which cannot be redistributed.
- Pick another player from the list at the top. Players are found through `usercache.json`; you can use a name or a UUID in the address.

### Editing

Edits work **only while the server is stopped**. A running server keeps online players in memory and writes them back when they leave, so an edit made to the file would be lost, or would overwrite what they did. The page is read-only while the server runs and says why. (It reads the last saved copy, so it can be a little behind what an online player has right now.)

- **Remove an item:** one from a stack, or the whole stack, from any slot.
- **Clear inventory** (including armor and off hand), **clear ender chest**, **clear effects**.
- **Set game mode:** survival, creative, adventure or spectator.
- **Move the player:** to coordinates and a dimension, or to the world spawn from `level.dat`. They also stop riding a vehicle and lose any fall in progress.

Safety:

- Before every change the old file is copied to `<uuid>.dat.mcpd-bak-<time>` next to it. The newest three are kept.
- If the file changed since you opened the page, the change is refused (409) and nothing is written. Reload and try again.
- Only the tags an edit needs are touched. Everything else, including data from mods, is written back byte for byte. A file this reader would not write back exactly as it found it is not edited at all.
- Every confirm dialog names what is about to go.
- Edits to the file are also in the server's activity log (the file writes, plus one "player data edit" entry with the action).

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/mc-playerdata-1.0.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/mc-playerdata-1.0.0.pteroext --enable --replace
```

## Setup

The page is available on servers whose egg has the `minecraft` tag, or the `eula` feature that every Java egg has. It is hidden for Bedrock (`bedrock` tag) and for proxies (BungeeCord, Waterfall, Velocity).

The world folder comes from `level-name` in `server.properties` (default `world`). Player files are read from `<world>/playerdata/<uuid>.dat`. A player appears once the server has saved them, which happens when they leave.

Only plain folder names are supported for the world. A `level-name` with a slash in it is not.

## Permissions (Subusers)

| Permission | Allows |
|---|---|
| `ext.mc-playerdata.read` | Open the page and see a player's data |
| `ext.mc-playerdata.manage` | Edit it, while the server is stopped |

Server owners and admins have both. Either can be granted to any subuser on its own: `manage` without `read` cannot open the page.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version, your Minecraft version and the error text or request ID.
