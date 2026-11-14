<?php

/*
 * Scenarios for Paywarr Stats against tracearr_stub.py. Run with ./run.sh.
 */

require __DIR__ . '/shims.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/TracearrException.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/TracearrClient.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/PaywarrStats.php';

use Paymenter\Extensions\Others\PaywarrStats\PaywarrStats;
use Paymenter\Extensions\Others\PaywarrStats\TracearrClient;
use Paymenter\Extensions\Others\PaywarrStats\TracearrException;

$STUB = 'http://127.0.0.1:' . ($argv[1] ?? '8767');

/* ---------- tiny test kit ---------- */

$results = ['pass' => 0, 'fail' => []];

function stub(string $path, ?array $body = null): array
{
    global $STUB;
    $curl = curl_init($STUB . $path);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($body ?? new stdClass),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_PROXY => '',
    ]);
    $raw = curl_exec($curl);
    if ($raw === false) {
        fwrite(STDERR, "stub not reachable at $STUB\n");
        exit(2);
    }
    curl_close($curl);

    return json_decode($raw, true);
}

function state(): array
{
    return stub('/_test/state');
}

function same(mixed $expected, mixed $actual, string $what): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($what . "\n      expected: " . json_encode($expected) . "\n      actual:   " . json_encode($actual));
    }
}

function ok(bool $condition, string $what): void
{
    if (!$condition) {
        throw new RuntimeException($what);
    }
}

/** Runs $fn with Paymenter set to $locale, then back to English. */
function inLocale(string $locale, callable $fn)
{
    app()->setLocale($locale);
    try {
        return $fn();
    } finally {
        app()->setLocale('en');
    }
}

function test(string $name, callable $fn): void
{
    global $results;
    stub('/_test/reset');
    \Illuminate\Support\Facades\Cache::$store = [];
    \Illuminate\Support\Facades\Log::$warnings = [];
    try {
        $fn();
        $results['pass']++;
        echo "  ok    $name\n";
    } catch (Throwable $e) {
        $results['fail'][] = $name;
        echo "  FAIL  $name\n      " . $e->getMessage() . "\n";
    }
}

echo "\nPaywarr Stats\n\n";

/* ---------- Paywarr Stats: each customer's own numbers from Tracearr ---------- */


function stats(array $config = []): PaywarrStats
{
    global $STUB;

    return new PaywarrStats($config + ['tracearr_url' => $STUB, 'tracearr_api_key' => 'trr_pub_test']);
}

/** Tracearr identity with one account per [type, username]. */
function identity(string $id, array $accounts, array $removed = []): array
{
    return ['id' => $id, 'username' => $accounts[0][1], 'accounts' => array_map(fn ($a) => [
        'server_id' => 'srv-' . $a[0], 'server_type' => $a[0], 'server_user_id' => $id . '-' . $a[0], 'external_user_id' => 'x', 'username' => $a[1],
        'removed_at' => in_array($a[1], $removed, true) ? '2026-01-01T00:00:00Z' : null,
    ], $accounts)];
}

function windows(int $p7, int $h7, int $p30, int $h30, int $pAll, int $hAll): array
{
    $ms = fn ($hours) => $hours * 3600000;

    return ['last_7' => ['plays' => $p7, 'watch_time_ms' => $ms($h7)], 'last_30' => ['plays' => $p30, 'watch_time_ms' => $ms($h30)], 'all_time' => ['plays' => $pAll, 'watch_time_ms' => $ms($hAll)]];
}

/** Two customers in Tracearr: mette (Jellyfin and Emby identities) and ole. */
function tracearrWithCustomers(): void
{
    stub('/_test/set', [
        'identities' => [identity('u-mette', [['jellyfin', 'mette']]), identity('u-mette-emby', [['emby', 'Mette']]), identity('u-ole', [['jellyfin', 'ole']])],
        'stats' => [
            'u-mette' => ['windows' => windows(2, 3, 10, 12, 40, 50), 'top_genres' => [['genre' => 'Drama', 'plays' => 20], ['genre' => 'Comedy', 'plays' => 5]]],
            'u-mette-emby' => ['windows' => windows(1, 1, 2, 2, 3, 4), 'top_genres' => [['genre' => 'Comedy', 'plays' => 30]]],
            'u-ole' => ['windows' => windows(99, 99, 99, 99, 99, 99), 'top_genres' => [['genre' => 'Horror', 'plays' => 99]]],
        ],
        'history' => [
            'u-mette' => [['media_title' => 'Pilot', 'show_title' => 'The Show', 'season_number' => 1, 'episode_number' => 2, 'media_type' => 'episode', 'server_name' => 'Jellyfin', 'started_at' => '2026-11-10T20:00:00Z', 'percent_complete' => 100,
                'poster_url' => '/api/v1/images/proxy?server=s1&url=%2FItems%2Fshow%2FImages%2FPrimary&width=300&height=450&fallback=poster']],
            'u-mette-emby' => [['media_title' => 'A Film', 'show_title' => null, 'year' => 2024, 'media_type' => 'movie', 'server_name' => 'Emby', 'started_at' => '2026-11-12T20:00:00Z', 'percent_complete' => 55.4,
                'poster_url' => 'http://evil.example.com/steal']],
            'u-ole' => [['media_title' => 'Ole\'s secret', 'media_type' => 'movie', 'server_name' => 'Jellyfin', 'started_at' => '2026-11-13T20:00:00Z']],
        ],
    ]);
}

