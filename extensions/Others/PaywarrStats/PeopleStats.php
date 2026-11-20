<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

/**
 * The numbers behind the admin pages Customers, Servers and Trends: per
 * customer, per media server and over time, from the last 60 days of plays
 * (Tracearr and Silo) and what Paymenter knows (subscriptions, invoices,
 * tickets, Discord, requests). Plain arrays in, plain arrays out; the pages
 * format them.
 *
 * A play: who (the Paymenter user ID, or null when the media server user
 * belongs to no customer), username, server, server_type, title, episode,
 * type (movie, episode or other), ms, started, stopped (null when unknown),
 * watched, app, device, platform, transcode (null when unknown), genres.
 *
 * A customer: id, name, email, discord (name or null), since, products,
 * status, trial, servers, paid_total, unpaid, unpaid_total, currency,
 * requests (used, limit, unlimited; or null), tickets, open_tickets, and for
 * the card invoices and ticket_list.
 */
class PeopleStats
{
    public const DAY = 86400;

    /** A play counts when it ran two minutes, or was watched to the end (as Tracearr's own numbers). */
    public const PLAY_MS = 120000;

    private array $plays;

    /** @var array<int|string, array> customers by Paymenter user ID */
    private array $customers;

    public function __construct(array $plays, array $customers, private readonly int $now)
    {
        $this->plays = array_values(array_filter($plays, fn ($play) => ($play['ms'] ?? 0) >= self::PLAY_MS || !empty($play['watched'])));
        usort($this->plays, fn ($a, $b) => $b['started'] <=> $a['started']);
        $this->customers = [];
        foreach ($customers as $customer) {
            $this->customers[$customer['id']] = $customer;
        }
    }

    /** The last 30 days, or the 30 days before when $previous. */
    private function within(array $play, bool $previous = false): bool
    {
        $age = $this->now - $play['started'];

        return $previous ? $age >= 30 * self::DAY && $age < 60 * self::DAY : $age >= 0 && $age < 30 * self::DAY;
    }

    private function playsOf($id): array
    {
        return array_values(array_filter($this->plays, fn ($play) => $play['who'] !== null && (string) $play['who'] === (string) $id));
    }

    /** Change from the 30 days before to the last 30, in percent; null when there is nothing to compare with. */
    public static function trend(int $before, int $now): ?int
    {
        if ($before <= 0) {
            return null;
        }

        return (int) round(($now - $before) / $before * 100);
    }

    /* ---------------- customers ---------------- */

    /** One row per customer. */
    public function customerRows(): array
    {
        $rows = [];
        foreach ($this->customers as $id => $customer) {
            $rows[$id] = $this->summary($customer, $this->playsOf($id));
        }

        return $rows;
    }

    private function summary(array $customer, array $plays): array
    {
        $now = ['plays' => 0, 'ms' => 0];
        $before = ['plays' => 0, 'ms' => 0];
        $apps = [];
        $devices = [];
        foreach ($plays as $play) {
            if ($this->within($play)) {
                $now['plays']++;
                $now['ms'] += $play['ms'];
                $apps[$play['app']] = ($apps[$play['app']] ?? 0) + 1;
                $devices[$play['device'] . '|' . $play['app']] = true;
            } elseif ($this->within($play, true)) {
                $before['plays']++;
                $before['ms'] += $play['ms'];
            }
        }
        arsort($apps);

        return $customer + [
            'plays_30' => $now['plays'],
            'ms_30' => $now['ms'],
            'plays_before' => $before['plays'],
            'ms_before' => $before['ms'],
            'trend' => self::trend($before['ms'], $now['ms']),
            'last' => $plays[0]['started'] ?? null,
            'app' => (string) (array_key_first(array_filter($apps, fn ($n, $app) => $app !== '', ARRAY_FILTER_USE_BOTH)) ?? ''),
            'devices' => count($devices),
        ];
    }

    /** Everything about one customer, or null when there is no such customer. */
    public function customer($id): ?array
    {
        $customer = $this->customers[$id] ?? null;
        if ($customer === null) {
            return null;
        }
        $plays = $this->playsOf($id);
        $card = $this->summary($customer, $plays);
        $card['movies'] = count(array_filter($plays, fn ($play) => $play['type'] === 'movie'));
        $card['episodes'] = count(array_filter($plays, fn ($play) => $play['type'] === 'episode'));
        $card['plays_60'] = count($plays);
        $card['ms_60'] = array_sum(array_column($plays, 'ms'));
        $card['hours'] = $this->hours($plays);
        $card['weekdays'] = $this->weekdays($plays);
        $card['titles'] = $this->titles($plays, 8);
        $card['genres'] = $this->genres($plays, 6);
        $card['apps'] = $this->apps($plays);
        $card['servers_watched'] = array_values(array_unique(array_column($plays, 'server')));
        $card['recent'] = array_map(fn ($play) => [
            'title' => $play['title'], 'episode' => $play['episode'], 'server' => $play['server'], 'app' => $play['app'],
            'device' => $play['device'], 'started' => $play['started'], 'ms' => $play['ms'], 'watched' => (bool) $play['watched'],
        ], array_slice($plays, 0, 12));

        return $card;
    }

