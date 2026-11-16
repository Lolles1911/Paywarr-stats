<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use Throwable;

/**
 * The administrators' overview: what is streamed right now, the most
 * watched titles, the most active users and plays per day, per server, from
 * Silo and Tracearr together: everyone on the servers, customers or not. Each media server user is shown as the
 * Paymenter customer Paywarr noted it for, with their subscription.
 */
class AdminOverview
{
    public function __construct(
        private readonly ?TracearrClient $tracearr,
        private readonly ?SiloClient $silo,
        private readonly string $timezone = 'UTC',
    ) {}

    /**
     * @param  array<string, array>  $customers  by "silo|<account ID>" or "<type>|<lower-case username>"
     *                                           ("|<username>" for users noted without a type):
     *                                           name, email, products, status, paid_until
     */
    /** Tracearr counts a play once two minutes are watched, or it was marked watched. */
    public const COUNTED_MS = 120000;

    public function gather(array $customers, ?int $now = null): array
    {
        $now ??= time();
        $errors = [];
        $streams = [];
        $titles = [];
        $people = [];
        $servers = [];
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days", $now))] = [];
        }
        $attempt = function (string $source, callable $fn) use (&$errors) {
            try {
                $fn();
            } catch (Throwable $e) {
                $errors[] = $source . ': ' . $e->getMessage();
            }
        };
        $server = function (string $name, string $type) use (&$servers): void {
            $servers[$name] ??= ['name' => $name, 'type' => $type, 'status' => 'unknown', 'historical' => false, 'streams' => 0, 'users' => null, 'customers' => 0, 'plays' => 0, 'watch_ms' => 0];
        };

        if ($this->silo) {
            $server('Silo', 'silo');
            $attempt('Silo', function () use (&$streams, &$servers, $customers) {
                foreach ($this->silo->sessions() as $session) {
                    $streams[] = $this->siloStream($session, $customers);
                    $servers['Silo']['streams']++;
                }
                $servers['Silo']['status'] = 'up';
            });
            $attempt('Silo', function () use (&$titles, &$people, &$servers, $customers) {
                $top = $this->silo->topActivity(30);
                foreach ($top['titles'] ?? [] as $title) {
                    $this->addTitle($titles, (string) ($title['title'] ?? ''), (string) ($title['media_type'] ?? ''), (int) ($title['plays'] ?? 0),
                        1000 * (int) ($title['total_seconds'] ?? 0), ($title['media_item_id'] ?? '') !== '' ? 'silo:' . $title['media_item_id'] : '', 'Silo');
                }
                foreach ($top['profiles'] ?? [] as $profile) {
                    $who = $customers['silo|' . ($profile['user_id'] ?? '')] ?? null;
                    $plays = (int) ($profile['plays'] ?? 0);
                    $ms = 1000 * (int) ($profile['total_seconds'] ?? 0);
                    $this->addPerson($people, $who, (string) ($profile['username'] ?? ''), 'Silo', $plays, $ms);
                    $servers['Silo']['watch_ms'] += $ms;
                }
            });
            $attempt('Silo', function () use (&$days, &$servers) {
                foreach ($this->silo->playsPerHour(720) as $bucket) {
                    $day = ($ts = strtotime((string) ($bucket['hour'] ?? ''))) ? date('Y-m-d', $ts) : null;
                    if ($day !== null && isset($days[$day])) {
                        $count = (int) ($bucket['direct'] ?? 0) + (int) ($bucket['remux'] ?? 0) + (int) ($bucket['transcode'] ?? 0);
                        $days[$day]['Silo'] = ($days[$day]['Silo'] ?? 0) + $count;
                        $servers['Silo']['plays'] += $count;
                    }
                }
            });
            // Accounts need more than monitoring rights: without them the count is left out.
            try {
                $accounts = array_filter($this->silo->accounts(), fn ($a) => ($a['role'] ?? '') !== 'admin');
                $servers['Silo']['users'] = count($accounts);
                $servers['Silo']['customers'] = count(array_filter($accounts, fn ($a) => isset($customers['silo|' . ($a['id'] ?? '')])));
            } catch (Throwable $e) {
                // No count.
            }
        }