test('stats: the customer\'s media users are read from Paywarr, old and new style', function () {
    same([
        ['type' => 'jellyfin', 'username' => 'mette'],
        ['type' => 'emby', 'username' => 'Mette'],
        ['type' => '', 'username' => 'old'],
    ], stats()->mediaAccounts([
        ['wizarr_users:1' => '1:mette:jellyfin', 'wizarr_users:4' => '2:Mette:emby', 'seerr_user_id' => '5'],
        ['wizarr_users' => '3:old', 'wizarr_users:1' => '1:mette:jellyfin'],
    ]), 'parsed, duplicates once');
});

test('stats: only identities with the customer\'s own accounts are matched', function () {
    $identities = [identity('a', [['jellyfin', 'Mette']]), identity('b', [['emby', 'mette']]), identity('c', [['jellyfin', 'ole']]), identity('d', [['jellyfin', 'mette2']]), identity('e', [['plex', 'gone']], ['gone'])];
    same(['a'], stats()->matchIdentities([['type' => 'jellyfin', 'username' => 'mette']], $identities), 'same type and username, any case');
    same(['a', 'b'], stats()->matchIdentities([['type' => '', 'username' => 'mette']], $identities), 'no type recorded: any server');
    same([], stats()->matchIdentities([['type' => 'plex', 'username' => 'gone']], $identities), 'a removed account is not theirs any more');
});

test('stats: numbers from all the customer\'s identities, and never anyone else\'s', function () {
    tracearrWithCustomers();
    $stats = stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]);
    same('ok', $stats['state'], 'found');
    same(['plays' => 3, 'watch_time_ms' => 4 * 3600000], $stats['windows']['last_7'], 'last 7 days summed');
    same(['Comedy' => 35, 'Drama' => 20], $stats['genres'], 'genres summed, most first');
    same(['A Film', 'Pilot'], array_column($stats['history'], 'title'), 'history merged, newest first');
    $asked = array_column(array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], '/users/')), 'path');
    ok(!array_filter($asked, fn ($p) => str_contains($p, 'u-ole')), 'Tracearr is never asked about another customer: ' . json_encode($asked));
});

test('stats: no media user yet, or nothing in Tracearr yet', function () {
    tracearrWithCustomers();
    same(['state' => 'no_access'], stats()->statsFor([]), 'no user');
    same(['state' => 'no_data'], stats()->statsFor([['type' => 'jellyfin', 'username' => 'newbie']]), 'not in Tracearr');
});

test('stats: Tracearr\'s users are read page by page', function () {
    $many = [];
    for ($i = 0; $i < 230; $i++) {
        $many[] = identity("u$i", [['jellyfin', "user$i"]]);
    }
    stub('/_test/set', ['identities' => $many]);
    same(['u229'], stats()->matchIdentities([['type' => 'jellyfin', 'username' => 'user229']], (new TracearrClient($GLOBALS['STUB'], 'trr_pub_test'))->users()), 'found on the third page');
});

test('stats: a wrong key or Tracearr down is reported plainly', function () {
    try {
        stats(['tracearr_api_key' => 'trr_pub_wrong'])->statsFor([['type' => 'jellyfin', 'username' => 'mette']]);
        $message = null;
    } catch (TracearrException $e) {
        $message = $e->getMessage();
    }
    ok(str_contains((string) $message, 'rejected the API key'), 'wrong key: ' . $message);
    try {
        stats(['tracearr_url' => 'http://127.0.0.1:1'])->statsFor([['type' => 'jellyfin', 'username' => 'mette']]);
        $message = null;
    } catch (TracearrException $e) {
        $message = $e->getMessage();
    }
    ok(str_contains((string) $message, 'Could not reach Tracearr'), 'down: ' . $message);
});

