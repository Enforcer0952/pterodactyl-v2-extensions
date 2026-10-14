# Valheim Tools: players, access lists, worlds, mods and settings for Valheim servers

Adds a **Players** card and a **Server settings** card under the console, plus **Access lists**, **Worlds** and **Mods** pages, to Valheim dedicated servers in Pterodactyl 2.0.

## Features

- **Players:** who is online, read from the console, and who was seen recently. Each player with a Steam ID has **Make admin**, **Permit** and **Ban** buttons that edit the access list files. The first and last time a player was seen is kept, so the names show up next to the IDs on the Access lists page.
- **Access lists:** `adminlist.txt`, `bannedlist.txt` and `permittedlist.txt`. Add a player by Steam ID or Steam profile link, remove a player, see the name next to each ID. IDs are checked before anything is written. Comments, blank lines and line endings in the files are kept, and every save keeps the old file as a `.bak`. The page warns that a permitted list with anyone on it means only those players can join.
- **Worlds:** every world with size and date, and the one the server loads (the `WORLD` startup variable).
  - **Use:** make a world the active one, or enter a new name to let Valheim generate a world with that name on the next start.
  - **Back up:** copies the world to `valheim-backups/<name>-<date>/`. Each world keeps its newest backups (default 5, see Settings). A running server asks for confirmation first, because Valheim has no save command for dedicated servers and a copy can catch a file while it is written.
  - **Restore:** needs the server stopped. The world it replaces is backed up first. Works for the backups made here and for **Valheim's own automatic backups** (`NAME_backup_auto-...`).
  - **Delete:** needs the server stopped, asks for confirmation and makes a backup first by default. The active world cannot be deleted.
  - Worlds in the new Valheim 1.0 folder format and in the older `.db` + `.fwl` format both work.
- **Mods:** only on servers that load BepInEx. Lists what is in `BepInEx/plugins`, `patchers` and `monomod` with the name and version from each mod's `manifest.json`. Search Thunderstore, or paste `Owner-Name` or a Thunderstore link. **Install** also installs the mods it needs (not BepInEx itself). **Update** shows when Thunderstore has a newer version. **Remove** deletes the mod's folder. Installing, updating and removing need the server stopped. BepInEx itself (`BepInEx/core`, the loader files, the `BepInExPack_Valheim` package) is never installed, changed or removed. Config files in `BepInEx/config` are kept.
- **Server settings:** server name, password (hidden until you press Show), show in the server list, crossplay, and a world preset if the egg has a `PRESET`, `WORLD_PRESET` or `SERVER_PRESET` variable. Values are checked against Valheim's rules (password at least 5 characters and not part of the world name) and the egg's own rules, and nothing is saved unless all of them pass. Changes apply the next time the server starts.
- Everything that ends up in a file path or the startup command is validated, so a name, ID or world name can never reach outside the server folder or inject a command.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- A Valheim dedicated server from one of the usual eggs (the Pelican/Pterodactyl community eggs, vanilla or BepInEx).
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/valheim-tools-1.0.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/valheim-tools-1.0.0.pteroext --enable --replace
```

Settings and data are kept.

## Setup

The cards and pages appear on servers whose egg has the `valheim` tag, **or** whose startup command contains `valheim_server`. The common community eggs have no `valheim` tag, so the startup command is what matches. The **Mods** page also needs `BepInEx` or `doorstop` in the startup command, which the BepInEx eggs have.

How it finds things:

| What | Where |
|---|---|
| Save folder | `-savedir` from the startup command if it points inside the server, else `.config/unity3d/IronGate/Valheim` (the eggs run with `HOME=/home/container`) |
| Worlds | `worlds_local` in the save folder |
| Access lists | `adminlist.txt`, `bannedlist.txt`, `permittedlist.txt` in the save folder |
| Backups made here | `valheim-backups` in the server folder |
| Active world | the `WORLD` startup variable |
| Settings | the `SERVER_NAME`, `PASSWORD`, `PUBLIC_SERVER`, `ENABLE_CROSSPLAY` startup variables |

If the egg sets `-savedir` through a startup variable, the extension cannot see the folder and falls back to the default. Put the path in the startup command instead.

### Things worth knowing

- **Access lists apply after a restart.** Valheim reads the three files when it starts. The extension does not claim they reload while the server runs.
- **Who is online** comes from the console. Valheim's dedicated server has no player list command and cannot be given admin commands from the console, so the extension reads the join and leave lines. The panel can only read the last 100 console lines, so the list is built while a page with the Players card is open (it checks every 20 seconds, and right away when the console shows a join or leave). A player who joined while nobody had the console open and scrolled out of the log is not known.
- **Steam IDs:** Valheim 1.0 writes IDs as `V_` followed by the Steam ID. Older servers use the plain Steam ID, and `Steam_` also works. The extension treats all spellings as the same player (it will not add a second line), and adds new players in the style chosen in Settings. Existing lines are never rewritten. Players on other platforms (Xbox, Microsoft Store) log an ID that is not the one the lists take, so they have no buttons on the card; add them by hand on the Access lists page if you know their ID.
- **Backups:** Wings cannot copy a folder to another folder, so a backup packs the world, moves the archive and unpacks it. This works for folder worlds and `.db`/`.fwl` worlds alike. It needs the File → Archive permission.
- **Thunderstore:** search uses the same listing the Thunderstore website uses, package details use its documented API. Answers are cached for ten minutes. Only downloads from `thunderstore.io` are made, with the link built from a validated name and version.
- **Egg modpacks:** some BepInEx eggs install a modpack on every start (`V_MODPACK`). Mods installed here are not part of that pack, but the pack can overwrite files with the same name.

## Settings

Under **Admin → Extensions → Valheim Tools**:

| Setting | Options |
|---|---|
| Backups to keep per world | 1 to 50 (default 5). Older backups are deleted after a new one is made. Valheim's automatic backups are not counted. |
| How Steam players are written to the lists | `V_` + Steam ID (default, Valheim 1.0), `Steam_` + Steam ID, or the plain Steam ID (before 1.0). |

## Permissions (Subusers)

| Permission | Allows |
|---|---|
| `ext.valheim-tools.view` | See who is online and who was seen recently |
| `ext.valheim-tools.players` | The Make admin, Permit and Ban buttons (also needs File → Read content and Update) |
| `ext.valheim-tools.lists` | The Access lists page (also needs File → Read and Read content; changing needs File → Update) |
| `ext.valheim-tools.worlds` | The Worlds page (also needs File → Read) |
| `ext.valheim-tools.mods` | The Mods page (also needs File → Read) |
| `ext.valheim-tools.settings` | The Server settings card (also needs Startup → Read; saving needs Startup → Update) |

The Worlds and Mods pages also use the panel's own file permissions for what they change: File → Create, Update, Delete and Archive to back up, restore or install, Startup → Update to switch the world, File → Delete to remove. The password is only shown to people who may read the startup variables.

Server owners and admins have all of them.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
