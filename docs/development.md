# Tests and files

## Tests

```bash
tests/run.sh      # requires php 8.3+, python3, curl
```

The scenarios run the extension against `tests/tracearr_stub.py`, written from
Tracearr's source (public API v2 and the image proxy) and Silo's API contract, with small stand-ins for
Paymenter and Laravel in `tests/shims.php`. GitHub runs them on every push.

## Files

| File | Contents |
|---|---|
| `extensions/Others/PaywarrStats/PaywarrStats.php` | The extension: settings, menu item, page, posters |
| `extensions/Others/PaywarrStats/TracearrClient.php` | The calls to Tracearr |
| `extensions/Others/PaywarrStats/SiloClient.php` | The calls to Silo |
| `extensions/Others/PaywarrStats/AdminOverview.php` | The staff overview: gathers Silo and Tracearr, linked to customers |
| `extensions/Others/PaywarrStats/BusinessOverview.php` | The staff overview's business part, from Paymenter's subscriptions and invoices |
| `extensions/Others/PaywarrStats/PeopleStats.php` | The admin pages' numbers: per customer, per server, trends |
| `extensions/Others/PaywarrStats/Concerns/People.php` | Gathers 60 days of plays from Tracearr and Silo and Paymenter's side of each customer |
| `extensions/Others/PaywarrStats/Concerns/Wrapped.php` | The year in review: the section in My stats, the mails, the server's year |
| `extensions/Others/PaywarrStats/Admin/Pages/` | The admin pages: Viewing per customer, Viewing per server, Rankings and trends |
| `extensions/Others/PaywarrStats/Admin/StatsPage.php` | What the admin pages share: both menu groups, Refresh, bars and lists |
| `extensions/Others/PaywarrStats/routes.php` | `/stats`, `/stats/admin` and their posters |
| `extensions/Others/PaywarrStats/lang/da.json` | Danish |
| `install.sh` | Copies the extension into Paymenter |
| `tools/stats-check.php` | Checks My stats for one customer, step by step |
| `tests/` | Tracearr and Silo stub, stand-ins and scenarios |
| `assets/` | Banner and the picture GitHub shows when the project is shared |

[← Paywarr Stats](../README.md)
