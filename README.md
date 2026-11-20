<p align="center"><img src="assets/banner.png" alt="Paywarr Stats" width="760"></p>

# Paywarr Stats

[![Tests](https://github.com/Lolles1911/Paywarr-stats/actions/workflows/tests.yml/badge.svg)](https://github.com/Lolles1911/Paywarr-stats/actions/workflows/tests.yml)

**Every customer's own watch statistics, on a page in the Paymenter client area.**

![The "My stats" page](screenshots/stats.png)

A [Paymenter](https://paymenter.org) extension that adds **My stats** to the
client area. [Tracearr](https://github.com/connorgallopo/tracearr) collects
what is watched on your Plex, Jellyfin and Emby servers, and
[Silo](https://github.com/Silo-Server/silo-server) records it itself; Paywarr
Stats shows each customer their own part of it:

- Watch time and plays for the last 7 days, the last 30 days and all time,
  plus movies and episodes watched and their current and longest streak
- When they watch: per weekday and time of day, with their favourite of each
- Their favourite genres, and where they watch (devices, and how much plays
  directly without converting)
- Their shows, with posters and how far they are ("14 of 18 episodes")
- What they watched recently, with posters: each show or movie once, with
  episode, date and how much of it

Customers log in to Paymenter as usual, Discord included through
[Paywarr](https://github.com/Lolles1911/Paywarr), and need no Tracearr or Silo
admin account.

## What it needs

| | |
|---|---|
| **Paymenter** | v1.5.9 or newer |
| **[Paywarr](https://github.com/Lolles1911/Paywarr)** | Knows which Plex, Jellyfin, Emby and Silo users belong to which customer. Paywarr Stats reads that, so it only works next to Paywarr |
| **[Tracearr](https://github.com/connorgallopo/tracearr)** | For Plex, Jellyfin and Emby: running and connected to your media servers, reachable from Paymenter (it can stay on your own network) |
| **[Silo](https://github.com/Silo-Server/silo-server)** | For Silo: nothing extra, Silo records plays itself. Paymenter needs to reach Silo and an API key |

You need Tracearr, Silo or both, depending on what your customers use.

## Installation

1. **Get the repo and install it** (Paymenter in `/opt/paymenter`; on Docker,
   give the folder with Paymenter's `docker-compose.yml` instead):

   ```bash
   git clone https://github.com/Lolles1911/Paywarr-stats.git /opt/paywarr-stats
   bash /opt/paywarr-stats/install.sh /opt/paymenter
   ```

2. **Make the keys:**
   - Tracearr: Settings → Data & API → API → **Generate key**. It starts with
     `trr_pub_` and can only read.
   - Silo: Admin → API Keys → **Create API Key** on your admin account. **Full
     access** gives everything; a key with only `admin:monitor:read` gives the
     numbers and history but no posters or genres. The key Paywarr uses for
     Silo works too.
3. **Install it in Paymenter:** Admin → Extensions → **Available Extensions** →
   Paywarr Stats → **Install**.
4. **Set it up:** open it, fill in **Tracearr URL (internal)** (e.g.
   `http://192.168.1.10:3000`) and **Tracearr API key**, and/or **Silo URL
   (internal)** (Silo's own port, e.g. `http://192.168.1.10:8090`) and **Silo
   API key**. Turn on **Enabled** and save.

**My stats** then appears in the client area menu and in the top bar, next to
Home and Shop.

## Updating

```bash
cd /opt/paywarr-stats && git pull && bash install.sh /opt/paymenter
```

What changed in each version is on the
[Releases](https://github.com/Lolles1911/Paywarr-stats/releases) page.

## Documentation

| | |
|---|---|
| [Stats overview and reminders](docs/overview.md) | The staff overview (business, servers, who watches what), and reminding customers who watch nothing |
| [How it works](docs/how-it-works.md) | Where the numbers come from, and troubleshooting |
| [Tests and files](docs/development.md) | Running the tests, and what each file does |
| [CHANGELOG](CHANGELOG.md) | What changed in each version |

## License

[MIT](LICENSE).
