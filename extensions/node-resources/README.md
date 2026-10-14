# Node Resources: real memory and disk numbers for every node

Adds a **Resources** tab to every node in the Pterodactyl 2.0 admin area, an overview page for all nodes, warnings before a node runs full, an allocation helper on the server forms and a History card on the console page.

## Features

- **Three numbers for RAM, disk and CPU:** what is *allocated* to servers, what is *really used*, and what is *free on the host*.
- **Overallocate settings** of the node shown next to the numbers, so you can see how far you can still go.
- **Per-server table:** limit, current use and 7-day peak of memory, disk and CPU for every server on the node.
- **Graphs** for the last 24 hours and 7 days (longer, up to a year, depending on the retention setting): disk, memory and load.
- **Disk-full forecast** from the 7-day trend ("full in about 12 days"). It needs at least 24 hours of data.
- **Admin overview** (**Admin → Node resources**) with every node, its warnings and the probe state.
- **Warning banner** above the admin overview and node list when a node's disk is over the limit you set (85 % by default), the forecast says full within 7 days (default), or memory is overcommitted. Servers with unlimited memory count with their 7-day peak. Warnings are shown in the panel only; nothing is sent anywhere.
- **Allocation helper** on the create-server form and on a server's build page: shows how much room the node has and warns when the memory or disk you type does not fit.
- **Suggested limits** for servers with unlimited memory or disk: memory = 7-day peak + 25 %, disk = current use + 25 %, rounded to a sensible step and never below what is used. One click applies them through the panel's own build update. Suggestions appear after 24 hours of data.
- **Server History card** on the console page (CPU, memory and disk for 24 hours and 7 days), collapsed by default and shown only to users with the `ext.node-resources.history` permission.
- **Collector** runs every minute through the panel scheduler, skips nodes that cannot be reached and shows when it last ran.
- No external database or service. Everything stays in the panel's database.

## The probe

Wings does not tell the panel how much memory or disk is really free on the host, only how much the servers use. Without that you cannot tell that a node with 500 GB allocated is already 95 % full because of logs, backups or other software.

The probe is a tiny server, one per node, that you create with the **Set up probe** button on the node's Resources tab. Its container can see the host's memory figures and the disk the servers live on, and prints them once a minute. The collector reads that output from the console log.

What it costs:

- **Images:** the small Alpine yolk (about 4 MB) and its installer image (about 11 MB), pulled once per node.
- **Limits:** 64 MB RAM, 50 MB disk, 10 % of one CPU, no swap. In practice it uses a few MB.
- **Allocation:** it uses one free allocation on the node and counts for 64 MB / 50 MB in the node's totals. It is marked as the probe in the server table and left out of suggestions.
- **Network and files:** none. It does not open a port and does not read your servers' files.

Without a probe the extension still works. Memory and disk used by servers are known, and the node tab shows a **Set up probe to see free disk** hint where the host numbers would be.

To remove it, press **Remove probe** on the node's Resources tab. The probe server is deleted; its history stays. Deleting the probe server from the normal server list, or removing the extension, works too. The egg "Node Probe" can be deleted afterwards under **Admin → Eggs**.

Caveats: if the servers' disk is a quota volume (XFS or ext4 project quota, or a sparse image), the free space reported is that of the volume the probe can see. Sizes are counted in 1024 (MiB, GiB), like the panel does.

## Requirements

- Pterodactyl Panel **2.0** (2.0.0-beta or newer; tested on 2.0-develop, October 2026)
- PHP 8.3+ (same as the panel)
- The panel scheduler running (`php artisan schedule:run` every minute from cron). The collector and the nightly clean-up depend on it.
- No build step: the package ships prebuilt.

## Install

1. Copy the `.pteroext` file to your panel server.
2. Run, from the panel directory (usually `/var/www/pterodactyl`):

   ```
   sudo -u www-data php artisan p:extension:install /path/to/node-resources-1.0.0.pteroext --enable
   ```

   Or upload it under **Admin → Extensions**.
3. Hard-refresh the panel in your browser (Ctrl+Shift+R).

## Update

Same command with `--replace`:

```
sudo -u www-data php artisan p:extension:install /path/to/node-resources-1.0.0.pteroext --enable --replace
```

Settings and history are kept.

## Setup

1. Open **Admin → Nodes**, pick a node and open the **Resources** tab.
2. Press **Set up probe**. The probe installs in a minute or two; host numbers appear after its first report.
3. Repeat for each node you want real numbers for.

The history fills up from the first minute. Graphs and the forecast become useful after a day.

## Settings

Under **Admin → Extensions → Node Resources**:

| Setting | Options |
|---|---|
| Keep history for | 30 days (default) or 90 days with 5-minute points, or 1 year with hourly points (5-minute points are kept for the last 30 days) |
| Warn when the disk is more than this full | Percent, 85 by default |
| Warn when the disk is expected to be full within | Days, 7 by default |

Raw one-minute samples are kept for 48 hours. A nightly job rolls older data up and deletes what is past the retention. The tab shows when the collector last ran and warns if it has been quiet for more than five minutes.

## Permissions (Subusers)

| Permission | Allows |
|---|---|
| `ext.node-resources.history` | See the History card on the server's console page |

The admin tabs, overview, warnings and probe controls are for root admins only. Server owners and admins have the history permission.

## Support

Open an issue at https://github.com/Enforcer0952/pterodactyl-v2-extensions/issues. Please include your panel version and the error text or request ID.
