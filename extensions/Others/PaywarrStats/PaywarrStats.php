<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Each customer's own watch statistics, from Tracearr, on a "My stats" page
 * in the client area. The customer's media server users come from Paywarr
 * (the Wizarr server extension); Tracearr is only asked about those, so a
 * customer never sees anyone else's numbers.
 */
#[ExtensionMeta(
    name: 'Paywarr Stats',
    description: 'Shows each customer their own watch statistics from Tracearr, on a page in the client area.',
    version: '1.2.0',
    author: 'Paywarr',
    url: 'https://github.com/Lolles1911/Paywarr-stats',
)]
class PaywarrStats extends Extension
{
    /** Numbers are kept this long per customer, so page views do not hit Tracearr. */
    private const CACHE_SECONDS = 600;

    private ?TracearrClient $client = null;

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'tracearr_url',
                'label' => 'Tracearr URL (internal)',
                'type' => 'text',
                'required' => true,
                'placeholder' => 'http://192.168.1.10:3000',
                'description' => 'How Paymenter reaches Tracearr. Never shown to customers.',
            ],
            [
                'name' => 'tracearr_api_key',
                'label' => 'Tracearr API key',
                'type' => 'password',
                'required' => true,
                'encrypted' => true,
                'description' => 'Tracearr -> Settings -> Data & API -> API -> Generate key (starts with "trr_pub_"). Needs the Paywarr (Wizarr) server extension for the customers\' media server users.',
            ],
        ];
    }

    public function boot()
    {
        require __DIR__ . '/routes.php';

        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('My stats'),
            'route' => 'extensions.paywarr-stats',
            'icon' => 'ri-bar-chart-2',
            'priority' => 25,
            'condition' => Auth::check(),
        ]);
    }

    /** GET /stats */
    public function page()
    {
        $user = Auth::user();
        try {
            $stats = $this->statsForUser($user);
        } catch (Throwable $e) {
            Log::warning('Paywarr Stats: could not load the statistics of user ' . $user->id . ': ' . $e->getMessage());
            $stats = null;
        }

        return view('layouts.app', [
            'slot' => new HtmlString($this->pageHtml($stats, rtrim(route('extensions.paywarr-stats'), '/') . '/poster/', (string) ($user->first_name ?? ''))),
            'title' => self::t('My stats'),
        ]);
    }

    /** GET /stats/poster/{number}: a poster of the customer's own "Recently watched". */
    public function poster($number)
    {
        try {
            $image = $this->posterFor($this->statsForUser(Auth::user()), (int) $number);
        } catch (Throwable $e) {
            $image = null;
        }
        if ($image === null) {
            abort(404);
        }

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Poster number $number (from 1) of these statistics, fetched from
     * Tracearr; null when there is none.
     *
     * @return array{body: string, type: string}|null
     */
    public function posterFor(array $stats, int $number): ?array
    {
        $path = $stats['posters'][$number - 1] ?? null;

        return $number >= 1 && is_string($path) && $path !== '' ? $this->client()->poster($path) : null;
    }

    /** The customer's statistics, kept for CACHE_SECONDS. */
    private function statsForUser($user): array
    {
        // The version: entries stored by an older version have another shape.
        return Cache::remember('paywarr-stats:v3:' . $user->id, self::CACHE_SECONDS, function () use ($user) {
            $services = Service::where('user_id', $user->id)
                ->whereIn('status', ['active', 'suspended'])
                ->with(['properties', 'product.server'])
                ->get();
            $lists = [];
            foreach ($services as $service) {
                if ($service->product?->server?->extension === 'Wizarr') {
                    $lists[] = $service->properties->pluck('value', 'key')->all();
                }
            }

            return $this->statsFor($this->mediaAccounts($lists));
        });
    }

    /**
     * The customer's media server users, as Paywarr records them on each
     * subscription: "wizarr_users" (before 1.11) and "wizarr_users:<server ID>",
     * each "id:username:type,...".
     *
     * @param  array<int, array<string, mixed>>  $propertyLists  one per subscription
     * @return array<int, array{type: string, username: string}>
     */
    public function mediaAccounts(array $propertyLists): array
    {
        $accounts = [];
        foreach ($propertyLists as $properties) {
            foreach ($properties as $key => $value) {
                if ($key !== 'wizarr_users' && !str_starts_with((string) $key, 'wizarr_users:')) {
                    continue;
                }
                foreach (array_filter(explode(',', (string) $value)) as $entry) {
                    [, $username, $type] = array_pad(explode(':', $entry, 3), 3, '');
                    $username = rawurldecode($username);
                    if ($username !== '') {
                        $accounts[strtolower(rawurldecode($type)) . '|' . strtolower($username)] = ['type' => strtolower(rawurldecode($type)), 'username' => $username];
                    }
                }
            }
        }

        return array_values($accounts);
    }

    /**
     * The Tracearr identities behind these accounts: same server type and
     * username (any type for records without one), accounts not removed.
     *
     * @return string[]
     */
    public function matchIdentities(array $accounts, array $identities): array
    {
        $ids = [];
        foreach ($identities as $identity) {
            foreach ($identity['accounts'] ?? [] as $account) {
                if (!empty($account['removed_at'])) {
                    continue;
                }
                foreach ($accounts as $mine) {
                    $sameType = $mine['type'] === '' || $mine['type'] === strtolower((string) ($account['server_type'] ?? ''));
                    if ($sameType && strcasecmp((string) ($account['username'] ?? ''), $mine['username']) === 0) {
                        $ids[(string) $identity['id']] = true;
                    }
                }
            }
        }

        return array_keys($ids);
    }

    /**
     * What the page shows. 'state': 'no_access' (no media server user yet),
     * 'no_data' (not in Tracearr, so nothing watched yet) or 'ok'.
     */
    public function statsFor(array $accounts): array
    {
        if (!$accounts) {
            return ['state' => 'no_access'];
        }
        $ids = $this->matchIdentities($accounts, $this->client()->users());
        if (!$ids) {
            return ['state' => 'no_data'];
        }

        $windows = ['last_7' => ['plays' => 0, 'watch_time_ms' => 0], 'last_30' => ['plays' => 0, 'watch_time_ms' => 0], 'all_time' => ['plays' => 0, 'watch_time_ms' => 0]];
        $genres = [];
        $plays = [];
        $shows = [];
        $movies = 0;
        foreach ($ids as $id) {
            $stats = $this->client()->userStats($id);
            foreach ($windows as $key => $sum) {
                $windows[$key]['plays'] += (int) ($stats['windows'][$key]['plays'] ?? 0);
                $windows[$key]['watch_time_ms'] += (int) ($stats['windows'][$key]['watch_time_ms'] ?? 0);
            }
            foreach ($stats['top_genres'] ?? [] as $genre) {
                $genres[(string) $genre['genre']] = ($genres[(string) $genre['genre']] ?? 0) + (int) $genre['plays'];
            }
            foreach ($this->client()->userHistory($id) as $play) {
                $plays[] = $this->play($play);
            }
            // The same show on two servers counts once, with the furthest progress.
            foreach ($this->client()->watchedMedia($id, 'show') as $show) {
                $title = (string) ($show['title'] ?? '');
                if ($title === '') {
                    continue;
                }
                $key = strtolower($title);
                $known = $shows[$key] ?? ['title' => $title, 'plays' => 0, 'watched' => 0, 'episodes' => null, 'last' => ''];
                $known['plays'] += (int) ($show['plays'] ?? 0);
                $known['watched'] = max($known['watched'], (int) ($show['episodes_watched'] ?? 0));
                $known['episodes'] = isset($show['episode_count']) ? max((int) $known['episodes'], (int) $show['episode_count']) : $known['episodes'];
                $known['last'] = max($known['last'], (string) ($show['last_watched_day'] ?? ''));
                $shows[$key] = $known;
            }
            foreach ($this->client()->watchedMedia($id, 'movie') as $movie) {
                if (($movie['watched_state'] ?? '') === 'watched') {
                    $movies++;
                }
            }
        }
        usort($plays, fn ($a, $b) => strcmp($b['started_at'], $a['started_at']));
        arsort($genres);
        $episodes = array_sum(array_column($shows, 'watched'));
        usort($shows, fn ($a, $b) => strcmp($b['last'], $a['last']));
        $shows = array_slice($shows, 0, 6);

        // Posters are served by number from this list, never by address.
        $posters = [];
        $posterNumber = function (string $path) use (&$posters): int {
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
        // Each show or movie once, at its latest play.
        $recent = [];
        foreach ($plays as $play) {
            $key = strtolower($play['show'] !== '' ? 'show|' . $play['show'] : 'movie|' . $play['title'] . '|' . $play['year']);
            if (!isset($recent[$key]) && count($recent) < 12) {
                $recent[$key] = $play;
            }
        }
        $recent = array_values($recent);
        foreach ($recent as &$play) {
            $play['poster'] = $posterNumber($play['poster_path']);
            unset($play['poster_path']);
        }
        unset($play);
        foreach ($shows as &$show) {
            $path = '';
            foreach ($plays as $play) {
                if ($path === '' && strcasecmp($play['show'], $show['title']) === 0) {
                    $path = $play['poster_path'];
                }
            }
            $show['poster'] = $posterNumber($path);
        }
        unset($show);

        return [
            'state' => 'ok',
            'windows' => $windows,
            'genres' => array_slice($genres, 0, 6, true),
            'movies_watched' => $movies,
            'episodes_watched' => $episodes,
            'shows' => $shows,
            'recent' => $recent,
            'insights' => $this->insights($plays),
            'posters' => $posters,
        ];
    }

    /** One play from Tracearr's history, as the page needs it. */
    private function play(array $play): array
    {
        return [
            'title' => (string) ($play['media_title'] ?? ''),
            'show' => (string) ($play['show_title'] ?? ''),
            'season' => $play['season_number'] ?? null,
            'episode' => $play['episode_number'] ?? null,
            'year' => $play['year'] ?? null,
            'type' => (string) ($play['media_type'] ?? ''),
            'server' => (string) ($play['server_name'] ?? ''),
            'started_at' => (string) ($play['started_at'] ?? ''),
            'percent' => isset($play['percent_complete']) ? (int) round((float) $play['percent_complete']) : null,
            'watched_ms' => (int) ($play['duration_ms'] ?? 0),
            'device' => trim((string) ($play['device'] ?? '')) ?: trim((string) ($play['player'] ?? '')) ?: trim((string) ($play['platform'] ?? '')),
            'transcode' => isset($play['is_transcode']) ? (bool) $play['is_transcode'] : null,
            // Tracearr's own proxy path; served to the customer by number.
            'poster_path' => str_starts_with((string) ($play['poster_url'] ?? ''), '/api/v1/images/proxy?') ? (string) $play['poster_url'] : '',
        ];
    }

    /**
     * Patterns in the latest plays: watch time per weekday and time of day
     * (in the shop's time zone), devices, direct play share and streaks.
     */
    public function insights(array $plays, ?int $today = null): array
    {
        $weekdays = array_fill(1, 7, 0);
        $dayparts = ['night' => 0, 'morning' => 0, 'afternoon' => 0, 'evening' => 0];
        $devices = [];
        $days = [];
        $direct = 0;
        $known = 0;
        foreach ($plays as $play) {
            $ts = strtotime($play['started_at']);
            if ($ts === false) {
                continue;
            }
            $minutes = intdiv(max(0, $play['watched_ms']), 60000);
            $weekdays[(int) date('N', $ts)] += $minutes;
            $hour = (int) date('G', $ts);
            $dayparts[$hour < 6 ? 'night' : ($hour < 12 ? 'morning' : ($hour < 18 ? 'afternoon' : 'evening'))] += $minutes;
            if ($play['device'] !== '') {
                $devices[$play['device']] = ($devices[$play['device']] ?? 0) + 1;
            }
            if ($play['transcode'] !== null) {
                $known++;
                $direct += $play['transcode'] ? 0 : 1;
            }
            $days[date('Y-m-d', $ts)] = true;
        }
        arsort($devices);

        // Streaks over calendar days with at least one play.
        $dates = array_keys($days);
        sort($dates);
        $longest = 0;
        $run = 0;
        $previous = null;
        foreach ($dates as $date) {
            $run = $previous !== null && strtotime($previous . ' +1 day') === strtotime($date) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }
        $today ??= time();
        $current = 0;
        $day = isset($days[date('Y-m-d', $today)]) ? $today : strtotime('-1 day', $today);
        while (isset($days[date('Y-m-d', $day)])) {
            $current++;
            $day = strtotime('-1 day', $day);
        }

        return [
            'weekdays' => $weekdays,
            'dayparts' => $dayparts,
            'devices' => array_slice($devices, 0, 4, true),
            'direct_share' => $known > 0 ? (int) round(100 * $direct / $known) : null,
            'longest_streak' => $longest,
            'current_streak' => $current,
            'sample' => count($plays),
        ];
    }

    /** The page body. Styled with the theme's colour variables, so it fits any theme, light or dark. */
    public function pageHtml(?array $stats, string $posterBase = '/stats/poster/', string $name = ''): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '<div id="paywarr-stats" class="container mt-14"><style>' . $this->css() . '</style>'
            . '<header class="ps-head"><h1>' . $e(self::t('My stats')) . '</h1>'
            . ($name !== '' ? '<p class="ps-muted">' . $e(self::t('Hi :name, here is what you have been watching.', ['name' => $name])) . '</p>' : '')
            . '</header>';

        if ($stats === null || ($stats['state'] ?? '') !== 'ok') {
            $message = match ($stats['state'] ?? null) {
                'no_access' => self::t('Your statistics appear here once you have created your user and started watching.'),
                'no_data' => self::t('Nothing watched yet. Your statistics appear here once you start watching.'),
                default => self::t('Your statistics can\'t be loaded right now. Please try again later.'),
            };

            return $html . '<p class="ps-card ps-note">' . $e($message) . '</p></div>';
        }

        $poster = function (int $number, string $title, string $class, string $extra = '') use ($e, $posterBase): string {
            $letter = $e(mb_strtoupper(mb_substr(trim($title) ?: '?', 0, 1)));

            return '<span class="' . $class . '"><span class="ps-letter">' . $letter . '</span>'
                . ($number > 0 ? '<img loading="lazy" alt="" src="' . $e($posterBase . $number) . '" onerror="this.remove()">' : '') . $extra . '</span>';
        };
        $bar = fn (float $share) => '<span class="ps-bar"><span style="width:' . max(2, min(100, (int) round($share * 100))) . '%"></span></span>';

        // Hero: all time
        $all = $stats['windows']['all_time'];
        $insights = $stats['insights'];
        $chips = [
            $all['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $all['plays']]),
            $stats['movies_watched'] === 1 ? self::t('1 movie') : self::t(':count movies', ['count' => $stats['movies_watched']]),
            $stats['episodes_watched'] === 1 ? self::t('1 episode') : self::t(':count episodes', ['count' => $stats['episodes_watched']]),
        ];
        if ($insights['longest_streak'] > 1) {
            $chips[] = self::t('Longest streak: :count days', ['count' => $insights['longest_streak']]);
        }
        $html .= '<section class="ps-hero"><div class="ps-label">' . $e(self::t('All time')) . '</div>'
            . '<div class="ps-big">' . $e($this->duration($all['watch_time_ms'])) . '</div>'
            . ($all['watch_time_ms'] >= 86400000 ? '<div class="ps-muted">' . $e(self::t('That is :count days of watching', ['count' => number_format($all['watch_time_ms'] / 86400000, 1, self::t('.'), '')])) . '</div>' : '')
            . '<div class="ps-chips">' . implode('', array_map(fn ($c) => '<span class="ps-chip">' . $e($c) . '</span>', $chips)) . '</div></section>';

        // Periods
        $html .= '<div class="ps-periods">';
        foreach (['last_7' => [self::t('Last 7 days'), 7], 'last_30' => [self::t('Last 30 days'), 30]] as $key => [$label, $daysInPeriod]) {
            $window = $stats['windows'][$key];
            $html .= '<div class="ps-card"><div class="ps-label">' . $e($label) . '</div>'
                . '<div class="ps-value">' . $e($this->duration($window['watch_time_ms'])) . '</div>'
                . '<div class="ps-muted">' . $e($window['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $window['plays']]))
                . ' · ' . $e(self::t(':time a day', ['time' => $this->duration(intdiv($window['watch_time_ms'], $daysInPeriod))])) . '</div></div>';
        }
        if ($insights['current_streak'] > 0) {
            $html .= '<div class="ps-card"><div class="ps-label">' . $e(self::t('Streak')) . '</div>'
                . '<div class="ps-value">' . $e($insights['current_streak'] === 1 ? self::t('1 day') : self::t(':count days', ['count' => $insights['current_streak']])) . '</div>'
                . '<div class="ps-muted">' . $e(self::t('in a row with something watched')) . '</div></div>';
        }
        $html .= '</div>';

        // When you watch, genres, devices
        $html .= '<div class="ps-grid">';
        if (array_sum($insights['weekdays']) > 0) {
            $max = max($insights['weekdays']);
            $names = [1 => self::t('Monday'), 2 => self::t('Tuesday'), 3 => self::t('Wednesday'), 4 => self::t('Thursday'), 5 => self::t('Friday'), 6 => self::t('Saturday'), 7 => self::t('Sunday')];
            $html .= '<section class="ps-card"><h2>' . $e(self::t('When you watch')) . '</h2><div class="ps-week">';
            foreach ($insights['weekdays'] as $day => $minutes) {
                $html .= '<div class="ps-col" title="' . $e($this->duration($minutes * 60000)) . '"><span class="ps-colbar"><span style="height:' . max(3, (int) round(100 * $minutes / $max)) . '%"></span></span><span class="ps-tiny">' . $e(mb_substr($names[$day], 0, 3)) . '</span></div>';
            }
            $html .= '</div>';
            $parts = ['morning' => self::t('Morning'), 'afternoon' => self::t('Afternoon'), 'evening' => self::t('Evening'), 'night' => self::t('Night')];
            $total = max(1, array_sum($insights['dayparts']));
            foreach ($parts as $key => $label) {
                $html .= '<div class="ps-line"><span>' . $e($label) . '</span>' . $bar($insights['dayparts'][$key] / $total) . '<span class="ps-tiny">' . round(100 * $insights['dayparts'][$key] / $total) . '%</span></div>';
            }
            $html .= '<div class="ps-pair"><div><div class="ps-label">' . $e(self::t('Favourite day')) . '</div><div class="ps-title">' . $e($names[array_search($max, $insights['weekdays'], true)]) . '</div></div>'
                . '<div><div class="ps-label">' . $e(self::t('Favourite time')) . '</div><div class="ps-title">' . $e($parts[array_search(max($insights['dayparts']), $insights['dayparts'], true)]) . '</div></div></div>';
            $html .= '</section>';
        }
        if ($stats['genres'] || $insights['devices']) {
            $html .= '<section class="ps-card">';
            if ($stats['genres']) {
                $top = max($stats['genres']);
                $html .= '<h2>' . $e(self::t('Favourite genres')) . '</h2>';
                foreach ($stats['genres'] as $genre => $count) {
                    $html .= '<div class="ps-line"><span>' . $e($genre) . '</span>' . $bar($count / max(1, $top)) . '<span class="ps-tiny">' . $e($count) . '</span></div>';
                }
            }
            if ($insights['devices']) {
                $html .= '<h2>' . $e(self::t('Where you watch')) . '</h2>';
                $sum = array_sum($insights['devices']);
                foreach ($insights['devices'] as $device => $count) {
                    $html .= '<div class="ps-line"><span>' . $e($device) . '</span>' . $bar($count / max(1, $sum)) . '<span class="ps-tiny">' . round(100 * $count / max(1, $sum)) . '%</span></div>';
                }
                if ($insights['direct_share'] !== null) {
                    $html .= '<p class="ps-muted ps-small">' . $e(self::t(':percent% played directly, without converting', ['percent' => $insights['direct_share']])) . '</p>';
                }
            }
            $html .= '</section>';
        }
        $html .= '</div>';

        // Shows
        if ($stats['shows']) {
            $html .= '<h2 class="ps-h">' . $e(self::t('Your shows')) . '</h2><div class="ps-shows">';
            foreach ($stats['shows'] as $show) {
                $progress = $show['episodes'] ? min(1, $show['watched'] / $show['episodes']) : null;
                $html .= '<div class="ps-card ps-show">' . $poster($show['poster'], $show['title'], 'ps-thumb')
                    . '<div class="ps-grow"><div class="ps-title">' . $e($show['title']) . '</div>'
                    . '<div class="ps-muted ps-small">' . $e($progress !== null
                        ? self::t(':watched of :total episodes', ['watched' => $show['watched'], 'total' => $show['episodes']])
                        : ($show['watched'] === 1 ? self::t('1 episode') : self::t(':count episodes', ['count' => $show['watched']]))) . '</div>'
                    . ($progress !== null ? $bar($progress) : '') . '</div></div>';
            }
            $html .= '</div>';
        }

        // Recently watched
        if ($stats['recent']) {
            $html .= '<h2 class="ps-h">' . $e(self::t('Recently watched')) . '</h2><div class="ps-posters">';
            foreach ($stats['recent'] as $play) {
                $title = $play['show'] !== '' ? $play['show'] : $play['title'] . ($play['year'] ? ' (' . $play['year'] . ')' : '');
                $sub = $play['show'] !== ''
                    ? ($play['season'] !== null && $play['episode'] !== null ? sprintf('S%02dE%02d', $play['season'], $play['episode']) . ' · ' : '') . $play['title']
                    : $play['server'];
                $when = ($ts = strtotime($play['started_at'])) ? date(self::t('M j, Y'), $ts) : '';
                $html .= '<div class="ps-tile">' . $poster($play['poster'], $title, 'ps-cover',
                    $play['percent'] !== null && $play['percent'] < 95 ? '<span class="ps-progress"><span style="width:' . max(2, $play['percent']) . '%"></span></span>' : '')
                    . '<div class="ps-title ps-clip">' . $e($title) . '</div>'
                    . '<div class="ps-muted ps-small ps-clip">' . $e($sub) . '</div>'
                    . '<div class="ps-muted ps-tiny">' . $e($when) . '</div></div>';
            }
            $html .= '</div>';
        }

        if ($insights['sample'] > 0) {
            $html .= '<p class="ps-muted ps-tiny ps-foot">' . $e(self::t('Patterns are based on your latest :count plays.', ['count' => $insights['sample']])) . '</p>';
        }

        return $html . '</div>';
    }

    private function css(): string
    {
        return '#paywarr-stats{max-width:68rem;margin-left:auto;margin-right:auto;padding:0 1rem 3rem;color:hsl(var(--color-base))}'
            . '#paywarr-stats *{box-sizing:border-box}'
            . '#paywarr-stats h1{font-size:1.9rem;font-weight:800;margin:0}'
            . '#paywarr-stats h2{font-size:1rem;font-weight:700;margin:0 0 .9rem}'
            . '#paywarr-stats .ps-h{margin:2.25rem 0 1rem;font-size:1.15rem}'
            . '#paywarr-stats .ps-head{margin-bottom:1.5rem}'
            . '#paywarr-stats .ps-muted{color:hsl(var(--color-muted, var(--color-base)))}'
            . '#paywarr-stats .ps-small{font-size:.85rem}#paywarr-stats .ps-tiny{font-size:.75rem;color:hsl(var(--color-muted, var(--color-base)))}'
            . '#paywarr-stats .ps-card{background:hsl(var(--color-background-secondary));border:1px solid hsl(var(--color-neutral));border-radius:1rem;padding:1.15rem 1.3rem}'
            . '#paywarr-stats .ps-note{margin:0}'
            . '#paywarr-stats .ps-hero{border-radius:1.25rem;padding:1.6rem 1.75rem;border:1px solid hsl(var(--color-neutral));'
            . 'background:linear-gradient(135deg,hsl(var(--color-primary) / .22),hsl(var(--color-background-secondary)) 65%)}'
            . '#paywarr-stats .ps-label{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:hsl(var(--color-muted, var(--color-base)))}'
            . '#paywarr-stats .ps-big{font-size:clamp(2.2rem,6vw,3.4rem);font-weight:800;line-height:1.1;margin:.3rem 0}'
            . '#paywarr-stats .ps-value{font-size:1.55rem;font-weight:700;margin:.3rem 0}'
            . '#paywarr-stats .ps-chips{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}'
            . '#paywarr-stats .ps-chip{padding:.35rem .8rem;border-radius:9999px;font-size:.85rem;background:hsl(var(--color-background) / .55);border:1px solid hsl(var(--color-neutral))}'
            . '#paywarr-stats .ps-periods{display:grid;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr));gap:1rem;margin-top:1rem}'
            . '#paywarr-stats .ps-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr));gap:1rem;margin-top:1rem}'
            . '#paywarr-stats .ps-grid h2+*{margin-top:0}#paywarr-stats .ps-grid section h2:not(:first-child){margin-top:1.4rem}'
            . '#paywarr-stats .ps-week{display:flex;align-items:flex-end;gap:.5rem;height:8.5rem;margin-bottom:1.1rem}'
            . '#paywarr-stats .ps-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:.35rem;height:100%}'
            . '#paywarr-stats .ps-colbar{flex:1;width:100%;max-width:2.4rem;display:flex;align-items:flex-end;border-radius:.5rem;background:hsl(var(--color-neutral) / .45)}'
            . '#paywarr-stats .ps-colbar span{display:block;width:100%;border-radius:.5rem;background:hsl(var(--color-primary))}'
            . '#paywarr-stats .ps-line{display:grid;grid-template-columns:minmax(5rem,9rem) 1fr 2.6rem;align-items:center;gap:.75rem;margin:.45rem 0;font-size:.9rem}'
            . '#paywarr-stats .ps-line>span:first-child{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '#paywarr-stats .ps-line .ps-tiny{text-align:right}'
            . '#paywarr-stats .ps-bar{display:block;height:.5rem;border-radius:9999px;background:hsl(var(--color-neutral) / .55);overflow:hidden}'
            . '#paywarr-stats .ps-bar span{display:block;height:100%;border-radius:9999px;background:hsl(var(--color-primary))}'
            . '#paywarr-stats .ps-shows{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,19rem),1fr));gap:1rem}'
            . '#paywarr-stats .ps-show{display:flex;gap:1rem;align-items:center;padding:.8rem}'
            . '#paywarr-stats .ps-show .ps-bar{margin-top:.6rem}#paywarr-stats .ps-grow{flex:1;min-width:0}'
            . '#paywarr-stats .ps-title{font-weight:600}'
            . '#paywarr-stats .ps-thumb,#paywarr-stats .ps-cover{position:relative;display:block;overflow:hidden;border-radius:.6rem;background:linear-gradient(160deg,hsl(var(--color-primary) / .35),hsl(var(--color-neutral)))}'
            . '#paywarr-stats .ps-thumb{flex:none;width:3.6rem;aspect-ratio:2/3}'
            . '#paywarr-stats .ps-cover{width:100%;aspect-ratio:2/3;margin-bottom:.55rem;box-shadow:0 8px 22px rgb(0 0 0 / .25)}'
            . '#paywarr-stats .ps-thumb img,#paywarr-stats .ps-cover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
            . '#paywarr-stats .ps-letter{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;opacity:.6}'
            . '#paywarr-stats .ps-posters{display:grid;grid-template-columns:repeat(auto-fill,minmax(9.5rem,1fr));gap:1.1rem}'
            . '#paywarr-stats .ps-pair{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1.2rem;padding-top:1rem;border-top:1px solid hsl(var(--color-neutral))}'
            . '#paywarr-stats .ps-tile{position:relative;min-width:0}'
            . '#paywarr-stats .ps-progress{position:absolute;left:0;right:0;bottom:0;display:block;height:.3rem;background:rgb(0 0 0 / .55)}'
            . '#paywarr-stats .ps-progress span{display:block;height:100%;background:hsl(var(--color-primary))}'
            . '#paywarr-stats .ps-clip{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}'
            . '#paywarr-stats .ps-foot{margin-top:2rem}'
            . '@media (max-width:640px){#paywarr-stats .ps-posters{grid-template-columns:repeat(auto-fill,minmax(6.25rem,1fr));gap:.75rem}#paywarr-stats .ps-hero{padding:1.3rem}}';
    }

    /** "12 h 5 min", "45 min" (translated). */
    public function duration(int $milliseconds): string
    {
        $minutes = intdiv(max(0, $milliseconds), 60000);
        $hours = intdiv($minutes, 60);

        return $hours > 0
            ? self::t(':hours h :minutes min', ['hours' => $hours, 'minutes' => $minutes % 60])
            : self::t(':minutes min', ['minutes' => $minutes]);
    }

    private function client(): TracearrClient
    {
        return $this->client ??= new TracearrClient((string) $this->config('tracearr_url'), (string) $this->config('tracearr_api_key'));
    }

    /* ------------------------------------------------------------------
     | Translations: lang/<locale>.json, English otherwise (as in Paywarr)
     * ----------------------------------------------------------------- */

    /** @var array<string, array<string, string>> */
    private static array $translations = [];

    public static function t(string $text, array $replace = []): string
    {
        $locale = function_exists('app') ? (string) app()->getLocale() : 'en';
        foreach (array_unique([$locale, strtolower(strtok($locale, '_-') ?: $locale)]) as $candidate) {
            if (!preg_match('/^[A-Za-z_-]{2,10}$/', $candidate)) {
                continue;
            }
            if (!array_key_exists($candidate, self::$translations)) {
                $file = __DIR__ . '/lang/' . $candidate . '.json';
                $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
                self::$translations[$candidate] = is_array($decoded) ? $decoded : [];
            }
            if (isset(self::$translations[$candidate][$text]) && self::$translations[$candidate][$text] !== '') {
                $text = (string) self::$translations[$candidate][$text];

                break;
            }
        }
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return $pairs ? strtr($text, $pairs) : $text;
    }
}