    /** Plays started per hour of the day (0 to 23), in Paymenter's time zone. */
    private function hours(array $plays): array
    {
        $hours = array_fill(0, 24, 0);
        foreach ($plays as $play) {
            $hours[(int) date('G', $play['started'])]++;
        }

        return $hours;
    }

    /** Plays per weekday, Monday first. */
    private function weekdays(array $plays): array
    {
        $days = array_fill(0, 7, 0);
        foreach ($plays as $play) {
            $days[(int) date('N', $play['started']) - 1]++;
        }

        return $days;
    }

    /** The most watched titles (a show counts its episodes together). */
    private function titles(array $plays, int $count): array
    {
        $titles = [];
        foreach ($plays as $play) {
            $key = $play['type'] . '|' . strtolower($play['title']);
            $titles[$key] ??= ['title' => $play['title'], 'type' => $play['type'], 'plays' => 0, 'ms' => 0, 'viewers' => []];
            $titles[$key]['plays']++;
            $titles[$key]['ms'] += $play['ms'];
            $titles[$key]['viewers'][$play['who'] ?? $play['username']] = true;
        }
        foreach ($titles as &$title) {
            $title['viewers'] = count($title['viewers']);
        }
        unset($title);
        uasort($titles, fn ($a, $b) => [$b['plays'], $b['ms']] <=> [$a['plays'], $a['ms']]);

        return array_values(array_slice($titles, 0, $count));
    }

    private function genres(array $plays, int $count): array
    {
        $genres = [];
        foreach ($plays as $play) {
            foreach ($play['genres'] ?? [] as $genre) {
                if (is_string($genre) && $genre !== '') {
                    $genres[$genre] = ($genres[$genre] ?? 0) + 1;
                }
            }
        }
        arsort($genres);

        return array_slice($genres, 0, $count, true);
    }

    /** Apps and devices: app, device, platform, plays, watch time, last used, share transcoded. */
    private function apps(array $plays): array
    {
        $apps = [];
        foreach ($plays as $play) {
            $key = strtolower($play['app'] . '|' . $play['device'] . '|' . $play['platform']);
            $apps[$key] ??= ['app' => $play['app'], 'device' => $play['device'], 'platform' => $play['platform'], 'plays' => 0, 'ms' => 0, 'last' => $play['started'], 'transcoded' => 0, 'known' => 0];
            $apps[$key]['plays']++;
            $apps[$key]['ms'] += $play['ms'];
            $apps[$key]['last'] = max($apps[$key]['last'], $play['started']);
            if ($play['transcode'] !== null) {
                $apps[$key]['known']++;
                $apps[$key]['transcoded'] += $play['transcode'] ? 1 : 0;
            }
        }
        foreach ($apps as &$app) {
            $app['transcode_share'] = $app['known'] > 0 ? (int) round($app['transcoded'] / $app['known'] * 100) : null;
            unset($app['known'], $app['transcoded']);
        }
        unset($app);
        uasort($apps, fn ($a, $b) => $b['plays'] <=> $a['plays']);

        return array_values($apps);
    }

    /* ---------------- servers ---------------- */

    /** Per media server, over the last 30 days. */
    public function servers(): array
    {
        $byServer = [];
        foreach ($this->plays as $play) {
            if ($this->within($play)) {
                $byServer[$play['server']][] = $play;
            }
        }
        $servers = [];
        foreach ($byServer as $name => $plays) {
            $viewers = [];
            foreach ($plays as $play) {
                $key = $play['who'] !== null ? 'c' . $play['who'] : 'u' . strtolower($play['username']);
                $viewers[$key] ??= ['who' => $play['who'], 'name' => $play['who'] !== null ? ($this->customers[$play['who']]['name'] ?? $play['username']) : $play['username'], 'customer' => $play['who'] !== null, 'plays' => 0, 'ms' => 0];
                $viewers[$key]['plays']++;
                $viewers[$key]['ms'] += $play['ms'];
            }
            uasort($viewers, fn ($a, $b) => $b['ms'] <=> $a['ms']);
            $apps = [];
            foreach ($plays as $play) {
                $label = $play['app'] !== '' ? $play['app'] : 'Unknown';
                $apps[$label] = ($apps[$label] ?? 0) + 1;
            }
            arsort($apps);
            $known = array_filter($plays, fn ($play) => $play['transcode'] !== null);
            $servers[$name] = [
                'name' => $name,
                'type' => $plays[0]['server_type'],
                'plays' => count($plays),
                'ms' => array_sum(array_column($plays, 'ms')),
                'viewers' => count($viewers),
                'customers' => count(array_filter($viewers, fn ($viewer) => $viewer['customer'])),
                'top_viewers' => array_values(array_slice($viewers, 0, 8)),
                'hours' => $this->hours($plays),
                'weekdays' => $this->weekdays($plays),
                'apps' => array_slice($apps, 0, 8, true),
                'titles' => $this->titles($plays, 8),
                'transcode_share' => $known ? (int) round(count(array_filter($known, fn ($play) => $play['transcode'])) / count($known) * 100) : null,
                'peak' => $this->peak($plays),
            ];
        }
        uasort($servers, fn ($a, $b) => $b['plays'] <=> $a['plays']);

        return $servers;
    }

