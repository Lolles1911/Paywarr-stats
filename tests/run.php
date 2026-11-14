<?php

/*
 * Scenarios for Paywarr Stats against tracearr_stub.py. Run with ./run.sh.
 */

require __DIR__ . '/shims.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/TracearrException.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/TracearrClient.php';
require __DIR__ . '/../extensions/Others/PaywarrStats/SiloClient.php';
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
            'u-mette' => [['media_title' => 'Pilot', 'show_title' => 'The Show', 'season_number' => 1, 'episode_number' => 2, 'media_type' => 'episode', 'server_name' => 'Jellyfin', 'started_at' => '2026-11-10T20:00:00Z', 'percent_complete' => 100, 'duration_ms' => 2700000, 'device' => 'Living room TV', 'is_transcode' => false,
                'poster_url' => '/api/v1/images/proxy?server=s1&url=%2FItems%2Fshow%2FImages%2FPrimary&width=300&height=450&fallback=poster']],
            'u-mette-emby' => [['media_title' => 'A Film', 'show_title' => null, 'year' => 2024, 'media_type' => 'movie', 'server_name' => 'Emby', 'started_at' => '2026-11-12T20:00:00Z', 'percent_complete' => 55.4,
                'poster_url' => 'http://evil.example.com/steal']],
            'u-ole' => [['media_title' => 'Ole\'s secret', 'media_type' => 'movie', 'server_name' => 'Jellyfin', 'started_at' => '2026-11-13T20:00:00Z']],
        ],
        'watched' => [
            'u-mette' => [
                'show' => [['media_type' => 'show', 'title' => 'The Show', 'watched_state' => 'partial', 'plays' => 7, 'last_watched_day' => '2026-11-10', 'episodes_watched' => 7, 'episode_count' => 19]],
                'movie' => [['media_type' => 'movie', 'title' => 'Old Film', 'watched_state' => 'watched', 'plays' => 1], ['media_type' => 'movie', 'title' => 'Half Film', 'watched_state' => 'partial', 'plays' => 1]],
            ],
            'u-mette-emby' => [
                'show' => [['media_type' => 'show', 'title' => 'the show', 'watched_state' => 'partial', 'plays' => 2, 'last_watched_day' => '2026-11-01', 'episodes_watched' => 2, 'episode_count' => 19]],
                'movie' => [['media_type' => 'movie', 'title' => 'A Film', 'watched_state' => 'watched', 'plays' => 1]],
            ],
            'u-ole' => ['show' => [['media_type' => 'show', 'title' => 'Ole\'s show', 'watched_state' => 'watched', 'plays' => 50, 'episodes_watched' => 50]], 'movie' => []],
        ],
    ]);
}

