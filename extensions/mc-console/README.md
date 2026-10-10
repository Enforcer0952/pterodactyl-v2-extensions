# Minecraft Console: command autocomplete

Adds Minecraft-style command suggestions to the Pterodactyl 2.0 server console.

## Features

- Suggestions for all vanilla commands while you type, with a syntax line and description.
- **Tab** completes, **↑/↓** chooses, **Esc** hides.
- Knows argument types: online player names, selectors (`@a`, `@p`…), game modes, difficulty, coordinates, and nested commands (`execute … run …`).
- Reads **plugin commands** from the `plugin.yml` of every jar in `/plugins`, including aliases and usage lines.
- No changes to the panel itself: it hooks into the existing console input.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/mc-console-1.0.1.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/mc-console-1.0.1.pteroext --enable --replace
```

Settings and data are kept.


## Setup

Active on servers whose egg has the `minecraft` tag or the `eula` feature. Not active on Bedrock or proxy eggs. Users need the **control.console** permission.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
