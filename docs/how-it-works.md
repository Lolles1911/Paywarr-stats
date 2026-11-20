# How it works

Where the numbers come from, and what to do when they don't show.

## Where the numbers come from

- **Silo** customers' numbers come from Silo itself: totals from its watch
  summary, everything else from their latest 200 plays, with posters, genres
  and the size of each show from Silo's catalog. Silo keeps totals for a year
  at most, so for a customer only on Silo the big number is **the last 12
  months** (and says so). Silo is not at 1.0 yet and this has been tested
  against its published API contract, not yet on a real Silo server.
- **Plex** is supported, but less tested than Jellyfin and Emby. Customers are
  found by username, and Wizarr records a Plex user's display name, which
  usually is the same as the Plex username but can differ; such a customer
  sees "Nothing watched yet". Feedback is very welcome.
- **Only the customer's own numbers.** Paywarr records each customer's media
  server users on their subscriptions. Paywarr Stats finds those users in
  Tracearr (same server type and username, accounts not removed) and asks
  Tracearr about them only; on Silo it asks about the customer's own account
  ID, and drops any play that belongs to someone else. Nobody sees anyone
  else's numbers, and neither is asked about another customer.
- **Fast pages.** The numbers are kept for 10 minutes per customer, so page
  views don't load Tracearr or Silo. "Nothing watched yet" is kept for only a
  minute, so a new customer's first play shows up soon.
- **Posters** come from Tracearr's image proxy or Silo's catalog, fetched by
  Paymenter and passed on, so both can stay on your own network. Silo's key is
  only sent to Silo itself, never to an object storage address. The page only asks for poster
  number 1, 2, 3 … of the customer's own list, never for an address, so it
  can't be used to fetch anything else; only real images are passed on (no
  SVG). Throttled to 120 a minute per customer.
- **Patterns** (weekdays, time of day, devices, streaks) come from the
  customer's latest 200 plays per media server user, in Paymenter's time zone.
- **A show on two servers** (Jellyfin and Emby) is shown once, with the
  progress of the server where the customer got furthest.
- **Tracearr down or a wrong key:** the customer sees "Your statistics can't
  be loaded right now", and the reason is in Paymenter's log.
- **History:** Tracearr can import plays from before it was installed (e.g.
  from Jellyfin's Playback Reporting plugin or Tautulli); they show up here too.
- **Languages:** English, and Danish when Paymenter is set to Danish. Add a
  language by copying `extensions/Others/PaywarrStats/lang/da.json`.

Not shown (yet): favourites. Tracearr records plays, not favourites.

## Troubleshooting

**Check one customer**, step by step: the settings, the media server users
Paywarr noted on their subscriptions, what Tracearr and Silo know about them,
and what the page will show. It only reads, and makes the customer's next visit
ask afresh:

```bash
su -s /bin/bash www-data -c "php /opt/paywarr-stats/tools/stats-check.php /opt/paymenter customer@example.com"
```

Errors are in Paymenter's log:

```bash
grep -h "Paywarr Stats" /opt/paymenter/storage/logs/laravel-$(date +%F).log | tail -5
```

| Message | Fix |
|---|---|
| `Tracearr rejected the API key` | Make a new key in Tracearr (Settings → Data & API → API) and paste it again |
| `Could not reach Tracearr at …` | Check the internal URL: IP and port, reachable from the Paymenter machine |
| `Silo rejected the API key` / `Could not reach Silo at …` | The same for Silo: a key from Admin → API Keys, and Silo's own port (not 8096) |
| `Silo refused … (403)` | The key must belong to an admin account, with full access or `admin:monitor:read` |
| No posters or genres for Silo customers | The key has only `admin:monitor:read`, or your admin account's main profile has a PIN; posters need full access and a profile without PIN |
| "Nothing watched yet" for someone who has watched | Their username in Tracearr differs from the one Paywarr recorded, or the account is removed in Tracearr |

[← Paywarr Stats](../README.md)