    /** The most streams at the same time, and when: ['streams' => n, 'at' => timestamp]. */
    private function peak(array $plays): array
    {
        $events = [];
        foreach ($plays as $play) {
            $stop = $play['stopped'] ?? ($play['started'] + intdiv($play['ms'], 1000));
            $events[] = [$play['started'], 1];
            $events[] = [max($stop, $play['started'] + 1), -1];
        }
        // A stop before a start at the same second: back-to-back plays are not "at the same time".
        usort($events, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $now = 0;
        $peak = ['streams' => 0, 'at' => null];
        foreach ($events as [$at, $change]) {
            $now += $change;
            if ($now > $peak['streams']) {
                $peak = ['streams' => $now, 'at' => $at];
            }
        }

        return $peak;
    }

    /* ---------------- trends ---------------- */

    /**
     * Top viewers, customers who watch much less than before, plays per
     * week, and new and cancelled subscriptions per month. $subscriptions:
     * rows with created_at, cancelled_at (null unless cancelled) and status.
     */
    public function trends(array $subscriptions): array
    {
        $rows = $this->customerRows();
        $top = array_filter($rows, fn ($row) => $row['ms_30'] > 0);
        uasort($top, fn ($a, $b) => $b['ms_30'] <=> $a['ms_30']);

        // Paying customers who watched an hour or more before and less than half of it now.
        $less = array_filter($rows, fn ($row) => in_array($row['status'], ['active', 'suspended'], true) && empty($row['trial'])
            && $row['ms_before'] >= 3600000 && $row['ms_30'] <= $row['ms_before'] / 2);
        uasort($less, fn ($a, $b) => ($a['ms_30'] - $a['ms_before']) <=> ($b['ms_30'] - $b['ms_before']));
        $more = array_filter($rows, fn ($row) => $row['ms_30'] >= 3600000 && $row['ms_30'] >= max(1, $row['ms_before']) * 2);
        uasort($more, fn ($a, $b) => ($b['ms_30'] - $b['ms_before']) <=> ($a['ms_30'] - $a['ms_before']));

        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $weeks[$this->now - ($i + 1) * 7 * self::DAY] = ['plays' => 0, 'ms' => 0, 'viewers' => []];
        }
        foreach ($this->plays as $play) {
            $age = $this->now - $play['started'];
            if ($age < 0 || $age >= 56 * self::DAY) {
                continue;
            }
            $start = $this->now - (intdiv($age, 7 * self::DAY) + 1) * 7 * self::DAY;
            $weeks[$start]['plays']++;
            $weeks[$start]['ms'] += $play['ms'];
            $weeks[$start]['viewers'][$play['who'] ?? $play['username']] = true;
        }
        foreach ($weeks as &$week) {
            $week['viewers'] = count($week['viewers']);
        }
        unset($week);

        $months = [];
        for ($i = 11; $i >= 0; $i--) {
            $months[date('Y-m', strtotime(date('Y-m-01', $this->now) . " -$i months"))] = ['new' => 0, 'cancelled' => 0];
        }
        foreach ($subscriptions as $subscription) {
            $new = date('Y-m', (int) ($subscription['created_at'] ?? 0));
            if (isset($months[$new])) {
                $months[$new]['new']++;
            }
            if (($subscription['status'] ?? '') === 'cancelled' && !empty($subscription['cancelled_at'])) {
                $gone = date('Y-m', (int) $subscription['cancelled_at']);
                if (isset($months[$gone])) {
                    $months[$gone]['cancelled']++;
                }
            }
        }

        $paying = array_filter($rows, fn ($row) => $row['status'] === 'active' && empty($row['trial']));

        return [
            'top' => array_values(array_slice($top, 0, 10, true)),
            'less' => array_values(array_slice($less, 0, 15, true)),
            'more' => array_values(array_slice($more, 0, 10, true)),
            'weeks' => $weeks,
            'months' => $months,
            'paying' => count($paying),
            'watching' => count(array_filter($paying, fn ($row) => $row['plays_30'] > 0)),
            'idle_14' => count(array_filter($paying, fn ($row) => $row['last'] === null || $this->now - $row['last'] >= 14 * self::DAY)),
        ];
    }
}