        if ($this->tracearr) {
            $names = [];
            $attempt('Tracearr', function () use (&$names, &$servers, $server) {
                foreach ($this->tracearr->servers() as $row) {
                    $name = (string) ($row['server_name'] ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $names[(string) ($row['server_id'] ?? '')] = $name;
                    $server($name, strtolower((string) ($row['server_type'] ?? '')));
                    $servers[$name]['status'] = (string) ($row['status'] ?? 'unknown');
                    $servers[$name]['historical'] = !empty($row['historical']);
                }
            });
            $attempt('Tracearr', function () use (&$streams, &$servers, $server, $customers) {
                foreach ($this->tracearr->streams() as $stream) {
                    $streams[] = $this->tracearrStream($stream, $customers);
                    $name = (string) ($stream['server_name'] ?? '');
                    if ($name !== '') {
                        $server($name, strtolower((string) ($stream['server_type'] ?? '')));
                        $servers[$name]['streams']++;
                    }
                }
            });
            $attempt('Tracearr', function () use (&$titles, &$people, &$days, &$servers, $server, $customers, $now) {
                foreach ($this->tracearr->history($now - 30 * 86400) as $play) {
                    $name = (string) ($play['server_name'] ?? '');
                    $type = strtolower((string) ($play['server_type'] ?? ''));
                    $username = (string) ($play['user']['username'] ?? '');
                    $ms = max(0, (int) ($play['duration_ms'] ?? 0));
                    $counted = $ms >= self::COUNTED_MS || !empty($play['watched']);
                    $who = $customers[$type . '|' . strtolower($username)] ?? $customers['|' . strtolower($username)] ?? null;
                    if ($name !== '') {
                        $server($name, $type);
                        $servers[$name]['watch_ms'] += $ms;
                    }
                    $this->addPerson($people, $who, $username, $name !== '' ? $name : ucfirst($type), $counted ? 1 : 0, $ms);
                    if (!$counted) {
                        continue;
                    }
                    $day = ($ts = strtotime((string) ($play['started_at'] ?? ''))) ? date('Y-m-d', $ts) : null;
                    if ($day !== null && isset($days[$day])) {
                        $days[$day][$name] = ($days[$day][$name] ?? 0) + 1;
                        if ($name !== '') {
                            $servers[$name]['plays']++;
                        }
                    }
                    $show = (string) ($play['show_title'] ?? '');
                    $title = $show !== '' ? $show : (string) ($play['media_title'] ?? '') . (($play['media_type'] ?? '') === 'movie' && !empty($play['year']) ? ' (' . $play['year'] . ')' : '');
                    $poster = (string) ($play['poster_url'] ?? '');
                    $this->addTitle($titles, $title, $show !== '' ? 'show' : (string) ($play['media_type'] ?? ''), 1, $ms,
                        str_starts_with($poster, '/api/v1/images/proxy?') ? $poster : '', $name, $who['name'] ?? $username);
                }
            });
            $attempt('Tracearr', function () use (&$servers, $names, $customers) {
                foreach ($this->tracearr->users() as $identity) {
                    foreach ($identity['accounts'] ?? [] as $account) {
                        $name = $names[(string) ($account['server_id'] ?? '')] ?? null;
                        if ($name === null || !empty($account['removed_at'])) {
                            continue;
                        }
                        $servers[$name]['users'] = ($servers[$name]['users'] ?? 0) + 1;
                        $key = strtolower((string) ($account['server_type'] ?? '')) . '|' . strtolower((string) ($account['username'] ?? ''));
                        if (isset($customers[$key]) || isset($customers['|' . strtolower((string) ($account['username'] ?? ''))])) {
                            $servers[$name]['customers']++;
                        }
                    }
                }
            });
        }

        usort($streams, fn ($a, $b) => strcmp($a['customer']['name'], $b['customer']['name']));
        uasort($titles, fn ($a, $b) => [$b['plays'], $b['watch_ms']] <=> [$a['plays'], $a['watch_ms']]);
        uasort($people, fn ($a, $b) => [$b['watch_ms'], $b['plays']] <=> [$a['watch_ms'], $a['plays']]);
        $titles = array_slice(array_values($titles), 0, 12);
        foreach ($titles as &$title) {
            $title['viewers'] = array_keys($title['viewers']);
            $title['servers'] = array_keys($title['servers']);
        }
        unset($title);
        $people = array_values(array_filter($people, fn ($person) => $person['plays'] > 0 || $person['watch_ms'] > 0));
        foreach ($people as &$person) {
            $person['servers'] = array_keys($person['servers']);
        }
        unset($person);
        // Live servers first, most plays first; servers with only imported history last.
        uasort($servers, fn ($a, $b) => [$a['historical'], -$a['plays'], $a['name']] <=> [$b['historical'], -$b['plays'], $b['name']]);

        // Posters by number, as on "My stats".
        $posters = [];
        $number = function (string $path) use (&$posters): int {
            if ($path === '') {
                return 0;
            }
            $found = array_search($path, $posters, true);
            if ($found === false) {
                $posters[] = $path;
                $found = count($posters) - 1;
            }

            return $found + 1;
        };
        foreach ($streams as &$stream) {
            $stream['poster'] = $number($stream['poster_path']);
            unset($stream['poster_path']);
        }
        unset($stream);
        foreach ($titles as &$title) {
            $title['poster'] = $number($title['poster_path']);
            unset($title['poster_path']);
        }
        unset($title);

        $emails = [];
        foreach ($customers as $customer) {
            if (($customer['status'] ?? '') === 'active') {
                $emails[$customer['email']] = true;
            }
        }

        return [
            'state' => 'ok',
            'streams' => $streams,
            'titles' => $titles,
            'people' => $people,
            'days' => $days,
            'servers' => array_values($servers),
            'paying' => count($emails),
            'posters' => $posters,
            'errors' => array_values(array_unique($errors)),
            'at' => $now,
        ];
    }