test('stats: the page shows the numbers, escapes what it prints, and speaks Danish', function () {
    tracearrWithCustomers();
    $html = stats()->pageHtml(stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]));
    foreach (['My stats', 'Last 7 days', '4 h 0 min', '3 plays', '54 h 0 min', 'Comedy', 'The Show S01E02 – Pilot', 'A Film (2024)', 'Emby · Nov 12, 2026', '55%'] as $needle) {
        ok(str_contains($html, $needle), "shows \"$needle\"");
    }
    same('Min statistik', inLocale('da', fn () => PaywarrStats::t('My stats')), 'Danish');
    ok(str_contains(inLocale('da', fn () => stats()->pageHtml(['state' => 'no_data'])), 'Intet set endnu'), 'Danish message');
    $evil = stats()->pageHtml(['state' => 'ok', 'windows' => windows(0, 0, 0, 0, 0, 0), 'genres' => ['<b>x</b>' => 1], 'history' => [['title' => '<script>', 'show' => '', 'season' => null, 'episode' => null, 'year' => null, 'type' => 'movie', 'server' => '', 'started_at' => '', 'percent' => null]]]);
    ok(!str_contains($evil, '<script>') && !str_contains($evil, '<b>x</b>'), 'escaped');
    ok(str_contains(stats()->pageHtml(null), 'be loaded right now'), 'Tracearr down: a message, not an error page');
});

test('stats: posters come through Paymenter, only from Tracearr\'s image proxy and only the customer\'s own', function () {
    tracearrWithCustomers();
    $stats = stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]);
    same(['', '/api/v1/images/proxy?server=s1&url=%2FItems%2Fshow%2FImages%2FPrimary&width=300&height=450&fallback=poster'], array_column($stats['history'], 'poster'), 'only proxy paths are kept');

    $image = stats()->posterFor($stats, 2);
    same('image/jpeg', $image['type'] ?? null, 'the image, as an image');
    ok(str_contains($image['body'] ?? '', '/Items/show/Images/Primary'), 'from Tracearr\'s proxy');
    same([null, null, null], [stats()->posterFor($stats, 1), stats()->posterFor($stats, 0), stats()->posterFor($stats, 99)], 'no poster, number 0, a number past the list');

    $html = stats()->pageHtml($stats, '/stats/poster/');
    ok(str_contains($html, 'src="/stats/poster/2"') && !str_contains($html, 'src="/stats/poster/1"'), 'an <img> only where there is a poster');
    ok(!str_contains($html, 'evil.example.com') && !str_contains($html, 'images/proxy'), 'no outside or Tracearr address in the page');
});

test('stats: the poster fetch refuses anything but an image from the proxy', function () {
    global $STUB;
    $client = new TracearrClient($STUB, 'trr_pub_test');
    stub('/_test/clear-requests');
    same(null, $client->poster('/api/v2/public/users'), 'another Tracearr path');
    same(null, $client->poster('http://evil.example.com/x'), 'another host');
    same([], state()['requests'], 'neither was even asked for');
    same(null, $client->poster('/api/v1/images/proxy?url=notimage'), 'not an image (e.g. a login page)');
    same(null, $client->poster('/api/v1/images/proxy?url=svg'), 'SVG, which can carry scripts');
    same('image/jpeg', $client->poster('/api/v1/images/proxy?url=ok')['type'] ?? null, 'a real image');
});

test('stats: boot adds "My stats" to the client area menu', function () {
    \Illuminate\Support\Facades\Route::$routes = [];
    \Illuminate\Support\Facades\Event::$listeners = [];
    stats()->boot();
    $item = null;
    foreach (\Illuminate\Support\Facades\Event::$listeners as [$event, $listener]) {
        if ($event === 'navigation.dashboard') {
            $item = $listener();
        }
    }
    same(['My stats', 'extensions.paywarr-stats', 'ri-bar-chart-2'], [$item['name'] ?? null, $item['route'] ?? null, $item['icon'] ?? null], 'menu item');
    $routes = \Illuminate\Support\Facades\Route::$routes;
    same(['GET stats extensions.paywarr-stats', 'GET stats/poster/{number} extensions.paywarr-stats.poster'], array_map(fn ($r) => $r->method . ' ' . $r->uri . ' ' . $r->name, $routes), 'routes');
    same(['web', 'auth'], $routes[0]->middleware, 'logged-in customers only');
    same([['web', 'auth', 'throttle:120,1'], ['number' => '[0-9]+']], [$routes[1]->middleware, $routes[1]->wheres], 'posters: logged in, throttled, by number only');
});

test('stats: every text has a Danish translation', function () {
    $code = file_get_contents(__DIR__ . '/../extensions/Others/PaywarrStats/PaywarrStats.php');
    preg_match_all('/self::t\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $code, $m);
    $used = array_unique(array_map(fn ($t) => strtr($t, ['\\\\' => '\\', "\\'" => "'"]), $m[1]));
    $danish = json_decode(file_get_contents(__DIR__ . '/../extensions/Others/PaywarrStats/lang/da.json'), true);
    same([], array_values(array_diff($used, array_keys($danish))), 'missing in da.json');
    same([], array_values(array_diff(array_keys($danish), $used)), 'not used');
});

/* ---------- summary ---------- */

echo "\n" . $results['pass'] . ' passed, ' . count($results['fail']) . " failed\n";
exit($results['fail'] ? 1 : 0);
