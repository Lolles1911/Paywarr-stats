# Stats overview, admin pages and reminders

## Year in review

From December 1 to January 31, My stats starts with **"Your year"**: hours
watched, plays, days with something on, longest streak, busiest month,
favourite day and genre, the top 5 titles and the first play of the year.

In the first week of December (checked daily at 11:17) every active customer
who watched something that year gets it by mail, and as a private Discord
message if they connected Discord (sent through Paywarr). Once a year each.
And with **Discord: announcements channel ID** set on the Wizarr server
(Paywarr 1.26), the bot posts the server's year there once: hours, plays,
viewers and the most watched movies and shows, without names.

Switch it off with **Year in review** on the extension.


## Admin pages

In Paymenter's admin, under **Paywarr Stats** (and in the **Paywarr** group
too), for staff who may view services:

- **Viewing per customer:** one row per customer (everyone with a Paywarr
  subscription): subscription and media servers, status, plays and watch time
  over the last 30 days, "Than before" (watch time against the 30 days before),
  last watched, the app they mostly use and on how many devices, paid in total,
  unpaid invoices, requests and tickets. Search, sort, export to CSV, and filter on status,
  media server, "Nothing in 14 days", "Half or less than before", "More than
  before" and "With unpaid invoices". Click a customer for their card: viewing,
  when they watch (per hour and weekday), most watched titles, apps and devices
  (with how often the server had to transcode), the latest plays, money
  (invoices), requests, Discord and tickets. "Manage in Paywarr" opens the
  same customer under Paywarr → Customers.
- **Viewing per server:** a tab per media server, last 30 days: viewers (and how
  many are customers), plays, watch time, the most streams at the same time,
  share transcoded, busiest hours and weekdays, who watches most, most watched
  titles, and apps.
- **Rankings and trends:** paying customers who watched an hour or more in the
  30 days before and half or less in the last 30 (they may be about to stop),
  top viewers, who watches more, plays and viewers per week over 8 weeks, and
  new and cancelled subscriptions per month over a year.

They read the last 60 days of plays from Tracearr (with app, device, platform
and whether it was transcoded) and from Silo (with Silo's app name), and
Paymenter's subscriptions, invoices and tickets. That is kept for 10 minutes;
**Refresh** fetches it again. A play counts once 2 minutes are watched.


## Stats overview for staff

Paymenter staff (anyone whose role may view services, `admin.services.view`)
also get **Stats overview** in the menu, at `/stats/admin`. Customers never see
it; for them the address doesn't exist.

**Your business**, from Paymenter (Paywarr's subscriptions):

- **Monthly revenue** of the active paid subscriptions (yearly plans / 12 and
  so on), per currency, and **per product**
- **Paying customers**, **trials running** and the share of finished trials
  that became paying, **unpaid invoices** and how many are overdue
- **Subscriptions per server**: active, trials, suspended, pending, cancelled
- **Last 30 days**: new subscriptions, new trials, cancelled, and who renews in
  the next 7 days
- **Customers**: paying but nothing watched in 30 days, Discord connected, who
  left the Discord, and requests used, with who has used their limit up
  (requests need Paywarr 1.23 or newer, and are kept for 10 minutes)

**Everyone on your servers**, from Silo and Tracearr together, customers or not:

- **Media servers**: users, customers, streaming now, plays and watch time per
  server, online or not; "History only" for a server whose history was
  imported into Tracearr without a live connection
- **Streaming now:** who, as the Paymenter customer with their subscription,
  status and paid-until date; what, on which device and server, played
  directly or converted, how far
- **Plays per day** over the last 30 days, coloured per server
- **Most watched** titles of the last 30 days, with posters, who watched them
  and on which server
- **Most active** users of the last 30 days, by watch time

A play counts once 2 minutes are watched (Tracearr's rule); shorter plays add
to the watch time only. Media server users that no subscription has are
marked **No subscription**, which makes left-over or unpaid accounts easy to
spot. The overview is kept for a minute. If Silo or Tracearr can't be reached,
the rest is still shown, with the reason on top. Silo's part needs a key with
full access or `admin:monitor:read`; the number of Silo users needs full
access, and shows "–" without it.

![The stats overview](../screenshots/overview.png)

## Reminding customers who watch nothing

Fill in **Remind customers who watched nothing for (days)** (e.g. 14) on Paywarr
Stats, and a paying customer who hasn't watched anything for that long gets a
friendly mail ("We miss you"), and a private Discord message if they connected
Discord. It is sent through Paywarr (1.25 or newer), so it looks like
Paywarr's other mails. Checked daily, at most once a month per customer.

Left alone: customers newer than that many days, trials, gift cards, top-ups,
customers without a user yet (Paywarr reminds those itself), and anyone when
Tracearr or Silo doesn't answer, so nobody gets a reminder by mistake.

[← Paywarr Stats](../README.md)
