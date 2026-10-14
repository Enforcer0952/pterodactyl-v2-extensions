# Crash Guard: stop a server that crashes over and over

Adds **Crash Guard** to Pterodactyl 2.0. When a server crashes again and again, Wings keeps restarting it and the loop can go on for hours: worlds get damaged, logs fill the disk and the node does pointless work. Crash Guard counts the crashes and, past a limit you choose, **stops the server and keeps it stopped** until someone clears it.

It works on **every egg** (Minecraft, ARK, Terraria, anything else): it only looks at the server's power state.

## Features

- **Per server on or off**, and a limit of N crashes within M minutes (default 3 in 10). Servers are unguarded until someone turns it on, or an admin turns it on for all servers.
- **Stops the loop:** when the limit is reached the server is stopped. If it does not stop within about 30 seconds it is killed.
- **Keeps it stopped:** if the server comes back up on its own while Crash Guard holds it (a schedule, Wings), it is stopped again.
- **Banner on the console page** while a server is held: "Crash Guard stopped this server after 3 crashes in 10 minutes", with **Clear and start** and **Clear** buttons.
- **Crash Guard page** on each server: the on/off switch, the two numbers, how many crashes count right now, and a log of the last 20 entries (time, how the crash was found, how long the server had been running).
- **Minecraft:** when the Minecraft Crash extension (`mc-crash`) is installed, crashes in the log link to its crash page.
- **Admin overview** (Admin area, **Crash Guard**): every guarded server, its state, its limit, crashes in the last 24 hours and recent trips. Filter to the stopped ones.
- Alerts are in the panel only. Nothing is sent to Discord or email.

## How a crash is found

Wings does not tell the panel when a server crashes, so Crash Guard checks every guarded server **once a minute** (the panel's scheduler must be running, as for schedules). It compares the power state and the uptime with the reading a minute earlier. It counts a crash when the server

- was running (or starting) and is now **offline**, or
- is running again but with an **uptime shorter than the time that passed** (it restarted by itself in between: this is how a crash with a fast automatic restart shows), or
- fell back from running to **starting**.

A change is **not** a crash when someone caused it: a power action in the activity log (the power buttons and API keys) or a schedule with a power task that ran at that time. Wings reports its activity in batches, so a possible crash waits two minutes before it counts, then the log is checked. The crash log shows such entries as "Possible crash".

### What it cannot see

Be aware of these limits:

- **Once a minute.** Two crashes within the same minute count as one. A crash and restart faster than a minute is still caught by the uptime drop, but only if the server had been running for at least about 30 seconds at the previous check.
- **Wings stops restarting a server that crashes twice within 60 seconds.** That is Wings' own protection and it happens before Crash Guard sees anything. It then shows as one crash that stays offline.
- **A server that stops itself** (an in-game `/stop`, or the game quitting) looks like a crash, because Wings usually treats an exit it did not ask for as a crash and restarts the server.
- **A schedule running at the same time** as a real crash hides that crash. A schedule that is still running (a long countdown) explains any change until it ends.
- **An unreachable node** is skipped: no reading, so no crash. A gap longer than 15 minutes between two readings is never compared.
- **Many servers on one node going down in the same minute** (three or more, and at least half of the guarded ones there) is taken for the node restarting, and is not counted.
- Crashes before Crash Guard was turned on for a server, and while it is held, are not counted.

### What happens when it trips

1. The server is stopped (a kill follows on the next check if it is still up after about 30 seconds).
2. The server is marked held and the banner shows on the console page.
3. If the server comes back up without a user starting it, it is stopped again after about 90 seconds. The delay lets the activity log show whether a person started it.
4. If a **person** starts or restarts the server with the power buttons, the hold ends and the counter starts over. Schedules do not end it.
5. **Clear** ends the hold and resets the counter, and the server stays stopped. **Clear and start** also starts it (it needs the Start permission). After a clear, the next trip needs a full set of new crashes.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- The panel's scheduler running (`php artisan schedule:run` every minute, as for any panel)
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/crash-guard-1.0.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/crash-guard-1.0.0.pteroext --enable --replace
```

Settings and data are kept.

## Setup

Nothing is guarded until you turn it on. Either:

- open a server's **Crash Guard** page and turn it on, or
- under **Admin → Extensions → Crash Guard**, turn on **Guard every server by default**. A server can still be switched off on its own page.

## Settings

Under **Admin → Extensions → Crash Guard**:

| Setting | Meaning |
|---|---|
| Guard every server by default | Servers without their own choice follow this. Off by default. |
| Default crashes before stopping | 2 to 20, default 3 |
| Default window in minutes | 1 to 120, default 10 |
| Keep the crash log for (days) | 1 to 365, default 30 |

A server's own numbers are set on its Crash Guard page and take over once saved.

## Permissions (Subusers)

| Permission | Allows |
|---|---|
| `ext.crash-guard.view` | See the Crash Guard page, the log and the console banner |
| `ext.crash-guard.manage` | Turn it on or off, change the limits, clear a held server. Starting the server again also needs the Start permission. |

Server owners and admins have all of them. The admin overview is for administrators only.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