    private function siloStream(array $session, array $customers): array
    {
        $series = (string) ($session['series_name'] ?? '');
        $episode = (string) ($session['episode_name'] ?? '') ?: (string) ($session['media_title'] ?? '');
        $duration = (float) ($session['file_duration'] ?? 0);

        return [
            'customer' => $this->who($customers['silo|' . ($session['user_id'] ?? '')] ?? null, (string) ($session['username'] ?? '')),
            'title' => $series !== '' ? $series : (string) ($session['media_title'] ?? ''),
            'sub' => $series !== '' ? (isset($session['season_number'], $session['episode_number']) ? sprintf('S%02dE%02d · ', $session['season_number'], $session['episode_number']) : '') . $episode : '',
            'server' => 'Silo',
            'device' => trim((string) ($session['client_label'] ?? '')) ?: trim((string) ($session['client_name'] ?? '')) ?: trim((string) ($session['device_platform'] ?? '')),
            'transcode' => ($session['video_decision'] ?? '') === 'transcode' || ($session['effective_play_method'] ?? '') === 'transcode',
            'paused' => !empty($session['is_paused']),
            'progress' => $duration > 0 ? (int) round(100 * min(1, (float) ($session['position_seconds'] ?? 0) / $duration)) : null,
            'poster_path' => ($session['content_id'] ?? '') !== '' ? 'silo:' . $session['content_id'] : '',
        ];
    }

    private function tracearrStream(array $stream, array $customers): array
    {
        $type = strtolower((string) ($stream['server_type'] ?? ''));
        $name = strtolower((string) ($stream['username'] ?? ''));
        $show = (string) ($stream['show_title'] ?? '');
        $duration = (float) ($stream['duration_ms'] ?? 0);
        $poster = (string) ($stream['poster_url'] ?? '');

        return [
            'customer' => $this->who($customers[$type . '|' . $name] ?? $customers['|' . $name] ?? null, (string) ($stream['username'] ?? '')),
            'title' => $show !== '' ? $show : (string) ($stream['media_title'] ?? '') . (!empty($stream['year']) ? ' (' . $stream['year'] . ')' : ''),
            'sub' => $show !== '' ? (isset($stream['season_number'], $stream['episode_number']) ? sprintf('S%02dE%02d · ', $stream['season_number'], $stream['episode_number']) : '') . ($stream['media_title'] ?? '') : '',
            'server' => (string) ($stream['server_name'] ?? ''),
            'device' => trim((string) ($stream['device'] ?? '')) ?: trim((string) ($stream['player'] ?? '')) ?: trim((string) ($stream['platform'] ?? '')),
            'transcode' => !empty($stream['is_transcode']),
            'paused' => ($stream['state'] ?? '') === 'paused',
            'progress' => $duration > 0 ? (int) round(100 * min(1, (float) ($stream['progress_ms'] ?? 0) / $duration)) : null,
            'poster_path' => str_starts_with($poster, '/api/v1/images/proxy?') ? $poster : '',
        ];
    }

    /** The customer behind a media server user, or the bare username for one Paywarr doesn't know. */
    private function who(?array $customer, string $username): array
    {
        return $customer ?? ['name' => $username !== '' ? $username : '?', 'email' => '', 'products' => [], 'status' => '', 'paid_until' => null, 'unknown' => true];
    }

    private function addTitle(array &$titles, string $title, string $type, int $plays, int $ms, string $poster, string $server = '', string $viewer = ''): void
    {
        if ($title === '') {
            return;
        }
        $key = strtolower($title);
        $titles[$key] ??= ['title' => $title, 'type' => $type, 'plays' => 0, 'watch_ms' => 0, 'poster_path' => '', 'viewers' => [], 'servers' => []];
        $titles[$key]['plays'] += $plays;
        $titles[$key]['watch_ms'] += $ms;
        if ($titles[$key]['poster_path'] === '') {
            $titles[$key]['poster_path'] = $poster;
        }
        if ($server !== '') {
            $titles[$key]['servers'][$server] = true;
        }
        if ($viewer !== '') {
            $titles[$key]['viewers'][$viewer] = true;
        }
    }

    private function addPerson(array &$people, ?array $customer, string $username, string $server, int $plays, int $ms): void
    {
        $who = $this->who($customer, $username);
        $key = $who['email'] !== '' ? 'c|' . strtolower($who['email']) : 'u|' . strtolower($server . '|' . $username);
        $people[$key] ??= $who + ['plays' => 0, 'watch_ms' => 0, 'servers' => []];
        $people[$key]['plays'] += $plays;
        $people[$key]['watch_ms'] += $ms;
        $people[$key]['servers'][$server] = true;
    }
}
