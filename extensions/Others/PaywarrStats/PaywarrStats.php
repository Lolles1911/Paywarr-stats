<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Extension;
use App\Models\Service;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * Each customer's own watch statistics on a "My stats" page in the client
 * area: from Tracearr for Plex, Jellyfin and Emby, from Silo itself for
 * Silo. The customer's media server users come from Paywarr (the Wizarr
 * server extension); only those are asked about, so a customer never sees
 * anyone else's numbers.
 */
#[ExtensionMeta(
    name: 'Paywarr Stats',
    description: 'Shows each customer their own watch statistics from Tracearr or Silo, on a page in the client area.',
    version: '1.9.1',
    author: 'Paywarr',
    url: 'https://github.com/Lolles1911/Paywarr-stats',
)]
class PaywarrStats extends Extension
{
    use Concerns\People;

    /** Numbers are kept this long per customer, so page views do not hit Tracearr. */
    private const CACHE_SECONDS = 600;

    /** The administrators' overview is kept this long. */
    private const ADMIN_CACHE_SECONDS = 60;

    /** "Nothing watched yet" and "no access yet" are kept this long. */
    private const EMPTY_CACHE_SECONDS = 60;

    private ?TracearrClient $client = null;

    private ?SiloClient $silo = null;

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'tracearr_url',
                'label' => 'Tracearr URL (internal)',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'http://192.168.1.10:3000',
                'description' => 'For Plex, Jellyfin and Emby. How Paymenter reaches Tracearr. Never shown to customers. Leave empty if you only use Silo.',
            ],
            [
                'name' => 'tracearr_api_key',
                'label' => 'Tracearr API key',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
                'description' => 'Tracearr -> Settings -> Data & API -> API -> Generate key (starts with "trr_pub_"). Needs the Paywarr (Wizarr) server extension for the customers\' media server users.',
            ],
            [
                'name' => 'silo_url',
                'label' => 'Silo URL (internal)',
                'type' => 'text',
                'required' => false,
                'placeholder' => 'http://192.168.1.10:8090',
                'description' => 'For customers on Silo (a Silo server in Paywarr). Silo records plays itself, so no Tracearr is needed for them. Leave empty without Silo.',
            ],
            [
                'name' => 'silo_api_key',
                'label' => 'Silo API key',
                'type' => 'password',
                'required' => false,
                'encrypted' => true,
                'description' => 'Silo -> Admin -> API Keys (starts with "sa_"). Full access gives posters and genres; a key with only admin:monitor:read gives the rest. The Paywarr key can be reused.',
            ],
            [
                'name' => 'remind_inactive_days',
                'label' => 'Remind customers who watched nothing for (days)',
                'type' => 'number',
                'required' => false,
                'validation' => 'nullable|integer|min:3|max:90',
                'description' => 'E.g. 14. A paying customer who hasn\'t watched anything for that long gets a friendly mail, and a private Discord message if they connected Discord (sent through Paywarr 1.25 or newer). At most once a month per customer; checked daily. Empty: off.',
            ],
        ];
    }

    public function boot()
    {
        // Daily: customers who watch nothing are reminded (when switched on).
        $register = function (Schedule $schedule) {
            $schedule->call(fn () => $this->remindInactive())->dailyAt('11:17')->name('paywarr-stats-remind-inactive')->withoutOverlapping();
        };
        if (function_exists('app') && method_exists(app(), 'afterResolving')) {
            app()->afterResolving(Schedule::class, $register);
            if (app()->resolved(Schedule::class)) {
                $register(app()->make(Schedule::class));
            }
        }

        require __DIR__ . '/routes.php';

        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('My stats'),
            'route' => 'extensions.paywarr-stats',
            'icon' => 'ri-bar-chart-2',
            'priority' => 25,
            'condition' => Auth::check(),
        ]);
        // "My stats" in the top bar too.
        Event::listen('navigation', fn () => [
            'name' => self::t('My stats'),
            'url' => route('extensions.paywarr-stats'),
            'icon' => 'ri-bar-chart-2',
            'priority' => 11,
            'condition' => Auth::check(),
        ]);
        Event::listen('navigation.dashboard', fn () => [
            'name' => self::t('Stats overview'),
            'route' => 'extensions.paywarr-stats.admin',
            'icon' => 'ri-dashboard-3',
            'priority' => 26,
            'condition' => self::isAdmin(Auth::user()),
        ]);
    }

    /** Paymenter staff who may see customers' subscriptions see the overview. */
    public static function isAdmin($user): bool
    {
        return $user !== null && method_exists($user, 'hasPermission') && $user->hasPermission('admin.services.view');
    }

    /** GET /stats/admin: what all customers watch. Paymenter staff only. */
    public function adminPage()
    {
        if (!self::isAdmin(Auth::user())) {
            abort(404);
        }
        try {
            $overview = $this->overview();
        } catch (Throwable $e) {
            Log::warning('Paywarr Stats: could not load the overview: ' . $e->getMessage());
            $overview = null;
        }

        return view('layouts.app', [
            'slot' => new HtmlString($this->adminHtml($overview, rtrim(route('extensions.paywarr-stats.admin'), '/') . '/poster/')),
            'title' => self::t('Stats overview'),
        ]);
    }

    /** GET /stats/admin/poster/{number} */
    public function adminPoster($number)
    {
        if (!self::isAdmin(Auth::user())) {
            abort(404);
        }
        try {
            $image = $this->posterFor($this->overview(), (int) $number, (string) request()->query('v', ''));
        } catch (Throwable $e) {
            $image = null;
        }
        if ($image === null) {
            abort(404);
        }

        return response($image['body'], 200, [
            'Content-Type' => $image['type'],
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The overview, kept for a minute: "now playing" should be close to now. */
    public function overview(): array
    {
        $cached = Cache::get('paywarr-stats:admin:v2');
        if (is_array($cached)) {
            return $cached;
        }
        $overview = (new AdminOverview(
            $this->configured('tracearr') ? $this->client() : null,
            $this->configured('silo') ? $this->silo() : null,
            (string) (config('app.timezone') ?: 'UTC'),
        ))->gather($this->customerIndex());
        $overview['business'] = $this->business($overview);
        Cache::put('paywarr-stats:admin:v2', $overview, self::ADMIN_CACHE_SECONDS);

        return $overview;
    }

    /** Paymenter's side of the overview; null (and a line in the log) when it can't be read. */
    public function business(array $overview): ?array
    {
        try {
            $services = Service::with(['product.server', 'product.settings', 'plan', 'properties', 'user.properties', 'cancellation'])->get();
            $watching = [];
            foreach ($overview['people'] as $person) {
                if (($person['email'] ?? '') !== '' && $person['plays'] > 0) {
                    $watching[] = $person['email'];
                }
            }
            // Asking Silo and Seerr about every customer takes a moment: kept for 10 minutes.
            $requests = Cache::get('paywarr-stats:admin:requests');
            if (!is_array($requests) && $requests !== false) {
                $requests = $this->requestRows($services) ?? false;
                Cache::put('paywarr-stats:admin:requests', $requests, 600);
            }

            return (new BusinessOverview)->gather($this->businessRows($services), $this->invoiceRows(), $watching, $requests === false ? null : $requests);
        } catch (Throwable $e) {
            Log::warning('Paywarr Stats: the business part of the overview could not be read: ' . $e->getMessage());

            return null;
        }
    }

    /** Paywarr's subscriptions as BusinessOverview's rows. */
    public function businessRows(iterable $services): array
    {
        $rows = [];
        foreach ($services as $service) {
            $product = $service->product ?? null;
            if (($product?->server?->extension ?? null) !== 'Wizarr') {
                continue;
            }
            $settings = $this->settingsArray($product->settings ?? []);
            $props = $this->propertiesOf($service);
            $user = $service->user ?? null;
            $userProps = $user ? $this->propertiesOf($user) : [];
            $time = fn ($value) => $value ? (strtotime((string) $value) ?: null) : null;
            $rows[] = [
                'id' => $service->id ?? null,
                'status' => (string) ($service->status ?? ''),
                'server' => (string) ($product->server->name ?? ''),
                'product' => (string) ($product->name ?? ''),
                'price' => (float) ($service->price ?? 0),
                'quantity' => (int) ($service->quantity ?? 1),
                'currency' => (string) ($service->currency_code ?? ''),
                'plan_type' => (string) ($service->plan->type ?? ''),
                'period' => (int) ($service->plan->billing_period ?? 1),
                'unit' => (string) ($service->plan->billing_unit ?? 'month'),
                'created_at' => $time($service->created_at ?? null),
                'cancelled_at' => $time($service->cancellation->created_at ?? null) ?? $time($service->updated_at ?? null),
                'expires_at' => $time($service->expires_at ?? null),
                // A trial product, or trial days given in admin (Paywarr 1.24: Paywarr → Customers).
                'trial' => (int) ($settings['trial_days'] ?? 0) > 0 || (int) ($props['trial_days_given'] ?? 0) > 0,
                'trial_ended' => !empty($props['trial_ended_at']),
                'trial_converted' => !empty($props['trial_converted_to']),
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) ($user->email ?? '?'),
                'email' => (string) ($user->email ?? ''),
                'discord' => !empty($userProps['discord_id']),
                'discord_left' => ($left = (int) ($props['discord_left_at'] ?? 0)) > 0 ? $left : null,
                'discord_suspended' => !empty($props['discord_suspended']),
            ];
        }

        return $rows;
    }

    /** Unpaid invoices: status, total, currency, due_at. */
    private function invoiceRows(): array
    {
        if (!class_exists(\App\Models\Invoice::class)) {
            return [];
        }
        $rows = [];
        foreach (\App\Models\Invoice::with(['items', 'adjustmentNotes'])->where('status', 'pending')->get() as $invoice) {
            $rows[] = ['status' => 'pending', 'total' => (float) $invoice->total, 'currency' => (string) ($invoice->currency_code ?? ''),
                'due_at' => $invoice->due_at ? strtotime((string) $invoice->due_at) : null];
        }

        return $rows;
    }

    /**
     * Each active customer's request quota, through Paywarr (1.23 or newer);
     * null when Paywarr can't tell.
     */
    private function requestRows(iterable $services): ?array
    {
        $paywarr = 'Paymenter\\Extensions\\Servers\\Wizarr\\Wizarr';
        if (!class_exists($paywarr) || !method_exists($paywarr, 'requestQuotaOf')) {
            return null;
        }
        $wizarr = new $paywarr([]);
        $byUser = [];
        foreach ($services as $service) {
            if (($service->status ?? '') === 'active' && ($service->product?->server?->extension ?? null) === 'Wizarr' && $service->user) {
                $byUser[$service->user_id ?? $service->user->id][] = $service;
            }
        }
        $rows = [];
        foreach ($byUser as $list) {
            $user = $list[0]->user;
            try {
                $sources = $wizarr->requestsSources($user, $list);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($sources as $source) {
                try {
                    $quota = $wizarr->requestQuotaOf($source);
                } catch (Throwable $e) {
                    continue;
                }
                if ($quota === null) {
                    continue;
                }
                $rows[] = [
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) ($user->email ?? '?'),
                    'email' => (string) ($user->email ?? ''),
                    'label' => (string) ($source['label'] ?? ''),
                    'used' => array_sum(array_column($quota['parts'] ?? [], 'used')),
                    'limit' => array_sum(array_column($quota['parts'] ?? [], 'limit')),
                    'unlimited' => !empty($quota['unlimited']),
                ];
            }
        }

        return $rows;
    }

    /** key => value of a model's properties (loaded relation or query). */
    private function propertiesOf($model): array
    {
        $properties = $model->properties ?? null;
        if (is_object($properties) && method_exists($properties, 'pluck')) {
            $values = $properties->pluck('value', 'key');

            return is_array($values) ? $values : (method_exists($values, 'all') ? $values->all() : []);
        }

        return is_array($properties) ? $properties : [];
    }

    private function settingsArray($settings): array
    {
        if (class_exists(\App\Helpers\ExtensionHelper::class) && method_exists(\App\Helpers\ExtensionHelper::class, 'settingsToArray')) {
            return \App\Helpers\ExtensionHelper::settingsToArray($settings);
        }

        return is_array($settings) ? $settings : [];
    }

    /**
     * Every media server user Paywarr noted, with the customer and subscription
     * behind it: "silo|<account ID>", "<type>|<lower-case username>".
     */
    public function customerIndex(): array
    {
        $index = [];
        $services = Service::with(['properties', 'product.server', 'user'])->whereIn('status', ['active', 'suspended'])->get();
        foreach ($services as $service) {
            if ($service->product?->server?->extension !== 'Wizarr') {
                continue;
            }
            $user = $service->user;
            $customer = [
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) ($user->email ?? '?'),
                'email' => (string) ($user->email ?? ''),
                'products' => [(string) ($service->product->name ?? '')],
                'status' => (string) $service->status,
                'paid_until' => $service->expires_at ? date('Y-m-d', strtotime((string) $service->expires_at)) : null,
            ];
            foreach ($this->mediaAccounts([$service->properties->pluck('value', 'key')->all()]) as $account) {
                $key = $account['type'] === 'silo' ? 'silo|' . $account['id'] : $account['type'] . '|' . strtolower($account['username']);
                if (isset($index[$key])) {
                    // Two subscriptions on one user: both products, the better status.
                    $index[$key]['products'] = array_values(array_unique([...$index[$key]['products'], ...$customer['products']]));
                    $index[$key]['status'] = in_array('active', [$index[$key]['status'], $customer['status']], true) ? 'active' : $customer['status'];
                    $index[$key]['paid_until'] = max($index[$key]['paid_until'], $customer['paid_until']);
                } else {
                    $index[$key] = $customer;
                }
            }
        }

        return $index;
    }

    /**
     * Daily: paying customers who watched nothing for "remind_inactive_days"
     * days get a reminder through Paywarr (mail, and Discord when connected),
     * at most once a month. New customers (younger than that) are left alone,
     * and so is anyone when Tracearr or Silo can't tell.
     *
     * @param  callable|null  $notify  for tests: fn (Service, string $title, string $text, array $button)
     * @return int[] the services reminded
     */
    public function remindInactive(?iterable $services = null, ?int $now = null, ?callable $notify = null): array
    {
        $days = (int) ($this->config('remind_inactive_days') ?? 0);
        if ($days < 3) {
            return [];
        }
        $now ??= time();
        $since = $now - $days * 86400;
        $paywarr = 'Paymenter\\Extensions\\Servers\\Wizarr\\Wizarr';
        $notify ??= function (Service $service, string $title, string $text, array $button) use ($paywarr) {
            if (!class_exists($paywarr) || !method_exists($paywarr, 'notifyCustomer')) {
                return false;
            }

            return (new $paywarr([]))->forService($service)->notifyCustomer($service, $title, $text, $button) !== [];
        };
        $services ??= Service::with(['properties', 'product.server', 'product.settings', 'user.properties'])->whereIn('status', ['active'])->get();

        // Who watched since then: Tracearr by name, Silo per account.
        $watched = null;
        if ($this->configured('tracearr')) {
            try {
                $watched = [];
                foreach ($this->client()->history($since) as $play) {
                    $watched[strtolower((string) ($play['server_type'] ?? '')) . '|' . strtolower((string) ($play['user']['username'] ?? ''))] = true;
                }
            } catch (Throwable $e) {
                Log::warning('Paywarr Stats: no inactivity reminders today, Tracearr did not answer: ' . $e->getMessage());

                return [];
            }
        }

        $reminded = [];
        foreach ($services as $service) {
            if (($service->status ?? '') !== 'active' || ($service->product?->server?->extension ?? null) !== 'Wizarr') {
                continue;
            }
            $settings = $this->settingsArray($service->product->settings ?? []);
            $props = $this->propertiesOf($service);
            if ((int) ($settings['trial_days'] ?? 0) > 0 || (int) ($props['trial_days_given'] ?? 0) > 0 || (int) ($settings['gift_months'] ?? 0) > 0 || (int) ($settings['extra_requests'] ?? 0) > 0) {
                continue; // trials, gift cards and top-ups
            }
            $created = $service->created_at ? strtotime((string) $service->created_at) : null;
            if ($created !== null && $created > $since) {
                continue; // too new to have missed anything
            }
            $accounts = $this->mediaAccounts([$props]);
            if (!$accounts) {
                continue; // no user yet: Paywarr's own reminder covers that
            }
            $active = false;
            $unknown = false;
            foreach ($accounts as $account) {
                if ($account['type'] === 'silo') {
                    if (!$this->configured('silo')) {
                        $unknown = true;

                        continue;
                    }
                    try {
                        $summary = $this->silo()->watchSummary((string) $account['id'], $days);
                        $active = $active || (int) ($summary['plays'] ?? 0) > 0;
                    } catch (Throwable $e) {
                        $unknown = true;
                    }
                } elseif ($watched === null) {
                    $unknown = true;
                } else {
                    $active = $active || isset($watched[$account['type'] . '|' . strtolower($account['username'])]);
                }
            }
            $user = $service->user;
            $last = (int) ($this->propertiesOf($user)['paywarr_stats_reminded'] ?? 0);
            if ($active || $unknown || !$user || $now - $last < 30 * 86400) {
                continue;
            }
            $product = (string) ($service->product->name ?? '');
            $sent = $notify($service, self::t('We miss you'),
                self::t('You haven\'t watched anything on :product for :days days. Is something not working? Tell us with "Report a problem" or write to us. And there is plenty new to watch.', ['product' => $product, 'days' => $days]),
                [self::t('Open My stats'), $this->statsUrl()]);
            if ($sent) {
                $user->properties()->updateOrCreate(['key' => 'paywarr_stats_reminded'], ['name' => 'Paywarr Stats: reminded (watched nothing)', 'value' => (string) $now]);
                $reminded[] = (int) $service->id;
            }
        }

        return $reminded;
    }

    private function statsUrl(): string
    {
        try {
            return route('extensions.paywarr-stats');
        } catch (Throwable $e) {
            return '/stats';
        }
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
            $image = $this->posterFor($this->statsForUser(Auth::user()), (int) $number, (string) request()->query('v', ''));
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
     * Tracearr or Silo; null when there is none.
     *
     * $key is the poster's fingerprint from the page's address. Numbers
     * change when the list does (a new play, a new minute of the overview),
     * so the fingerprint decides: the browser keeps posters for a while, and
     * a kept address must never show another title's poster.
     *
     * @return array{body: string, type: string}|null
     */
    public function posterFor(array $stats, int $number, string $key = ''): ?array
    {
        $path = $stats['posters'][$number - 1] ?? null;
        if ($key !== '') {
            $path = null;
            foreach ($stats['posters'] ?? [] as $candidate) {
                if (self::posterKey((string) $candidate) === $key) {
                    $path = $candidate;
                }
            }
        } elseif ($number < 1) {
            return null;
        }
        if (!is_string($path) || $path === '') {
            return null;
        }

        return str_starts_with($path, 'silo:') ? $this->silo()->poster(substr($path, 5)) : $this->client()->poster($path);
    }

    /** A poster's fingerprint, for its address: says which title, tells nothing about where it lives. */
    public static function posterKey(string $path): string
    {
        return substr(hash('sha256', $path), 0, 12);
    }

    /** The address of poster $number of these statistics. */
    private function posterSrc(string $base, array $stats, int $number): string
    {
        return $base . $number . '?v=' . self::posterKey((string) ($stats['posters'][$number - 1] ?? ''));
    }

    /** The customer's statistics, kept for CACHE_SECONDS. */
    private function statsForUser($user): array
    {
        // The version: entries stored by an older version have another shape.
        $key = 'paywarr-stats:v4:' . $user->id;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $stats = (function () use ($user) {
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
        })();
        // "Nothing yet" is asked again sooner, so a first play shows up soon.
        Cache::put($key, $stats, ($stats['state'] ?? '') === 'ok' ? self::CACHE_SECONDS : self::EMPTY_CACHE_SECONDS);

        return $stats;
    }

    /**
     * The customer's media server users, as Paywarr records them on each
     * subscription: "wizarr_users" (before 1.11) and "wizarr_users:<server ID>",
     * each "id:username:type,...".
     *
     * @param  array<int, array<string, mixed>>  $propertyLists  one per subscription
     * @return array<int, array{type: string, username: string, id: string}>
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
                    [$id, $username, $type] = array_pad(explode(':', $entry, 3), 3, '');
                    $username = rawurldecode($username);
                    if ($username !== '') {
                        // The ID is the media server's own (Silo: the account ID); Tracearr matches by name.
                        $accounts[strtolower(rawurldecode($type)) . '|' . strtolower($username)] = ['type' => strtolower(rawurldecode($type)), 'username' => $username, 'id' => $id];
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
        // Silo accounts are asked of Silo itself; the rest of Tracearr.
        $silo = array_values(array_filter($accounts, fn ($account) => $account['type'] === 'silo'));
        $others = array_values(array_filter($accounts, fn ($account) => $account['type'] !== 'silo'));
        $parts = [];
        if ($others && $this->configured('tracearr')) {
            $ids = $this->matchIdentities($others, $this->client()->users());
            if ($ids) {
                $parts[] = $this->tracearrPart($ids);
            }
        }
        if ($silo && $this->configured('silo')) {
            $part = $this->siloPart(array_values(array_filter(array_column($silo, 'id'), 'ctype_digit')));
            if ($part['plays'] || $part['windows']['all_time']['plays'] > 0) {
                $parts[] = $part;
            }
        }
        if (!$parts) {
            return ['state' => 'no_data'];
        }

        $windows = ['last_7' => ['plays' => 0, 'watch_time_ms' => 0], 'last_30' => ['plays' => 0, 'watch_time_ms' => 0], 'all_time' => ['plays' => 0, 'watch_time_ms' => 0]];
        $genres = [];
        $plays = [];
        $shows = [];
        $movies = 0;
        foreach ($parts as $part) {
            foreach ($windows as $key => $sum) {
                $windows[$key]['plays'] += $part['windows'][$key]['plays'];
                $windows[$key]['watch_time_ms'] += $part['windows'][$key]['watch_time_ms'];
            }
            foreach ($part['genres'] as $genre => $count) {
                if ($count > 0) {
                    $genres[$genre] = ($genres[$genre] ?? 0) + $count;
                }
            }
            array_push($plays, ...$part['plays']);
            // The same show on two servers counts once, with the furthest progress.
            foreach ($part['shows'] as $show) {
                $key = strtolower($show['title']);
                $known = $shows[$key] ?? ['title' => $show['title'], 'plays' => 0, 'watched' => 0, 'episodes' => null, 'last' => ''];
                $known['plays'] += $show['plays'];
                $known['watched'] = max($known['watched'], $show['watched']);
                $known['episodes'] = $show['episodes'] !== null ? max((int) $known['episodes'], $show['episodes']) : $known['episodes'];
                $known['last'] = max($known['last'], $show['last']);
                $shows[$key] = $known;
            }
            $movies += $part['movies'];
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
            // Silo keeps a year of totals: "all time" is the last 12 months when only Silo answered.
            'all_time' => count(array_filter($parts, fn ($part) => !empty($part['year_only']))) === count($parts) ? 'year' : 'all',
        ];
    }

    /** One customer's numbers from Tracearr, for the identities found there. */
    private function tracearrPart(array $ids): array
    {
        $part = ['windows' => [], 'genres' => [], 'plays' => [], 'shows' => [], 'movies' => 0];
        foreach (['last_7', 'last_30', 'all_time'] as $key) {
            $part['windows'][$key] = ['plays' => 0, 'watch_time_ms' => 0];
        }
        foreach ($ids as $id) {
            $stats = $this->client()->userStats($id);
            foreach ($part['windows'] as $key => $sum) {
                $part['windows'][$key]['plays'] += (int) ($stats['windows'][$key]['plays'] ?? 0);
                $part['windows'][$key]['watch_time_ms'] += (int) ($stats['windows'][$key]['watch_time_ms'] ?? 0);
            }
            foreach ($stats['top_genres'] ?? [] as $genre) {
                $part['genres'][(string) $genre['genre']] = ($part['genres'][(string) $genre['genre']] ?? 0) + (int) $genre['plays'];
            }
            foreach ($this->client()->userHistory($id) as $play) {
                $part['plays'][] = $this->play($play);
            }
            foreach ($this->client()->watchedMedia($id, 'show') as $show) {
                if ((string) ($show['title'] ?? '') !== '') {
                    $part['shows'][] = [
                        'title' => (string) $show['title'],
                        'plays' => (int) ($show['plays'] ?? 0),
                        'watched' => (int) ($show['episodes_watched'] ?? 0),
                        'episodes' => isset($show['episode_count']) ? (int) $show['episode_count'] : null,
                        'last' => (string) ($show['last_watched_day'] ?? ''),
                    ];
                }
            }
            foreach ($this->client()->watchedMedia($id, 'movie') as $movie) {
                if (($movie['watched_state'] ?? '') === 'watched') {
                    $part['movies']++;
                }
            }
        }

        return $part;
    }

    /**
     * One customer's numbers from Silo, for their Silo accounts. Totals come
     * from Silo's watch summary (at most a year back); everything else from
     * the latest 200 plays, with titles, genres and series sizes from Silo's
     * catalog where the key may read it.
     */
    private function siloPart(array $accountIds): array
    {
        $part = ['windows' => [], 'genres' => [], 'plays' => [], 'shows' => [], 'movies' => 0, 'year_only' => true];
        $raw = [];
        foreach (['last_7' => 7, 'last_30' => 30, 'all_time' => 365] as $key => $days) {
            $part['windows'][$key] = ['plays' => 0, 'watch_time_ms' => 0];
            foreach ($accountIds as $id) {
                $summary = $this->silo()->watchSummary($id, $days);
                $part['windows'][$key]['plays'] += (int) ($summary['plays'] ?? 0);
                $part['windows'][$key]['watch_time_ms'] += (int) round(1000 * (float) ($summary['watched_seconds'] ?? 0));
            }
        }
        foreach ($accountIds as $id) {
            array_push($raw, ...$this->silo()->history($id));
        }
        usort($raw, fn ($a, $b) => strcmp((string) ($b['started_at'] ?? ''), (string) ($a['started_at'] ?? '')));

        $movies = [];   // content ID => plays
        $series = [];   // lower-case title => details
        foreach ($raw as $play) {
            $part['plays'][] = $this->siloPlay($play);
            $item = (string) ($play['media_item_id'] ?? '');
            $title = (string) ($play['series_title'] ?? '');
            if (($play['media_type'] ?? '') === 'movie' && $item !== '') {
                $movies[$item] ??= ['plays' => 0, 'completed' => false];
                $movies[$item]['plays']++;
                $movies[$item]['completed'] = $movies[$item]['completed'] || !empty($play['completed']);
            } elseif ($title !== '') {
                $key = strtolower($title);
                $series[$key] ??= ['title' => $title, 'plays' => 0, 'episodes_seen' => [], 'last' => '', 'episode_id' => $item];
                $series[$key]['plays']++;
                if (!empty($play['completed'])) {
                    $series[$key]['episodes_seen'][($play['season_number'] ?? '?') . 'x' . ($play['episode_number'] ?? '?')] = true;
                }
                $series[$key]['last'] = max($series[$key]['last'], substr((string) ($play['ended_at'] ?? ''), 0, 10));
            }
        }
        $part['movies'] = count(array_filter($movies, fn ($movie) => $movie['completed']));

        // Catalog details for what the page shows: the latest shows and movies.
        uasort($series, fn ($a, $b) => strcmp($b['last'], $a['last']));
        $n = 0;
        foreach ($series as $show) {
            $episodes = null;
            if ($n++ < 6 && ($episode = $this->silo()->item($show['episode_id'])) && ($whole = $this->silo()->item((string) ($episode['series_id'] ?? '')))) {
                $episodes = isset($whole['episode_count']) ? (int) $whole['episode_count'] : null;
                foreach ($whole['genres'] ?? [] as $genre) {
                    $part['genres'][(string) $genre] = ($part['genres'][(string) $genre] ?? 0) + $show['plays'];
                }
            }
            $part['shows'][] = ['title' => $show['title'], 'plays' => $show['plays'], 'watched' => count($show['episodes_seen']), 'episodes' => $episodes, 'last' => $show['last']];
        }
        foreach (array_slice($movies, 0, 15, true) as $id => $movie) {
            foreach ($this->silo()->item((string) $id)['genres'] ?? [] as $genre) {
                $part['genres'][(string) $genre] = ($part['genres'][(string) $genre] ?? 0) + $movie['plays'];
            }
        }

        return $part;
    }

    /** One play from Silo's playback history, as the page needs it. */
    private function siloPlay(array $play): array
    {
        $started = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
        $ended = strtotime((string) ($play['ended_at'] ?? '')) ?: $started;
        $duration = (float) ($play['duration_seconds'] ?? 0);
        // played_seconds is real watching time, where Silo measured it; else the session's length.
        $seconds = !empty($play['measured']) && isset($play['played_seconds']) ? (float) $play['played_seconds'] : max(0, $ended - $started);
        if ($duration > 0) {
            $seconds = min($seconds, $duration);
        }
        $item = (string) ($play['media_item_id'] ?? '');

        return [
            'title' => (string) ($play['media_title'] ?? ''),
            'show' => (string) ($play['series_title'] ?? ''),
            'season' => $play['season_number'] ?? null,
            'episode' => $play['episode_number'] ?? null,
            'year' => null,
            'type' => (string) ($play['media_type'] ?? ''),
            'server' => 'Silo',
            'started_at' => (string) ($play['started_at'] ?? ''),
            'percent' => !empty($play['completed']) ? 100 : ($duration > 0 ? (int) round(100 * min(1, (float) ($play['watched_seconds'] ?? 0) / $duration)) : null),
            'watched_ms' => (int) round(1000 * $seconds),
            'device' => trim((string) ($play['client_name'] ?? '')) ?: trim((string) ($play['device_platform'] ?? '')),
            'transcode' => isset($play['video_decision']) ? $play['video_decision'] === 'transcode' : null,
            // Served to the customer by number; "silo:" tells posterFor() where it lives.
            'poster_path' => $item !== '' ? 'silo:' . $item : '',
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
            // Every play counts in the patterns, a short one as a minute.
            $minutes = max(1, intdiv(max(0, $play['watched_ms']), 60000));
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

        $poster = function (int $number, string $title, string $class, string $extra = '') use ($e, $posterBase, $stats): string {
            $letter = $e(mb_strtoupper(mb_substr(trim($title) ?: '?', 0, 1)));

            return '<span class="' . $class . '"><span class="ps-letter">' . $letter . '</span>'
                . ($number > 0 ? '<img loading="lazy" alt="" src="' . $e($this->posterSrc($posterBase, $stats, $number)) . '" onerror="this.remove()">' : '') . $extra . '</span>';
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
        $last = $stats['recent'][0] ?? null;
        $html .= '<section class="ps-hero">'
            . ($last && $last['poster'] > 0 ? '<img class="ps-hero-bg" alt="" src="' . $e($this->posterSrc($posterBase, $stats, $last['poster'])) . '" onerror="this.remove()">' : '')
            . '<div class="ps-hero-main"><div class="ps-label">' . $e(($stats['all_time'] ?? 'all') === 'year' ? self::t('Last 12 months') : self::t('All time')) . '</div>'
            . '<div class="ps-big">' . $e($this->duration($all['watch_time_ms'])) . '</div>'
            . ($all['watch_time_ms'] >= 86400000 ? '<div class="ps-muted">' . $e(self::t('That is :count days of watching', ['count' => number_format($all['watch_time_ms'] / 86400000, 1, self::t('.'), '')])) . '</div>' : '')
            . '<div class="ps-chips">' . implode('', array_map(fn ($c) => '<span class="ps-chip">' . $e($c) . '</span>', $chips)) . '</div></div>';
        if ($last) {
            $lastTitle = $last['show'] !== '' ? $last['show'] : $last['title'] . ($last['year'] ? ' (' . $last['year'] . ')' : '');
            $lastSub = $last['show'] !== '' ? ($last['season'] !== null && $last['episode'] !== null ? sprintf('S%02dE%02d', $last['season'], $last['episode']) . ' · ' : '') . $last['title'] : '';
            $when = ($ts = strtotime($last['started_at'])) ? date(self::t('M j, Y'), $ts) : '';
            $html .= '<div class="ps-spot">' . $poster($last['poster'], $lastTitle, 'ps-spot-cover')
                . '<div class="ps-grow"><div class="ps-label">' . $e(self::t('Last watched')) . '</div>'
                . '<div class="ps-title ps-clamp">' . $e($lastTitle) . '</div>'
                . ($lastSub !== '' ? '<div class="ps-muted ps-small ps-clip">' . $e($lastSub) . '</div>' : '')
                . '<div class="ps-muted ps-tiny">' . $e(implode(' · ', array_filter([$last['server'], $when]))) . '</div>'
                . ($last['percent'] !== null && $last['percent'] < 95 ? $bar($last['percent'] / 100) : '') . '</div></div>';
        }
        $html .= '</section>';

        // Periods
        $html .= '<div class="ps-periods">';
        foreach (['last_7' => [self::t('Last 7 days'), 7], 'last_30' => [self::t('Last 30 days'), 30]] as $key => [$label, $daysInPeriod]) {
            $window = $stats['windows'][$key];
            $html .= '<div class="ps-card"><div class="ps-label">' . $e($label) . '</div>'
                . '<div class="ps-value">' . $e($this->duration($window['watch_time_ms'])) . '</div>'
                . '<div class="ps-muted">' . $e($window['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $window['plays']]))
                . ($window['watch_time_ms'] >= $daysInPeriod * 60000 ? ' · ' . $e(self::t(':time a day', ['time' => $this->duration(intdiv($window['watch_time_ms'], $daysInPeriod))])) : '') . '</div></div>';
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
            $html .= '<section class="ps-card"><h2>' . $this->icon('when-you-watch') . $e(self::t('When you watch')) . '</h2><div class="ps-week">';
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
                $html .= '<h2>' . $this->icon('favourite-genres') . $e(self::t('Favourite genres')) . '</h2>';
                foreach ($stats['genres'] as $genre => $count) {
                    $html .= '<div class="ps-line"><span>' . $e($genre) . '</span>' . $bar($count / max(1, $top)) . '<span class="ps-tiny">' . $e($count) . '</span></div>';
                }
            }
            if ($insights['devices']) {
                $html .= '<h2>' . $this->icon('where-you-watch') . $e(self::t('Where you watch')) . '</h2>';
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
            $html .= '<h2 class="ps-h">' . $this->icon('your-shows') . $e(self::t('Your shows')) . '</h2><div class="ps-shows">';
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
            $html .= '<h2 class="ps-h">' . $this->icon('recently-watched') . $e(self::t('Recently watched')) . '</h2><div class="ps-posters">';
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
            $html .= '<p class="ps-muted ps-tiny ps-foot">' . $e($insights['sample'] === 1 ? self::t('Patterns are based on your latest play.') : self::t('Patterns are based on your latest :count plays.', ['count' => $insights['sample']])) . '</p>';
        }

        return $html . '</div>';
    }

    /** The administrators' overview page body. */
    public function adminHtml(?array $overview, string $posterBase = '/stats/admin/poster/'): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $html = '<div id="paywarr-stats" class="container mt-14"><style>' . $this->css() . $this->adminCss() . '</style>'
            . '<header class="ps-head"><h1>' . $e(self::t('Stats overview')) . '</h1>'
            . '<p class="ps-muted">' . $e(self::t('Everyone on your servers, from Silo and Tracearr, and your subscriptions in Paymenter. Only Paymenter staff can see this page.')) . '</p></header>';
        if ($overview === null) {
            return $html . '<p class="ps-card ps-note">' . $e(self::t('The overview can\'t be loaded right now. The reason is in Paymenter\'s log.')) . '</p></div>';
        }
        foreach ($overview['errors'] as $error) {
            $html .= '<p class="ps-card ps-note ps-warn">' . $e($error) . '</p>';
        }
        $poster = function (int $number, string $title, string $class) use ($e, $posterBase, $overview): string {
            return '<span class="' . $class . '"><span class="ps-letter">' . $e(mb_strtoupper(mb_substr(trim($title) ?: '?', 0, 1))) . '</span>'
                . ($number > 0 ? '<img loading="lazy" alt="" src="' . $e($this->posterSrc($posterBase, $overview, $number)) . '" onerror="this.remove()">' : '') . '</span>';
        };
        $bar = fn (float $share) => '<span class="ps-bar"><span style="width:' . max(2, min(100, (int) round($share * 100))) . '%"></span></span>';
        $customer = function (array $who) use ($e): string {
            if (!empty($who['unknown'])) {
                return '<div class="ps-title">' . $e($who['name']) . '</div><div class="ps-tiny"><span class="ps-badge ps-badge-warn">' . $e(self::t('No subscription')) . '</span></div>';
            }
            $status = $who['status'] === 'active' ? '<span class="ps-badge ps-badge-ok">' . $e(self::t('Active')) . '</span>' : '<span class="ps-badge ps-badge-warn">' . $e(self::t('Suspended')) . '</span>';

            return '<div class="ps-title">' . $e($who['name']) . '</div><div class="ps-tiny">' . $status . ' ' . $e(implode(', ', array_filter($who['products'])))
                . ($who['paid_until'] ? ' · ' . $e(self::t('until :date', ['date' => date(self::t('M j, Y'), strtotime($who['paid_until']))])) : '') . '</div>';
        };

        $business = $overview['business'] ?? null;
        $servers = $overview['servers'] ?? [];
        $section = fn (string $icon, string $title) => '<h2 class="ps-h">' . $this->icon($icon) . $e($title) . '</h2>';
        $cardsRow = function (array $cards) use ($e): string {
            $html = '<div class="ps-periods ps-four">';
            foreach ($cards as [$label, $value, $note]) {
                $html .= '<div class="ps-card"><div class="ps-label">' . $e($label) . '</div><div class="ps-value">' . $e($value) . '</div><div class="ps-muted ps-small">' . $e($note) . '</div></div>';
            }

            return $html . '</div>';
        };
        // One colour per server, the same in every chart and list.
        $palette = ['hsl(var(--color-primary))', 'hsl(190 80% 55%)', 'hsl(40 95% 60%)', 'hsl(145 60% 50%)', 'hsl(330 75% 65%)', 'hsl(260 60% 70%)'];
        $colours = [];
        foreach ($servers as $i => $server) {
            $colours[$server['name']] = $palette[$i % count($palette)];
        }
        $colour = fn (string $name) => $colours[$name] ?? 'hsl(var(--color-base) / .5)';

        // Business
        if ($business !== null) {
            $revenue = $business['revenue'] ? implode(' + ', array_map(fn ($amount, $currency) => $this->money($amount, $currency), $business['revenue'], array_keys($business['revenue']))) : $this->money(0, '');
            $unpaid = $business['unpaid'];
            $trials = $business['trials'];
            $html .= $cardsRow([
                [self::t('Monthly revenue'), $revenue, self::t('Active paid subscriptions, per month')],
                [self::t('Paying customers'), (string) $business['paying'], ($business['growth']['new'] === 1 ? self::t('1 new subscription in 30 days') : self::t(':count new subscriptions in 30 days', ['count' => $business['growth']['new']]))],
                [self::t('Trials running'), (string) $trials['running'], $trials['rate'] === null ? self::t('No trial has ended yet') : self::t(':rate% became paying (:converted of :total)', ['rate' => $trials['rate'], 'converted' => $trials['converted'], 'total' => $trials['converted'] + $trials['ended']])],
                [self::t('Unpaid invoices'), (string) $unpaid['count'], $unpaid['count'] === 0 ? self::t('All paid') : implode(' + ', array_map(fn ($amount, $currency) => $this->money($amount, $currency), $unpaid['amounts'], array_keys($unpaid['amounts']))) . ($unpaid['overdue'] ? ' · ' . self::t(':count overdue', ['count' => $unpaid['overdue']]) : '')],
            ]);
        }

        // Watching
        $streams = $overview['streams'];
        $converting = count(array_filter($streams, fn ($s) => $s['transcode']));
        $plays = 0;
        foreach ($overview['days'] as $perServer) {
            $plays += array_sum($perServer);
        }
        $watch = array_sum(array_column($overview['people'], 'watch_ms'));
        $watching = count(array_filter($overview['people'], fn ($p) => empty($p['unknown']) && $p['plays'] > 0));
        $live = array_count_values(array_filter(array_column($streams, 'server')));
        uksort($live, fn ($a, $b) => [$live[$b], $a] <=> [$live[$a], $b]); // most first, then by name
        $perServer = implode(', ', array_map(fn ($name, $n) => $n . ' ' . $name, array_keys($live), $live));
        $html .= $cardsRow([
            [self::t('Streaming now'), (string) count($streams), implode(' · ', array_filter([$perServer, $converting ? self::t(':count converting', ['count' => $converting]) : ($streams ? self::t('all played directly') : '')]))],
            [self::t('Plays'), number_format($plays, 0, self::t('.'), self::t(',')), self::t('Last 30 days, everyone')],
            [self::t('Watch time'), $this->duration($watch), self::t('Last 30 days, everyone')],
            [self::t('Customers watching'), $watching . ' / ' . $overview['paying'], self::t('of the active subscriptions, last 30 days')],
        ]);

        // Subscriptions per server (Paymenter)
        if ($business !== null && $business['servers']) {
            $html .= $section('where-you-watch', self::t('Subscriptions per server')) . '<div class="ps-card ps-table ps-scroll"><table class="ps-tab"><thead><tr><th>' . $e(self::t('Server')) . '</th><th>' . $e(self::t('Active')) . '</th><th>' . $e(self::t('Trials')) . '</th><th>' . $e(self::t('Suspended')) . '</th><th>' . $e(self::t('Pending')) . '</th><th>' . $e(self::t('Cancelled')) . '</th><th>' . $e(self::t('Customers')) . '</th></tr></thead><tbody>';
            foreach ($business['servers'] as $row) {
                $html .= '<tr><td class="ps-title">' . $e($row['name']) . '</td><td>' . $row['active'] . '</td><td>' . $row['trials'] . '</td><td>' . $row['suspended'] . '</td><td>' . $row['pending'] . '</td><td>' . $row['cancelled'] . '</td><td>' . $row['customers'] . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }

        // Media servers (Tracearr, Silo)
        if ($servers) {
            $html .= $section('now-playing', self::t('Media servers')) . '<div class="ps-card ps-table ps-scroll"><table class="ps-tab"><thead><tr><th>' . $e(self::t('Server')) . '</th><th>' . $e(self::t('Users')) . '</th><th>' . $e(self::t('Customers')) . '</th><th>' . $e(self::t('Streaming now')) . '</th><th>' . $e(self::t('Plays, 30 days')) . '</th><th>' . $e(self::t('Watch time, 30 days')) . '</th></tr></thead><tbody>';
            foreach ($servers as $row) {
                $state = $row['historical'] ? '<span class="ps-badge">' . $e(self::t('History only')) . '</span>'
                    : ($row['status'] === 'up' ? '<span class="ps-badge ps-badge-ok">' . $e(self::t('Online')) . '</span>' : ($row['status'] === 'down' ? '<span class="ps-badge ps-badge-warn">' . $e(self::t('Offline')) . '</span>' : ''));
                $html .= '<tr><td><span class="ps-dot" style="background:' . $colour($row['name']) . '"></span><span class="ps-title">' . $e($row['name']) . '</span> ' . $state . '</td><td>' . ($row['users'] ?? '–') . '</td><td>' . $row['customers'] . '</td><td>' . $row['streams'] . '</td><td>' . $row['plays'] . '</td><td>' . $e($this->duration($row['watch_ms'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div><p class="ps-muted ps-tiny ps-gap">' . $e(self::t('A play counts once 2 minutes are watched. "History only": a server whose history was imported into Tracearr, without a live connection.')) . '</p>';
        }

        if ($business !== null) {
            // Growth and renewals
            $growth = $business['growth'];
            $html .= $section('when-you-watch', self::t('Last 30 days')) . $cardsRow([
                [self::t('New subscriptions'), (string) $growth['new'], self::t('Paid, last 30 days')],
                [self::t('New trials'), (string) $growth['new_trials'], self::t('Last 30 days')],
                [self::t('Cancelled'), (string) $growth['cancelled'], self::t('Paid, last 30 days')],
                [self::t('Renewing soon'), (string) count($business['renewals']), self::t('In the next 7 days')],
            ]);
            if ($business['renewals']) {
                $html .= '<div class="ps-card ps-table ps-gap">';
                foreach ($business['renewals'] as $row) {
                    $html .= '<div class="ps-row"><div class="ps-grow"><div class="ps-title">' . $e($row['name']) . '</div><div class="ps-tiny ps-muted">' . $e($row['product']) . '</div></div>'
                        . '<div class="ps-amount"><div class="ps-title">' . $e($this->money($row['amount'], $row['currency'])) . '</div><div class="ps-tiny ps-muted">' . $e(date(self::t('M j, Y'), $row['date'])) . '</div></div></div>';
                }
                $html .= '</div>';
            }

            // Revenue per product
            if ($business['products']) {
                $top = max(0.01, $business['products'][0]['monthly']);
                $html .= $section('favourite-genres', self::t('Revenue per product')) . '<div class="ps-card ps-table">';
                foreach ($business['products'] as $row) {
                    $html .= '<div class="ps-row"><div class="ps-grow"><div class="ps-title">' . $e($row['product']) . '</div><div class="ps-tiny ps-muted">' . $e($row['count'] === 1 ? self::t('1 subscription') : self::t(':count subscriptions', ['count' => $row['count']])) . '</div></div>'
                        . '<div class="ps-amount"><div class="ps-title">' . $e(self::t(':amount / month', ['amount' => $this->money($row['monthly'], $row['currency'])])) . '</div>' . $bar($row['monthly'] / $top) . '</div></div>';
                }
                $html .= '</div>';
            }

            // Customers
            $discord = $business['discord'];
            $requests = $business['requests'];
            $html .= $section('your-shows', self::t('Customers')) . $cardsRow(array_values(array_filter([
                [self::t('Not watching'), (string) count($business['idle']), self::t('Paying, nothing watched in 30 days')],
                [self::t('Discord connected'), $discord['connected'] . ' / ' . $business['paying'], self::t('of the paying customers')],
                [self::t('Left the Discord'), (string) count($discord['warned']), $discord['suspended'] ? self::t(':count suspended for it', ['count' => $discord['suspended']]) : self::t('Warned, not suspended yet')],
                $requests === null ? null : [self::t('Requests used'), (string) $requests['used'], self::t(':count with their limit used up', ['count' => count($requests['full'])])],
            ])));
            $people = function (array $rows, callable $right) use ($e): string {
                $html = '<div class="ps-card ps-table ps-gap">';
                foreach ($rows as $row) {
                    $html .= '<div class="ps-row"><div class="ps-grow"><div class="ps-title">' . $e($row['name']) . '</div><div class="ps-tiny ps-muted">' . $e($row['email']) . '</div></div><div class="ps-amount ps-tiny">' . $right($row) . '</div></div>';
                }

                return $html . '</div>';
            };
            if ($business['idle']) {
                $html .= '<h3 class="ps-sub">' . $e(self::t('Paying, nothing watched in 30 days')) . '</h3>' . $people($business['idle'], fn ($row) => $e(implode(', ', array_unique($row['products']))));
            }
            if ($discord['warned']) {
                $html .= '<h3 class="ps-sub">' . $e(self::t('Left the Discord')) . '</h3>' . $people($discord['warned'], fn ($row) => $e($row['product']) . '<br>' . $e(self::t('since :date', ['date' => date(self::t('M j, Y') . ' H:i', $row['since'])])));
            }
            if ($requests !== null && $requests['full']) {
                $html .= '<h3 class="ps-sub">' . $e(self::t('Request limit used up')) . '</h3>' . $people($requests['full'], fn ($row) => $e($row['label']) . '<br>' . $e($row['used'] . ' / ' . $row['limit']));
            }
        }

        // Now playing
        $html .= $section('now-playing', self::t('Streaming now'));
        if (!$streams) {
            $html .= '<p class="ps-card ps-note ps-muted">' . $e(self::t('Nobody is watching right now.')) . '</p>';
        } else {
            $html .= '<div class="ps-shows">';
            foreach ($streams as $stream) {
                $method = $stream['transcode'] ? '<span class="ps-badge ps-badge-warn">' . $e(self::t('Converting')) . '</span>' : '<span class="ps-badge ps-badge-ok">' . $e(self::t('Direct')) . '</span>';
                $html .= '<div class="ps-card ps-show ps-stream">' . ($stream['server'] !== '' ? '<span class="ps-server">' . $this->icon('where-you-watch') . $e($stream['server']) . '</span>' : '')
                    . $poster($stream['poster'], $stream['title'], 'ps-thumb')
                    . '<div class="ps-grow">' . $customer($stream['customer'])
                    . '<div class="ps-small ps-clip ps-gap">' . ($stream['paused'] ? '<span class="ps-badge">' . $e(self::t('Paused')) . '</span> ' : '▶ ') . $e($stream['title']) . '</div>'
                    . ($stream['sub'] !== '' ? '<div class="ps-muted ps-tiny ps-clip">' . $e($stream['sub']) . '</div>' : '')
                    . '<div class="ps-muted ps-tiny ps-clip">' . $method . ' ' . $e($stream['device']) . '</div>'
                    . ($stream['progress'] !== null ? $bar($stream['progress'] / 100) : '') . '</div></div>';
            }
            $html .= '</div>';
        }

        // Plays per day, per server
        $max = 1;
        $totals = [];
        foreach ($overview['days'] as $perServer) {
            $max = max($max, array_sum($perServer));
            foreach ($perServer as $name => $n) {
                $totals[$name] = ($totals[$name] ?? 0) + $n;
            }
        }
        $html .= $section('when-you-watch', self::t('Plays per day')) . '<div class="ps-card"><div class="ps-days">';
        foreach ($overview['days'] as $day => $perServer) {
            $sum = array_sum($perServer);
            $tip = date(self::t('M j, Y'), strtotime($day)) . ': ' . $sum . ($perServer ? ' (' . implode(', ', array_map(fn ($name, $n) => $n . ' ' . ($name !== '' ? $name : '?'), array_keys($perServer), $perServer)) . ')' : '');
            $html .= '<div class="ps-col" title="' . $e($tip) . '"><span class="ps-tiny ps-n">' . ($sum > 0 ? $sum : '&nbsp;') . '</span><span class="ps-colbar ps-stack">';
            foreach ($perServer as $name => $n) {
                $html .= '<span style="height:' . round(100 * $n / $max, 2) . '%;background:' . $colour((string) $name) . '"></span>';
            }
            $html .= '</span></div>';
        }
        // Three dates under the bars, so they never run into each other on a phone.
        $dates = array_keys($overview['days']);
        $html .= '</div><div class="ps-axis ps-tiny">';
        foreach ([$dates[0], $dates[intdiv(count($dates), 2)], end($dates)] as $day) {
            $html .= '<span>' . $e(date(self::t('j/n'), strtotime($day))) . '</span>';
        }
        $html .= '</div>';
        if ($totals) {
            arsort($totals);
            $html .= '<div class="ps-legend ps-small">';
            foreach ($totals as $name => $n) {
                $html .= '<span><span class="ps-dot" style="background:' . $colour((string) $name) . '"></span>' . $e(($name !== '' ? $name : '?') . ': ' . $n) . '</span>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        // Top titles, with who watched them and where
        if ($overview['titles']) {
            $html .= $section('favourite-genres', self::t('Most watched, last 30 days')) . '<div class="ps-posters">';
            foreach ($overview['titles'] as $n => $title) {
                $who = $title['viewers'] ?? [];
                $html .= '<div class="ps-tile">' . $poster($title['poster'], $title['title'], 'ps-cover') . '<span class="ps-rank">' . ($n + 1) . '</span>'
                    . '<div class="ps-title ps-clip">' . $e($title['title']) . '</div>'
                    . '<div class="ps-muted ps-small">' . $e($title['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $title['plays']])) . ' · ' . $e($this->duration($title['watch_ms'])) . '</div>'
                    . ($who ? '<div class="ps-muted ps-tiny ps-clip" title="' . $e(implode(', ', $who)) . '">' . $e(self::t('By :names', ['names' => implode(', ', array_slice($who, 0, 3)) . (count($who) > 3 ? ' +' . (count($who) - 3) : '')])) . '</div>' : '')
                    . (($title['servers'] ?? []) ? '<div class="ps-tiny ps-clip">' . implode(' ', array_map(fn ($name) => '<span class="ps-dot" style="background:' . $colour($name) . '"></span>' . $e($name), $title['servers'])) . '</div>' : '')
                    . '</div>';
            }
            $html .= '</div>';
        }

        // Most active: everyone on the servers
        if ($overview['people']) {
            $top = max(1, $overview['people'][0]['watch_ms']);
            $html .= $section('your-shows', self::t('Most active, last 30 days')) . '<div class="ps-card ps-table">';
            foreach (array_slice($overview['people'], 0, 25) as $person) {
                $html .= '<div class="ps-row"><div class="ps-grow">' . $customer($person) . '</div>'
                    . '<div class="ps-amount"><div class="ps-title">' . $e($this->duration($person['watch_ms'])) . '</div><div class="ps-tiny">'
                    . $e(($person['plays'] === 1 ? self::t('1 play') : self::t(':count plays', ['count' => $person['plays']])) . ' · ' . implode(', ', $person['servers'])) . '</div>'
                    . $bar($person['watch_ms'] / $top) . '</div></div>';
            }
            $html .= '</div>';
        }

        return $html . '<p class="ps-muted ps-tiny ps-foot">' . $e(self::t('Updated :time. Kept for a minute.', ['time' => date('H:i', $overview['at'])])) . '</p></div>';
    }

    private function adminCss(): string
    {
        return '#paywarr-stats .ps-four{grid-template-columns:repeat(auto-fit,minmax(min(100%,9.5rem),1fr))}'
            . '#paywarr-stats .ps-warn{border-color:hsl(var(--color-primary));margin-bottom:1rem}'
            . '#paywarr-stats .ps-badge{display:inline-block;padding:.05rem .45rem;border-radius:9999px;font-size:.7rem;font-weight:600;border:1px solid currentColor}'
            . '#paywarr-stats .ps-badge-ok{color:hsl(142 60% 50%)}#paywarr-stats .ps-badge-warn{color:hsl(var(--color-primary))}'
            . '#paywarr-stats .ps-gap{margin-top:.35rem}'
            . '#paywarr-stats .ps-stream{position:relative;padding-top:2.3rem}'
            . '#paywarr-stats .ps-server{position:absolute;top:.6rem;left:.8rem;right:.8rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem;font-weight:700;letter-spacing:.02em;color:hsl(var(--color-primary))}'
            . '#paywarr-stats .ps-server .ps-ico{width:1em;height:1em;margin-right:.3rem}'
            . '#paywarr-stats .ps-days{display:flex;align-items:flex-end;gap:.25rem;height:9rem}'
            . '#paywarr-stats .ps-days .ps-col{min-width:0}#paywarr-stats .ps-days .ps-colbar{max-width:none}'
            . '#paywarr-stats .ps-axis{display:flex;justify-content:space-between;margin-top:.5rem}'
            . '#paywarr-stats .ps-rank{position:absolute;top:.4rem;left:.4rem;min-width:1.6rem;height:1.6rem;padding:0 .35rem;display:flex;align-items:center;justify-content:center;border-radius:9999px;font-weight:800;font-size:.8rem;background:hsl(var(--color-primary));color:hsl(var(--color-background))}'
            . '#paywarr-stats .ps-table{padding:.4rem 1.3rem}'
            . '#paywarr-stats .ps-row{display:flex;gap:1rem;align-items:center;padding:.8rem 0;border-bottom:1px solid hsl(var(--color-neutral))}'
            . '#paywarr-stats .ps-row:last-child{border-bottom:0}'
            . '#paywarr-stats .ps-amount{width:min(40%,14rem);text-align:right}#paywarr-stats .ps-amount .ps-bar{margin-top:.4rem}'
            . '#paywarr-stats .ps-scroll{overflow-x:auto}'
            . '#paywarr-stats .ps-tab{width:100%;border-collapse:collapse;font-size:.9rem}'
            . '#paywarr-stats .ps-tab th{text-align:left;font-size:.7rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;opacity:.6;padding:.7rem .6rem .5rem;white-space:nowrap}'
            . '#paywarr-stats .ps-tab td{padding:.65rem .6rem;border-top:1px solid hsl(var(--color-neutral));white-space:nowrap}'
            . '#paywarr-stats .ps-tab th:not(:first-child),#paywarr-stats .ps-tab td:not(:first-child){text-align:right}'
            . '#paywarr-stats .ps-dot{display:inline-block;width:.6rem;height:.6rem;border-radius:9999px;margin-right:.4rem;vertical-align:middle}'
            . '#paywarr-stats .ps-stack{display:flex;flex-direction:column-reverse;justify-content:flex-start;overflow:hidden}'
            . '#paywarr-stats .ps-stack>span{display:block;width:100%;flex:none}'
            . '#paywarr-stats .ps-stack>span{border-radius:0}#paywarr-stats .ps-n{font-weight:700;opacity:.75;line-height:1}'
            . '#paywarr-stats .ps-legend{display:flex;flex-wrap:wrap;gap:.4rem 1.2rem;margin-top:.8rem}'
            . '#paywarr-stats .ps-sub{margin:1.2rem 0 .2rem;font-size:.95rem;font-weight:700}';
    }

    /** A small line icon for a section heading, in the theme's text colour. */
    private function icon(string $name): string
    {
        $paths = [
            'now-playing' => '<circle cx="12" cy="12" r="9"/><path d="M10 8.5v7l6-3.5z"/>',
            'when-you-watch' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'favourite-genres' => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
            'where-you-watch' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
            'your-shows' => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M8 2l4 4 4-4"/>',
            'recently-watched' => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 8v4l3 2"/>',
        ];

        return '<svg class="ps-ico" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
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
            . '#paywarr-stats .ps-hero{position:relative;overflow:hidden;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,22rem);gap:1.5rem;align-items:center;'
            . 'border-radius:1.25rem;padding:1.6rem 1.75rem;border:1px solid hsl(var(--color-neutral));'
            . 'background:linear-gradient(135deg,hsl(var(--color-primary) / .22),hsl(var(--color-background-secondary)) 65%)}'
            . '#paywarr-stats .ps-hero-bg{position:absolute;inset:-3rem;width:calc(100% + 6rem);height:calc(100% + 6rem);object-fit:cover;filter:blur(40px) saturate(1.4);opacity:.35;z-index:-1}'
            . '#paywarr-stats .ps-hero::after{content:"";position:absolute;inset:0;z-index:-1;background:linear-gradient(90deg,hsl(var(--color-background-secondary) / .85),hsl(var(--color-background-secondary) / .35))}'
            . '#paywarr-stats .ps-spot{display:flex;gap:1rem;align-items:center;padding:.8rem;border-radius:1rem;background:hsl(var(--color-background) / .5);border:1px solid hsl(var(--color-neutral));backdrop-filter:blur(8px)}'
            . '#paywarr-stats .ps-spot .ps-bar{margin-top:.55rem}'
            . '#paywarr-stats .ps-spot-cover{position:relative;flex:none;display:block;width:4.6rem;aspect-ratio:2/3;overflow:hidden;border-radius:.6rem;box-shadow:0 8px 22px rgb(0 0 0 / .35);background:linear-gradient(160deg,hsl(var(--color-primary) / .35),hsl(var(--color-neutral)))}'
            . '#paywarr-stats .ps-spot-cover img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}'
            . '#paywarr-stats .ps-clamp{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}'
            . '#paywarr-stats .ps-ico{display:inline-block;width:1.15em;height:1.15em;margin-right:.45rem;vertical-align:-.2em;fill:none;stroke:hsl(var(--color-primary));stroke-width:2;stroke-linecap:round;stroke-linejoin:round}'
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
            . '@media (max-width:760px){#paywarr-stats .ps-hero{grid-template-columns:1fr}}'
            . '@media (max-width:640px){#paywarr-stats .ps-posters{grid-template-columns:repeat(auto-fill,minmax(6.25rem,1fr));gap:.75rem}#paywarr-stats .ps-hero{padding:1.3rem}}';
    }

    /** "12 h 5 min", "45 min" (translated). */
    public function duration(int $milliseconds): string
    {
        $minutes = intdiv(max(0, $milliseconds), 60000);
        $hours = intdiv($minutes, 60);
        if ($milliseconds > 0 && $minutes === 0) {
            return self::t('Under 1 min');
        }

        return $hours > 0
            ? self::t(':hours h :minutes min', ['hours' => $hours, 'minutes' => $minutes % 60])
            : self::t(':minutes min', ['minutes' => $minutes]);
    }

    /** An amount with its currency code, e.g. "99.00 DKK". */
    public function money(float $amount, string $currency): string
    {
        return trim(number_format($amount, 2, self::t('.'), self::t(',')) . ' ' . $currency);
    }

    private function client(): TracearrClient
    {
        return $this->client ??= new TracearrClient((string) $this->config('tracearr_url'), (string) $this->config('tracearr_api_key'));
    }

    private function silo(): SiloClient
    {
        return $this->silo ??= new SiloClient((string) $this->config('silo_url'), (string) $this->config('silo_api_key'));
    }

    /** Whether the address and key of 'tracearr' or 'silo' are filled in. */
    private function configured(string $source): bool
    {
        return trim((string) $this->config($source . '_url')) !== '' && trim((string) $this->config($source . '_api_key')) !== '';
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
