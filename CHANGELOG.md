# Changelog

What changed in each version of Paywarr Stats. Each section is also the text
of its release on GitHub (`.github/release-notes.sh` puts it there).

## 1.12.0 (2026-11-27)

- **Silo through Tracearr:** when Tracearr (3.3 or newer) watches your Silo,
  Silo's plays come from Tracearr like the others' on every page (My stats,
  Wrapped, the admin pages, the "we miss you" reminder), and Silo isn't asked
  for them separately, so nothing is counted twice. Silo customers are found
  by their username as well as their Silo ID. Without Silo in Tracearr, Silo's
  own settings are used as before.
- **Who watches** (Tracearr 3.3 profiles on Silo): My stats shows which
  profiles watch, and Wrapped which profile watched the most, when an account
  has more than its main profile.

## 1.11.6 (2026-11-25)

- With Paywarr 1.37 or newer, Paywarr's Tracearr is read from Paywarr ->
  Settings (where it moved from the Wizarr server).

## 1.11.5 (2026-11-25)

- **"We miss you" with what is new:** with Paywarr 1.36 or newer, "Remind
  customers who watched nothing" sends Paywarr's "Watched nothing for a
  while" mail: a few new titles, and the text can be changed under Paywarr:
  Mail → Automatic mails. Paywarr → At risk lists the same customers.

## 1.11.4 (2026-11-24)

- **Unsubscribe:** with Paywarr 1.35 or newer, Wrapped is sent as "stats and
  surveys" and the "we miss you" mail as "reminders", so a customer can
  unsubscribe from each by the link at the bottom of the mail.

## 1.11.3 (2026-11-23)

- **"My stats" stays on one line** in the top bar.

## 1.11.2 (2026-11-22)

- **Admin menu:** the pages are in "Paywarr Stats" and, with Paywarr 1.31 or
  newer, in "Paywarr: Operations" (was "Paywarr"); the Paywarr Stats group
  comes after Paywarr's groups.

## 1.11.1 (2026-11-21)

- **"My stats" only for subscribers:** the menu item (side menu and top bar)
  shows only to customers with a Paywarr subscription (active or suspended).

## 1.11.0 (2026-11-21)

- **Export CSV** on Viewing per customer: every customer with plays, watch
  time, trend, last watched, app, devices, money, requests and tickets, for
  Excel.

## 1.10.0 (2026-11-21)

- **New: the year in review.** From December 1 to January 31, "Your year" on
  top of My stats: hours, plays, days, longest streak, busiest month,
  favourite day and genre, the top 5 and the first play of the year. In the
  first week of December every active customer who watched something gets
  their year by mail (and Discord), and Paywarr's announcements channel gets
  the server's year (no names of customers). Setting "Year in review".
- Tracearr's address and key are taken from Paywarr (1.26) when none are set
  here, so they only need filling in once.

## 1.9.1 (2026-11-20)

- **Fixed:** with 1.9.0, Paymenter's admin answered "Server Error" on Paymenter
  versions with an older Filament (such as Paymenter 1.5.9): the admin pages'
  second menu entry used a call those versions don't have.

## 1.9.0 (2026-11-20)

- **New: three admin pages**, in a "Paywarr Stats" group and in Paywarr's group
  too (with the Stats overview linked from the first):
  - **Viewing per customer:** one row per customer with subscription, status,
    plays and watch time over 30 days and against the 30 days before, last
    watched, the app they mostly use, paid in total, unpaid invoices, requests
    and tickets. Search, sort and filter (nothing in 14 days, watching half or
    less, more, unpaid). Click a customer for their card: when they watch
    (hours and weekdays), most watched, apps and devices with how often the
    server transcoded, latest plays, invoices, requests, Discord and tickets.
  - **Viewing per server:** a tab per media server with viewers, plays, watch
    time, the most streams at once, share transcoded, busiest hours and
    weekdays, who watches most, most watched titles and apps.
  - **Rankings and trends:** paying customers watching half or less than
    before (they may be about to stop), top viewers, who watches more, plays
    and viewers per week, and new and cancelled subscriptions per month.
