# Changelog

What changed in each version of Paywarr Stats. Each section is also the text
of its release on GitHub (`.github/release-notes.sh` puts it there).

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
