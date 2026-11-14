<?php

/*
 * Checks "My stats" for one customer, step by step: the settings, the media
 * server users Paywarr noted on their subscriptions, what Tracearr and Silo
 * know about them, and what the page will show. Then forgets the customer's
 * kept numbers, so their next page view asks afresh.
 *
 *   su -s /bin/bash www-data -c "php /opt/paywarr-stats/tools/stats-check.php /opt/paymenter customer@example.com"
 *
 * Only reads. Never prints an API key.
 */

use App\Helpers\ExtensionHelper;
use App\Models\Extension;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Others\PaywarrStats\PaywarrStats;
use Paymenter\Extensions\Others\PaywarrStats\SiloClient;
use Paymenter\Extensions\Others\PaywarrStats\TracearrClient;

$root = rtrim($argv[1] ?? '/opt/paymenter', '/');
$email = $argv[2] ?? null;

if (!is_file($root . '/artisan') || !$email) {
    fwrite(STDERR, "Usage: php stats-check.php /path/to/paymenter customer@email\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$line = fn (string $mark, string $text) => print('  ' . str_pad($mark, 6) . $text . PHP_EOL);
$step = function (string $label, callable $fn) use ($line) {
    try {
        $result = $fn();
        $line('OK', $label . ': ' . $result);

        return $result;
    } catch (Throwable $e) {
        $line('FAIL', $label . ': ' . $e->getMessage());

        return null;
    }
};

echo PHP_EOL . '1. Paywarr Stats settings' . PHP_EOL;
$extension = Extension::where('extension', 'PaywarrStats')->first();
if (!$extension) {
    $line('FAIL', 'not installed: Admin -> Extensions -> Available Extensions -> Paywarr Stats -> Install');
    exit(1);
}
$line($extension->enabled ? 'OK' : 'FAIL', 'Enabled: ' . ($extension->enabled ? 'yes' : 'NO - turn it on and save'));
$settings = ExtensionHelper::settingsToArray($extension->settings);
$filled = [];
foreach (['tracearr' => 'Tracearr', 'silo' => 'Silo'] as $source => $name) {
    $url = trim((string) ($settings[$source . '_url'] ?? ''));
    $key = trim((string) ($settings[$source . '_api_key'] ?? ''));
    $filled[$source] = $url !== '' && $key !== '';
    $line($filled[$source] ? 'OK' : '-', $name . ': ' . ($filled[$source] ? $url . ', key filled in' : 'not set up' . ($url !== '' || $key !== '' ? ' (URL or key missing)' : '')));
}

echo PHP_EOL . "2. Customer $email" . PHP_EOL;
$user = User::where('email', $email)->first();
if (!$user) {
    $line('FAIL', 'no Paymenter user with that e-mail');
    exit(1);
}
$services = Service::where('user_id', $user->id)->with(['properties', 'product.server'])->get();
$lists = [];
foreach ($services as $service) {
    $server = $service->product?->server;
    if ($server?->extension !== 'Wizarr') {
        continue;
    }
    $backend = ExtensionHelper::settingsToArray($server->settings)['backend'] ?? 'wizarr';
    $properties = $service->properties->pluck('value', 'key')->all();
    $noted = array_filter($properties, fn ($value, $key) => $key === 'wizarr_users' || str_starts_with((string) $key, 'wizarr_users:'), ARRAY_FILTER_USE_BOTH);
    $counts = in_array($service->status, ['active', 'suspended'], true);
    $line($counts && $noted ? 'OK' : ($counts ? 'FAIL' : '-'), sprintf(
        'Subscription #%d "%s" (%s, server "%s" -> %s): %s',
        $service->id,
        $service->product?->name,
        $service->status,
        $server->name,
        $backend === 'silo' ? 'Silo' : 'Wizarr',
        $noted ? 'users noted: ' . implode(', ', array_map(fn ($k, $v) => "$k = $v", array_keys($noted), $noted)) : 'NO users noted yet',
    ));
    if ($counts && !$noted) {
        $line('', '      Paywarr notes the user once the invitation is used. Let the customer open this');
        $line('', '      subscription in the client area (Services) once, then run this again.');
    }
    if ($counts) {
        $lists[] = $properties;
    }
}
$stats = new PaywarrStats($settings);
$accounts = $stats->mediaAccounts($lists);
if (!$accounts) {
    $line('FAIL', 'no media server users to show statistics for (see above)');
    exit(1);
}

echo PHP_EOL . '3. What the media servers know' . PHP_EOL;
foreach ($accounts as $account) {
    $name = $account['type'] . ' user "' . $account['username'] . '"';
    if ($account['type'] === 'silo') {
        if (!$filled['silo']) {
            $line('FAIL', $name . ': fill in Silo URL and key in Paywarr Stats');

            continue;
        }
        $silo = new SiloClient((string) $settings['silo_url'], (string) $settings['silo_api_key']);
        $step($name . ' (Silo account ' . $account['id'] . '), last 365 days', function () use ($silo, $account) {
            $summary = $silo->watchSummary($account['id'], 365);

            return ($summary['plays'] ?? 0) . ' finished plays, ' . round(($summary['watched_seconds'] ?? 0) / 60) . ' min'
                . (($summary['plays'] ?? 0) === 0 ? ' - Silo counts a play once it has ended (stopped or closed)' : '');
        });
        $step($name . ', latest play', function () use ($silo, $account) {
            $play = $silo->history($account['id'], 1)[0] ?? null;

            return $play ? trim(($play['series_title'] ?? '') . ' ' . ($play['media_title'] ?? '')) . ', ended ' . ($play['ended_at'] ?? '?') : 'none';
        });
    } else {
        if (!$filled['tracearr']) {
            $line('FAIL', $name . ': fill in Tracearr URL and key in Paywarr Stats');

            continue;
        }
        $tracearr = new TracearrClient((string) $settings['tracearr_url'], (string) $settings['tracearr_api_key']);
        $step($name . ' in Tracearr', fn () => ($ids = $stats->matchIdentities([$account], $tracearr->users())) ? 'found (' . implode(', ', $ids) . ')' : 'NOT found - is the username the same in Tracearr?');
    }
}

echo PHP_EOL . '4. The page' . PHP_EOL;
Cache::forget('paywarr-stats:v4:' . $user->id);
$result = $step('My stats', function () use ($stats, $accounts) {
    $page = $stats->statsFor($accounts);
    if ($page['state'] !== 'ok') {
        return $page['state'] === 'no_data' ? 'Nothing watched yet' : 'No user yet';
    }

    return $page['windows']['all_time']['plays'] . ' plays, ' . count($page['recent']) . ' titles in "Recently watched"';
});
$line('', 'The customer\'s kept numbers were forgotten: their next visit to My stats asks afresh.');
echo PHP_EOL;
