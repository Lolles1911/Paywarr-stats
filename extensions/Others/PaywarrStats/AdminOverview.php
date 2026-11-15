<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use Throwable;

/**
 * The administrators' overview: what all customers stream right now, the
 * most watched titles, the most active customers and plays per day, from
 * Silo and Tracearr together. Each media server user is shown as the
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
    public function gather(array $customers, ?int $now = null): array
    {
        $now ??= time();
        $errors = [];
        $streams = [];
        $titles = [];
        $people = [];
        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $days[date('Y-m-d', strtotime("-$i days", $now))] = 0;
        }
        $attempt = function (string $source, callable $fn) use (&$errors) {
            try {
                $fn();
            } catch (Throwable $e) {
                $errors[] = $source . ': ' . $e->getMessage();
            }
        };

        if ($this->silo) {
            $attempt('Silo', function () use (&$streams, $customers) {
                foreach ($this->silo->sessions() as $session) {
                    $streams[] = $this->siloStream($session, $customers);
                }
            });
            $attempt('Silo', function () use (&$titles, &$people, $customers) {
                $top = $this->silo->topActivity(30);
                foreach ($top['titles'] ?? [] as $title) {
                    $this->addTitle($titles, (string) ($title['title'] ?? ''), (string) ($title['media_type'] ?? ''), (int) ($title['plays'] ?? 0),
                        1000 * (int) ($title['total_seconds'] ?? 0), ($title['media_item_id'] ?? '') !== '' ? 'silo:' . $title['media_item_id'] : '');
                }
                foreach ($top['profiles'] ?? [] as $profile) {
                    $who = $customers['silo|' . ($profile['user_id'] ?? '')] ?? null;
                    $this->addPerson($people, $who, (string) ($profile['username'] ?? ''), 'Silo', (int) ($profile['plays'] ?? 0), 1000 * (int) ($profile['total_seconds'] ?? 0));
                }
            });
            $attempt('Silo', function () use (&$days) {
                foreach ($this->silo->playsPerHour(720) as $bucket) {
                    $day = ($ts = strtotime((string) ($bucket['hour'] ?? ''))) ? date('Y-m-d', $ts) : null;
                    if ($day !== null && isset($days[$day])) {
                        $days[$day] += (int) ($bucket['direct'] ?? 0) + (int) ($bucket['remux'] ?? 0) + (int) ($bucket['transcode'] ?? 0);
                    }
                }
            });
        }

        if ($this->tracearr) {
            $attempt('Tracearr', function () use (&$streams, $customers) {
                foreach ($this->tracearr->streams() as $stream) {
                    $streams[] = $this->tracearrStream($stream, $customers);
                }
            });
            $attempt('Tracearr', function () use (&$titles) {
                foreach (['movie', 'show'] as $type) {
                    foreach ($this->tracearr->topMedia($type, 30) as $title) {
                        $name = (string) ($title['title'] ?? '') . ($type === 'movie' && !empty($title['year']) ? ' (' . $title['year'] . ')' : '');
                        $this->addTitle($titles, $name, $type, (int) ($title['plays'] ?? 0), (int) ($title['watch_time_ms'] ?? 0), '');
                    }
                }
            });
            $attempt('Tracearr', function () use (&$days) {
                foreach ($this->tracearr->playsPerDay($this->timezone) as $row) {
                    $day = substr((string) ($row['date'] ?? ''), 0, 10);
                    if (isset($days[$day])) {
                        $days[$day] += (int) ($row['count'] ?? 0);
                    }
                }
            });
            $attempt('Tracearr', function () use (&$people, $customers) {
                // Only users Paywarr noted for a customer: one call each.
                foreach ($this->tracearr->users() as $identity) {
                    foreach ($identity['accounts'] ?? [] as $account) {
                        if (!empty($account['removed_at'])) {
                            continue;
                        }
                        $type = strtolower((string) ($account['server_type'] ?? ''));
                        $name = strtolower((string) ($account['username'] ?? ''));
                        $who = $customers[$type . '|' . $name] ?? $customers['|' . $name] ?? null;
                        if ($who !== null) {
                            $window = $this->tracearr->userStats((string) $identity['id'])['windows']['last_30'] ?? [];
                            $this->addPerson($people, $who, (string) $account['username'], ucfirst($type), (int) ($window['plays'] ?? 0), (int) ($window['watch_time_ms'] ?? 0));

                            continue 2;
                        }
                    }
                }
            });
        }

        usort($streams, fn ($a, $b) => strcmp($a['customer']['name'], $b['customer']['name']));
        uasort($titles, fn ($a, $b) => [$b['plays'], $b['watch_ms']] <=> [$a['plays'], $a['watch_ms']]);
        uasort($people, fn ($a, $b) => [$b['watch_ms'], $b['plays']] <=> [$a['watch_ms'], $a['plays']]);
        $titles = array_slice(array_values($titles), 0, 12);
        $people = array_values(array_filter($people, fn ($person) => $person['plays'] > 0));

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

    private function addTitle(array &$titles, string $title, string $type, int $plays, int $ms, string $poster): void
    {
        if ($title === '') {
            return;
        }
        $key = strtolower($title);
        $titles[$key] ??= ['title' => $title, 'type' => $type, 'plays' => 0, 'watch_ms' => 0, 'poster_path' => ''];
        $titles[$key]['plays'] += $plays;
        $titles[$key]['watch_ms'] += $ms;
        if ($titles[$key]['poster_path'] === '') {
            $titles[$key]['poster_path'] = $poster;
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
