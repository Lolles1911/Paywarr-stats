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
    version: '1.1.0',
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
            'slot' => new HtmlString($this->pageHtml($stats, rtrim(route('extensions.paywarr-stats'), '/') . '/poster/')),
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
        $path = $stats['history'][$number - 1]['poster'] ?? null;

        return $number >= 1 && is_string($path) && $path !== '' ? $this->client()->poster($path) : null;
    }

    /** The customer's statistics, kept for CACHE_SECONDS. */
    private function statsForUser($user): array
    {
        // "v2": entries from 1.0.0 were stored without posters.
        return Cache::remember('paywarr-stats:v2:' . $user->id, self::CACHE_SECONDS, function () use ($user) {
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
        $history = [];
        foreach ($ids as $id) {
            $stats = $this->client()->userStats($id);
            foreach ($windows as $key => $sum) {
                $windows[$key]['plays'] += (int) ($stats['windows'][$key]['plays'] ?? 0);
                $windows[$key]['watch_time_ms'] += (int) ($stats['windows'][$key]['watch_time_ms'] ?? 0);
            }
            foreach ($stats['top_genres'] ?? [] as $genre) {
                $genres[(string) $genre['genre']] = ($genres[(string) $genre['genre']] ?? 0) + (int) $genre['plays'];
            }
            foreach ($this->client()->userHistory($id, 10) as $play) {
                $history[] = [
                    'title' => (string) ($play['media_title'] ?? ''),
                    'show' => (string) ($play['show_title'] ?? ''),
                    'season' => $play['season_number'] ?? null,
                    'episode' => $play['episode_number'] ?? null,
                    'year' => $play['year'] ?? null,
                    'type' => (string) ($play['media_type'] ?? ''),
                    'server' => (string) ($play['server_name'] ?? ''),
                    'started_at' => (string) ($play['started_at'] ?? ''),
                    'percent' => isset($play['percent_complete']) ? (int) round((float) $play['percent_complete']) : null,
                    // Tracearr's own proxy path; served to the customer through /stats/poster/{number}.
                    'poster' => str_starts_with((string) ($play['poster_url'] ?? ''), '/api/v1/images/proxy?') ? (string) $play['poster_url'] : '',
                ];
            }
        }
        arsort($genres);
        usort($history, fn ($a, $b) => strcmp($b['started_at'], $a['started_at']));

        return [
            'state' => 'ok',
            'windows' => $windows,
            'genres' => array_slice($genres, 0, 5, true),
            'history' => array_slice($history, 0, 10),
        ];
    }

    /** The page body. Styled with the theme's colour variables, like Paywarr's box. */
    public function pageHtml(?array $stats, string $posterBase = '/stats/poster/'): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '<div id="paywarr-stats" class="container mt-14">'
            . '<style>'
            . '#paywarr-stats{max-width:60rem;margin-left:auto;margin-right:auto;padding:0 1rem 3rem;color:hsl(var(--color-base))}'
            . '#paywarr-stats h1{font-size:1.75rem;font-weight:700;margin:0 0 1.5rem}'
            . '#paywarr-stats h2{font-size:1.1rem;font-weight:700;margin:2rem 0 .75rem}'
            . '#paywarr-stats .ps-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(12rem,1fr));gap:1rem}'
            . '#paywarr-stats .ps-card,#paywarr-stats .ps-note,#paywarr-stats .ps-list{background:hsl(var(--color-background-secondary));border:1px solid hsl(var(--color-neutral));border-radius:.75rem;padding:1rem 1.25rem}'
            . '#paywarr-stats .ps-label{font-size:.85rem;color:hsl(var(--color-muted, var(--color-base)))}'
            . '#paywarr-stats .ps-value{font-size:1.6rem;font-weight:700;margin:.25rem 0}'
            . '#paywarr-stats .ps-list{padding:0}'
            . '#paywarr-stats .ps-row{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.75rem 1.25rem;border-top:1px solid hsl(var(--color-neutral))}'
            . '#paywarr-stats .ps-row:first-child{border-top:0}'
            . '#paywarr-stats .ps-sub{font-size:.85rem;color:hsl(var(--color-muted, var(--color-base)))}'
            . '#paywarr-stats .ps-play{display:flex;gap:.9rem;align-items:center;min-width:0}'
            . '#paywarr-stats .ps-poster{flex:none;width:2.75rem;height:4.1rem;border-radius:.35rem;object-fit:cover;background:hsl(var(--color-neutral))}'
            . '#paywarr-stats .ps-genre{display:inline-block;margin:0 .5rem .5rem 0;padding:.35rem .8rem;border-radius:9999px;border:1px solid hsl(var(--color-neutral));font-size:.9rem}'
            . '</style>'
            . '<h1>' . $e(self::t('My stats')) . '</h1>';

        if ($stats === null || ($stats['state'] ?? '') !== 'ok') {
            $message = match ($stats['state'] ?? null) {
                'no_access' => self::t('Your statistics appear here once you have created your user and started watching.'),
                'no_data' => self::t('Nothing watched yet. Your statistics appear here once you start watching.'),
                default => self::t('Your statistics can\'t be loaded right now. Please try again later.'),
            };

            return $html . '<p class="ps-note">' . $e($message) . '</p></div>';
        }

        $html .= '<div class="ps-cards">';
        foreach (['last_7' => self::t('Last 7 days'), 'last_30' => self::t('Last 30 days'), 'all_time' => self::t('All time')] as $key => $label) {
            $window = $stats['windows'][$key];
            $html .= '<div class="ps-card"><div class="ps-label">' . $e($label) . '</div>'
                . '<div class="ps-value">' . $e($this->duration($window['watch_time_ms'])) . '</div>'
                . '<div class="ps-sub">' . $e($window['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $window['plays']])) . '</div></div>';
        }
        $html .= '</div>';

        if ($stats['genres']) {
            $html .= '<h2>' . $e(self::t('Favourite genres')) . '</h2><div>';
            foreach (array_keys($stats['genres']) as $genre) {
                $html .= '<span class="ps-genre">' . $e($genre) . '</span>';
            }
            $html .= '</div>';
        }

        if ($stats['history']) {
            $html .= '<h2>' . $e(self::t('Recently watched')) . '</h2><div class="ps-list">';
            foreach ($stats['history'] as $index => $play) {
                $title = $play['show'] !== ''
                    ? $play['show'] . ($play['season'] !== null && $play['episode'] !== null ? sprintf(' S%02dE%02d', $play['season'], $play['episode']) : '') . ($play['title'] !== '' ? ' – ' . $play['title'] : '')
                    : $play['title'] . ($play['year'] ? ' (' . $play['year'] . ')' : '');
                $when = ($ts = strtotime($play['started_at'])) ? date(self::t('M j, Y'), $ts) : '';
                $poster = ($play['poster'] ?? '') !== ''
                    ? '<img class="ps-poster" loading="lazy" alt="" src="' . $e($posterBase . ($index + 1)) . '" onerror="this.style.visibility=\'hidden\'">'
                    : '<span class="ps-poster" aria-hidden="true"></span>';
                $html .= '<div class="ps-row"><div class="ps-play">' . $poster . '<div><div>' . $e($title) . '</div><div class="ps-sub">' . $e(trim($play['server'] . ($when !== '' ? ' · ' . $when : ''), ' ·')) . '</div></div></div>'
                    . '<div class="ps-sub">' . ($play['percent'] !== null ? $e($play['percent'] . '%') : '') . '</div></div>';
            }
            $html .= '</div>';
        }

        return $html . '</div>';
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