test('stats: the customer\'s media users are read from Paywarr, old and new style', function () {
    same([
        ['type' => 'jellyfin', 'username' => 'mette', 'id' => '1'],
        ['type' => 'emby', 'username' => 'Mette', 'id' => '2'],
        ['type' => '', 'username' => 'old', 'id' => '3'],
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
    same(['A Film', 'Pilot'], array_column($stats['recent'], 'title'), 'history merged, newest first');
    same([2, 7], [$stats['movies_watched'], $stats['episodes_watched']], 'movies fully watched; a show on two servers counts its furthest progress');
    same([['The Show', 9, 7, 19]], array_map(fn ($s) => [$s['title'], $s['plays'], $s['watched'], $s['episodes']], $stats['shows']), 'one show across both servers, furthest progress');
    $requests = array_filter(state()['requests'], fn ($r) => !str_starts_with($r['path'], 'image:') && $r['path'] !== '/users');
    $asked = array_map(fn ($r) => $r['path'] . ' ' . ($r['query']['user_id'] ?? ''), $requests);
    ok(!array_filter($asked, fn ($p) => str_contains($p, 'u-ole')), 'Tracearr is never asked about another customer: ' . json_encode($asked));
    $watched = array_filter($requests, fn ($r) => $r['path'] === '/watched-media');
    ok(count($watched) === 4 && !array_filter($watched, fn ($r) => !in_array($r['query']['user_id'] ?? '', ['u-mette', 'u-mette-emby'], true)), 'watched media only ever for the customer\'s own identities');
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
    foreach (['My stats', 'Last 7 days', '4 h 0 min', '3 plays', '54 h 0 min', 'That is 2.3 days of watching', '2 movies', '7 episodes', 'Comedy', 'The Show', 'S01E02 · Pilot', 'A Film (2024)', 'Nov 12, 2026', 'width:55%', '7 of 19 episodes', 'When you watch'] as $needle) {
        ok(str_contains($html, $needle), "shows \"$needle\"");
    }
    same('Min statistik', inLocale('da', fn () => PaywarrStats::t('My stats')), 'Danish');
    ok(str_contains(inLocale('da', fn () => stats()->pageHtml(['state' => 'no_data'])), 'Intet set endnu'), 'Danish message');
    ok(str_contains(inLocale('da', fn () => stats()->pageHtml(stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette']]))), 'Det er 2,1 døgn'), 'Danish decimal comma');
    $evil = stats()->pageHtml(['state' => 'ok', 'windows' => windows(0, 0, 0, 0, 0, 0), 'genres' => ['<b>x</b>' => 1], 'movies_watched' => 0, 'episodes_watched' => 0,
        'shows' => [['title' => '<i>show</i>', 'plays' => 1, 'watched' => 1, 'episodes' => null, 'last' => '', 'poster' => 0]],
        'recent' => [['title' => '<script>', 'show' => '', 'season' => null, 'episode' => null, 'year' => null, 'type' => 'movie', 'server' => '', 'started_at' => '', 'percent' => null, 'watched_ms' => 0, 'device' => '<u>tv</u>', 'transcode' => null, 'poster' => 0]],
        'insights' => stats()->insights([['started_at' => '2026-11-10T20:00:00Z', 'watched_ms' => 60000, 'device' => '<u>tv</u>', 'transcode' => null]]), 'posters' => []], '/stats/poster/', '<em>Bo</em>');
    ok(!array_filter(['<script>', '<b>x</b>', '<i>show', '<u>tv', '<em>Bo'], fn ($tag) => str_contains($evil, $tag)), 'escaped');
    ok(str_contains(stats()->pageHtml(null), 'be loaded right now'), 'Tracearr down: a message, not an error page');
});

test('stats: posters come through Paymenter, only from Tracearr\'s image proxy and only the customer\'s own', function () {
    tracearrWithCustomers();
    $stats = stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette'], ['type' => 'emby', 'username' => 'mette']]);
    same(['/api/v1/images/proxy?server=s1&url=%2FItems%2Fshow%2FImages%2FPrimary&width=300&height=450&fallback=poster'], $stats['posters'], 'only proxy paths are kept, each once');
    same([[0, 1], 1], [array_column($stats['recent'], 'poster'), $stats['shows'][0]['poster']], 'plays and shows point at it by number');

    $image = stats()->posterFor($stats, 1);
    same('image/jpeg', $image['type'] ?? null, 'the image, as an image');
    ok(str_contains($image['body'] ?? '', '/Items/show/Images/Primary'), 'from Tracearr\'s proxy');
    same([null, null, null], [stats()->posterFor($stats, 0), stats()->posterFor($stats, -1), stats()->posterFor($stats, 99)], 'number 0, below, a number past the list');

    $html = stats()->pageHtml($stats, '/stats/poster/');
    same(2, substr_count($html, 'src="/stats/poster/1"'), 'the show and its episode share the poster');
    same(2, substr_count($html, '<img '), 'an <img> only where there is a poster');
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

test('stats: history is read page by page, up to 200 plays per identity', function () {
    $plays = [];
    for ($i = 0; $i < 250; $i++) {
        $plays[] = ['media_title' => "Film $i", 'media_type' => 'movie', 'started_at' => sprintf('2026-10-%02dT20:00:00Z', 1 + $i % 28)];
    }
    stub('/_test/set', ['history' => ['u1' => $plays]]);
    $client = new TracearrClient($GLOBALS['STUB'], 'trr_pub_test');
    same(200, count($client->userHistory('u1')), '200 of 250');
    same(['Film 0', 'Film 199'], [$client->userHistory('u1')[0]['media_title'], $client->userHistory('u1')[199]['media_title']], 'in order, no page twice');
    same(30, count($client->userHistory('u1', 30)), 'a smaller maximum');
    $sizes = array_map(fn ($r) => (int) $r['query']['pageSize'], array_filter(state()['requests'], fn ($r) => $r['path'] === '/users/u1/history'));
    ok(max($sizes) <= 100, 'never more than Tracearr allows per page');
});

test('stats: "Recently watched" shows each show or movie once, at its latest play', function () {
    $episode = fn (string $show, int $n, string $at) => ['media_title' => "Episode $n", 'show_title' => $show, 'season_number' => 1, 'episode_number' => $n, 'media_type' => 'episode', 'started_at' => $at];
    stub('/_test/set', ['identities' => [identity('u1', [['jellyfin', 'mette']])], 'history' => ['u1' => [
        $episode('Show A', 3, '2026-11-13T21:00:00Z'), $episode('Show A', 2, '2026-11-13T20:00:00Z'),
        ['media_title' => 'Film', 'year' => 2020, 'media_type' => 'movie', 'started_at' => '2026-11-12T20:00:00Z'],
        $episode('show a', 1, '2026-11-11T20:00:00Z'),
        ['media_title' => 'Film', 'year' => 1990, 'media_type' => 'movie', 'started_at' => '2026-11-10T20:00:00Z'],
    ]]]);
    $recent = stats()->statsFor([['type' => 'jellyfin', 'username' => 'mette']])['recent'];
    same([['Episode 3', 'Show A'], ['Film', ''], ['Film', '']], array_map(fn ($p) => [$p['title'], $p['show']], $recent), 'latest episode per show; a remake is another movie');
});

test('stats: when and where the customer watches, and streaks', function () {
    $play = fn (string $at, int $minutes, string $device = 'Living room TV', ?bool $transcode = false) => ['started_at' => $at, 'watched_ms' => $minutes * 60000, 'device' => $device, 'transcode' => $transcode];
    $insights = stats()->insights([
        $play('2026-11-14 21:00:00', 60),                  // Saturday evening
        $play('2026-11-13 08:00:00', 30, 'Phone', true),   // Friday morning
        $play('2026-11-12 14:00:00', 20),                  // Thursday afternoon
        $play('2026-11-12 02:00:00', 10, 'Phone', null),   // Thursday night
        $play('2026-11-01 20:00:00', 40, ''),              // Sunday, an earlier run of one day
        $play('not a date', 999),
    ], strtotime('2026-11-14 23:00:00'));
    same([1 => 0, 2 => 0, 3 => 0, 4 => 30, 5 => 30, 6 => 60, 7 => 40], $insights['weekdays'], 'minutes per weekday');
    same(['night' => 10, 'morning' => 30, 'afternoon' => 20, 'evening' => 100], $insights['dayparts'], 'minutes per time of day');
    same(['Living room TV' => 2, 'Phone' => 2], $insights['devices'], 'devices, most used first');
    same(75, $insights['direct_share'], 'direct play share of plays where it is known');
    same([3, 3], [$insights['current_streak'], $insights['longest_streak']], 'three days in a row up to today');
    same(0, stats()->insights([$play('2026-11-10 20:00:00', 5)], strtotime('2026-11-14 12:00:00'))['current_streak'], 'nothing since: no current streak');
    same(1, stats()->insights([$play('2026-11-13 20:00:00', 5)], strtotime('2026-11-14 12:00:00'))['current_streak'], 'yesterday still counts before today\'s first play');
});

/* ---------- Silo: numbers from Silo itself ---------- */

function siloStats(array $config = []): PaywarrStats
{
    global $STUB;

    return stats($config + ['silo_url' => $STUB . '/silo', 'silo_api_key' => 'sa_full']);
}

/** One play in Silo's playback history (AdminPlaybackHistoryEntry). */
function siloPlay(string $user, string $item, string $title, string $endedAt, array $extra = []): array
{
    return $extra + [
        'session_id' => 's-' . $item . $endedAt, 'user_id' => $user, 'username' => 'u' . $user, 'profile_id' => 'p', 'profile_name' => 'P',
        'media_item_id' => $item, 'media_file_id' => '1', 'media_title' => $title, 'media_type' => 'movie', 'play_method' => 'direct_play',
        'started_at' => date('Y-m-d\TH:i:s.000\Z', strtotime($endedAt) - 3600), 'ended_at' => $endedAt, 'watched_seconds' => 3000, 'duration_seconds' => 6000.0,
        'completed' => false, 'played_seconds' => 2400.0, 'series_title' => '', 'season_number' => null, 'episode_number' => null, 'measured' => true,
        'start_position_seconds' => 0, 'paused_seconds' => 0, 'measured_since' => null, 'measured_until' => null, 'client_ip' => null, 'device_id' => 'd',
        'device_platform' => 'tvOS', 'client_name' => 'Silo for Apple TV', 'client_version' => '1', 'video_decision' => 'direct', 'audio_decision' => 'direct',
        'end_reason' => 'stopped', 'lifecycle_seq' => 1,
    ];
}

/** Account 7 is the customer on Silo; account 8 is somebody else. */
function siloWithCustomers(): void
{
    $episode = fn (string $id, int $n, string $at, bool $done) => siloPlay('7', $id, "Episode $n", $at, ['media_type' => 'episode', 'series_title' => 'Glass Harbor', 'season_number' => 1, 'episode_number' => $n, 'completed' => $done]);
    stub('/_test/set', ['silo' => [
        'summary' => [
            '7' => ['7' => ['plays' => 3, 'watched_seconds' => 7200], '30' => ['plays' => 9, 'watched_seconds' => 36000], '365' => ['plays' => 40, 'watched_seconds' => 360000]],
            '8' => ['7' => ['plays' => 99, 'watched_seconds' => 999999], '30' => ['plays' => 99, 'watched_seconds' => 999999], '365' => ['plays' => 99, 'watched_seconds' => 999999]],
        ],
        'history' => [
            '7' => [
                $episode('ep-3', 3, '2026-11-13T21:00:00.000Z', false),
                siloPlay('7', 'mov-1', 'Arrival Point', '2026-11-12T21:00:00.000Z', ['completed' => true, 'video_decision' => 'transcode', 'client_name' => null, 'device_platform' => 'Android']),
                $episode('ep-2', 2, '2026-11-11T21:00:00.000Z', true),
                $episode('ep-1', 1, '2026-11-10T21:00:00.000Z', true),
                siloPlay('7', 'mov-2', 'Half Film', '2026-11-09T21:00:00.000Z'),
            ],
            '8' => [siloPlay('8', 'mov-9', 'Secret of 8', '2026-11-13T22:00:00.000Z', ['completed' => true])],
        ],
        'items' => [
            'ep-3' => ['type' => 'episode', 'title' => 'Episode 3', 'series_id' => 'ser-1', 'series_poster_url' => '/img/glass-harbor.jpg'],
            'ser-1' => ['type' => 'series', 'title' => 'Glass Harbor', 'episode_count' => 10, 'genres' => ['Drama', 'Mystery'], 'poster_url' => '/img/glass-harbor.jpg'],
            'mov-1' => ['type' => 'movie', 'title' => 'Arrival Point', 'genres' => ['Sci-Fi'], 'poster_url' => 'http://127.0.0.1:' . parse_url($GLOBALS['STUB'], PHP_URL_PORT) . '/s3/arrival.jpg?sig=x'],
            'mov-2' => ['type' => 'movie', 'title' => 'Half Film', 'genres' => ['Drama']],
        ],
    ]]);
}

test('silo: a Silo customer\'s numbers come from Silo, and only their own', function () {
    siloWithCustomers();
    $stats = siloStats()->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
    same('ok', $stats['state'], 'found');
    same([['plays' => 3, 'watch_time_ms' => 7200000], ['plays' => 40, 'watch_time_ms' => 360000000]], [$stats['windows']['last_7'], $stats['windows']['all_time']], 'Silo\'s watch summary');
    same('year', $stats['all_time'], 'Silo keeps a year, and the page says so');
    same(['Glass Harbor', 'Arrival Point', 'Half Film'], array_map(fn ($p) => $p['show'] ?: $p['title'], $stats['recent']), 'each show or movie once, newest first');
    same([1, 2], [$stats['movies_watched'], $stats['episodes_watched']], 'only finished movies and episodes count');
    same([['Glass Harbor', 3, 2, 10]], array_map(fn ($s) => [$s['title'], $s['plays'], $s['watched'], $s['episodes']], $stats['shows']), 'the show, with its size from Silo\'s catalog');
    same(['Drama' => 4, 'Mystery' => 3, 'Sci-Fi' => 1], $stats['genres'], 'genres from the catalog, weighted by plays');
    same(['Silo for Apple TV' => 4, 'Android' => 1], $stats['insights']['devices'], 'the app, or else the platform');
    same(80, $stats['insights']['direct_share'], 'direct play from video_decision');

    $asked = array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], 'silo:/api/v2/admin'));
    ok(!array_filter($asked, fn ($r) => str_contains($r['path'], '/users/8/') || ($r['query']['user_id'] ?? '7') !== '7'), 'Silo is never asked about another account: ' . json_encode(array_column($asked, 'path')));
    ok(!array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], '/users')), 'Tracearr is not asked about a Silo customer');
});

test('silo: plays of other accounts are dropped even if Silo ignored the filter', function () {
    siloWithCustomers();
    stub('/_test/set', ['silo' => ['ignore_user_filter' => true]]);
    $stats = siloStats()->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
    ok(!in_array('Secret of 8', array_column($stats['recent'], 'title'), true), 'account 8\'s play is not shown');
});

test('silo: posters come through Paymenter, from Silo\'s signed route or object storage', function () {
    siloWithCustomers();
    $stats = siloStats()->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
    same(['silo:ep-3', 'silo:mov-1', 'silo:mov-2'], $stats['posters'], 'by content ID, never by address');
    $series = siloStats()->posterFor($stats, 1);
    same('image/jpeg', $series['type'] ?? null, 'the series poster for an episode');
    ok(str_contains($series['body'], '/silo/img/glass-harbor.jpg'), 'from Silo\'s own route');
    ok(str_contains(siloStats()->posterFor($stats, 2)['body'] ?? '', '/s3/arrival.jpg'), 'from object storage');
    same(null, siloStats()->posterFor($stats, 3), 'no poster in the catalog: none');
    $images = array_values(array_filter(state()['requests'], fn ($r) => str_starts_with($r['path'], 'image:')));
    same(['Bearer sa_full', ''], array_column($images, 'auth'), 'the key goes to Silo only, never to object storage');
    $html = siloStats()->pageHtml($stats);
    ok(!str_contains($html, 's3') && !str_contains($html, 'silo:') && !str_contains($html, '/img/'), 'no Silo or storage address in the page');
    foreach (['/img/x.svg' => 'SVG, which can carry scripts', '/img/notimage' => 'not an image (a login page)', 'file:///etc/passwd' => 'not a web address'] as $url => $what) {
        stub('/_test/set', ['silo' => ['items' => ['mov-2' => ['type' => 'movie', 'title' => 'Half Film', 'poster_url' => $url]]]]);
        same(null, siloStats()->posterFor($stats, 3), $what);
    }
});

test('silo: a key with only admin:monitor:read still gives the numbers, without posters and genres', function () {
    siloWithCustomers();
    $stats = siloStats(['silo_api_key' => 'sa_monitor'])->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
    same(['ok', 40, [], null], [$stats['state'], $stats['windows']['all_time']['plays'], $stats['genres'], $stats['shows'][0]['episodes']], 'numbers, no catalog');
    same(null, siloStats(['silo_api_key' => 'sa_monitor'])->posterFor($stats, 1), 'no poster, no error');
});

test('silo: a customer on Silo and Jellyfin gets both added up', function () {
    tracearrWithCustomers();
    siloWithCustomers();
    $stats = siloStats()->statsFor([['type' => 'jellyfin', 'username' => 'mette', 'id' => '1'], ['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
    same(['plays' => 5, 'watch_time_ms' => 3 * 3600000 + 7200000], $stats['windows']['last_7'], 'Tracearr + Silo');
    same('all', $stats['all_time'], 'Tracearr has all time');
    ok(in_array('Pilot', array_column($stats['recent'], 'title'), true) && in_array('Arrival Point', array_column($stats['recent'], 'title'), true), 'plays from both');
});

test('silo: without Silo set up, a Silo customer sees "nothing yet", not an error', function () {
    siloWithCustomers();
    same(['state' => 'no_data'], stats()->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]), 'no Silo address');
    same(['state' => 'no_data'], siloStats()->statsFor([['type' => 'silo', 'username' => 'new', 'id' => '55']]), 'nothing watched yet');
});

test('silo: Silo down or a wrong key is reported plainly', function () {
    foreach ([['silo_url' => 'http://127.0.0.1:1'], ['silo_api_key' => 'sa_wrong']] as $i => $config) {
        try {
            siloStats($config)->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
            $message = null;
        } catch (TracearrException $e) {
            $message = $e->getMessage();
        }
        ok(str_contains((string) $message, ['Could not reach Silo', 'Silo rejected the API key'][$i]), 'message: ' . $message);
    }
});

test('silo: the page says "last 12 months" for Silo, in Danish too', function () {
    siloWithCustomers();
    $stats = siloStats()->statsFor([['type' => 'silo', 'username' => 'mette', 'id' => '7']]);
    ok(str_contains(siloStats()->pageHtml($stats), 'Last 12 months') && !str_contains(siloStats()->pageHtml($stats), 'All time'), 'English');
    ok(str_contains(inLocale('da', fn () => siloStats()->pageHtml($stats)), 'Seneste 12 måneder'), 'Danish');
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