- The numbers are the last 60 days of plays from Tracearr and Silo, kept for
  10 minutes ("Refresh" fetches them again).

## 1.8.0 (2026-11-20)

- **New: "We miss you".** Customers who watched nothing for a number of days
  (setting "Remind customers who watched nothing for (days)") get a friendly
  reminder by mail and Discord, at most once a month. Needs Paywarr 1.25.
- The README is short; the rest is in `docs/`.

## 1.7.1 (2026-11-20)

- Trial days given in Paywarr's admin (Paywarr 1.24) count as a trial in the
  business part of the Stats overview.

## 1.7.0 (2026-11-16)

- **New in the Stats overview: your business.** Monthly revenue (in total and
  per product), paying customers, trials running and how many became paying,
  unpaid invoices, subscriptions per server, new and cancelled subscriptions
  over 30 days, renewals in the next 7 days, paying customers who watch
  nothing, Discord, and requests used (requests need Paywarr 1.23).
- **New: media servers**, with users, customers, streams, plays and watch time
  each, online or not, and "History only" for a server whose history was
  imported into Tracearr.
- **Who watched what:** Most watched shows who watched each title and on
  which server; Plays per day is coloured per server; Most active lists
  everyone, not only customers.
- **Fixed:** Watch time showed 0 when the plays came from users without a
  subscription. It now counts everyone, as Plays does.
- Tracearr is asked once for the history of the last 30 days instead of top
  lists, activity and a call per customer. A play counts once 2 minutes are
  watched, as in Tracearr.

## 1.6.0 (2026-11-15)

- **My stats in the top bar** too, next to Home and Shop (in the menu on a
  phone). With Paywarr 1.21, Requests, Report a problem and Open Silo stand
  there as well.

## 1.5.3 (2026-11-15)

- **Fixed:** a poster could show the wrong title. Posters were addressed by
  their number in the list, and browsers keep them for a while; when the list
  changed, a kept address showed the previous title's poster. Every poster
  address now carries a fingerprint of its title, and an unknown one shows
  nothing rather than the wrong poster.

## 1.5.2 (2026-11-15)

- **Stats overview:** every card under "Streaming now" names its media server
  at the top, and the count up top says how many stream on each server.

## 1.5.1 (2026-11-15)

- **Stats overview:** Tracearr's most watched titles now have posters too.
- **Fixed:** on a phone, the section icons stood on a line of their own, and
  the dates under "Plays per day" ran into each other.

## 1.5.0 (2026-11-15)

- **New: Stats overview** (`/stats/admin`), for Paymenter staff only: who is
  streaming right now, plays per day, the most watched titles and the most
  active customers of the last 30 days, from Silo and Tracearr together.
  Every media server user is shown as the customer behind it, with
  subscription, status and paid-until date; users without a subscription are
  marked.

## 1.4.0 (2026-11-15)

- **My stats:** the top shows what was last watched, with its poster blurred
  behind it, and section headings have icons.
- A short first play reads "Under 1 min" instead of "0 min", with no daily
  average below a minute, and it counts in "When you watch".

## 1.3.1 (2026-11-14)

- A new customer's first play shows up within a minute: "Nothing watched yet"
  is kept for a minute instead of ten.
- Genres without plays are left out.
- New: `tools/stats-check.php`, which checks My stats for one customer step by
  step.

## 1.3.0 (2026-11-14)

- **New: Silo.** Customers on Silo (a Silo server in Paywarr 1.17) get their
  numbers from Silo itself, so no Tracearr is needed for them. A customer on
  both Silo and Jellyfin gets them added up.
- Tracearr and Silo settings are both optional.

## 1.2.0 (2026-11-14)

- **More on My stats:** when and where you watch (weekdays, time of day,
  devices, direct play), your shows with how far you are, movies and episodes
  watched, streaks, and "Recently watched" as a poster grid.

## 1.1.0 (2026-11-14)

- First release as its own project: each customer's own watch statistics from
  Tracearr, on a "My stats" page in the Paymenter client area, with posters.
