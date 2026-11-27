<?php

namespace Paymenter\Extensions\Others\PaywarrStats\Concerns;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Paymenter\Extensions\Others\PaywarrStats\PeopleStats;
use Throwable;

/**
 * What the admin pages Customers, Servers and Trends are made of: the last
 * 60 days of plays from Tracearr and Silo, each tied to its customer, and
 * Paymenter's side of every customer. Kept for 10 minutes. Part of
 * PaywarrStats.
 */
trait People
{
    private const PEOPLE_CACHE_SECONDS = 600;

    private const PEOPLE_DAYS = 60;

    public function peopleStats(?int $now = null): PeopleStats
    {
        $data = $this->people();

        return new PeopleStats($data['plays'], $data['customers'], $now ?? time());
    }

    /** ['plays', 'customers', 'subscriptions', 'errors', 'at'], kept for 10 minutes. */
    public function people(bool $fresh = false): array
    {
        $cached = $fresh ? null : Cache::get('paywarr-stats:people:v1');
        if (is_array($cached)) {
            return $cached;
        }
        $services = Service::with(['product.server', 'product.settings', 'plan', 'properties', 'user.properties', 'cancellation'])->get()
            ->filter(fn ($service) => ($service->product?->server?->extension ?? null) === 'Wizarr')->values();
        $data = $this->gatherPeople($services, time());
        Cache::put('paywarr-stats:people:v1', $data, self::PEOPLE_CACHE_SECONDS);

        return $data;
    }

    /** Forgets the kept numbers ("Refresh" on the pages). */
    public static function forgetPeople(): void
    {
        Cache::forget('paywarr-stats:people:v1');
    }

    public function gatherPeople(iterable $services, int $now): array
    {
        // Only Paywarr's subscriptions: another extension's are never read.
        $services = array_values(array_filter(is_array($services) ? $services : iterator_to_array($services),
            fn ($service) => ($service->product?->server?->extension ?? null) === 'Wizarr'));
        $errors = [];
        $owners = $this->accountOwners($services);
        $since = $now - self::PEOPLE_DAYS * PeopleStats::DAY;
        $plays = [];
        if ($this->configured('tracearr')) {
            try {
                foreach ($this->client()->history($since, 20000) as $play) {
                    $plays[] = $this->peopleTracearrPlay($play, $owners);
                }
            } catch (Throwable $e) {
                $errors[] = 'Tracearr: ' . $e->getMessage();
            }
        }
        if ($this->siloDirect()) {
            foreach ($owners as $key => $owner) {
                if (!str_starts_with($key, 'silo|')) {
                    continue;
                }
                try {
                    foreach ($this->silo()->history(substr($key, 5), 1000) as $play) {
                        $normal = $this->peopleSiloPlay($play, $owner);
                        if ($normal['started'] >= $since) {
                            $plays[] = $normal;
                        }
                    }
                } catch (Throwable $e) {
                    $errors['silo'] = 'Silo: ' . $e->getMessage();
                }
            }
        }

        return [
            'plays' => $plays,
            'customers' => $this->peopleCustomers($services, $now),
            'subscriptions' => array_map(fn ($row) => ['created_at' => $row['created_at'], 'cancelled_at' => $row['cancelled_at'], 'status' => $row['status']], $this->businessRows($services)),
            'errors' => array_values($errors),
            'at' => $now,
        ];
    }

    /**
     * Which customer has each media server user: "type|username" (Silo:
     * "silo|account ID") => ['who' => Paymenter user ID, 'server' => name].
     * An active subscription wins over an old one with the same user.
     */
    public function accountOwners(iterable $services): array
    {
        $owners = [];
        foreach ($services as $service) {
            $props = $this->propertiesOf($service);
            $active = in_array((string) ($service->status ?? ''), ['active', 'suspended'], true);
            foreach ($props as $key => $value) {
                if ($key !== 'wizarr_users' && !str_starts_with((string) $key, 'wizarr_users:')) {
                    continue;
                }
                $server = (string) ($props['wizarr_server' . substr((string) $key, strlen('wizarr_users'))] ?? '');
                foreach ($this->mediaAccounts([[$key => $value]]) as $account) {
                    $mapKey = $account['type'] === 'silo' ? 'silo|' . $account['id'] : $account['type'] . '|' . strtolower($account['username']);
                    if (!isset($owners[$mapKey]) || $active) {
                        $owners[$mapKey] = ['who' => $service->user_id ?? ($service->user->id ?? null), 'server' => $server !== '' ? $server : ($account['type'] === 'silo' ? 'Silo' : '')];
                        if ($account['type'] === 'silo') {
                            // Tracearr (3.3+) names Silo users by username.
                            $owners['silo|' . strtolower($account['username'])] = $owners[$mapKey];
                        }
                    }
                }
            }
        }

        return $owners;
    }

    /** A Tracearr play as PeopleStats reads it. */
    public function peopleTracearrPlay(array $play, array $owners): array
    {
        $type = strtolower((string) ($play['server_type'] ?? ''));
        $username = (string) ($play['user']['username'] ?? '');
        $owner = $owners[$type . '|' . strtolower($username)] ?? null;
        $mediaType = (string) ($play['media_type'] ?? '');
        $episode = $mediaType === 'episode';
        $started = strtotime((string) ($play['started_at'] ?? '')) ?: 0;
        $stopped = strtotime((string) ($play['stopped_at'] ?? '')) ?: null;
        $code = $episode && isset($play['season_number'], $play['episode_number']) ? sprintf('S%02dE%02d · ', (int) $play['season_number'], (int) $play['episode_number']) : '';

        return [
            'who' => $owner['who'] ?? null,
            'username' => $username,
            'server' => (string) ($play['server_name'] ?? ''),
            'server_type' => $type,
            'title' => (string) (($episode ? ($play['show_title'] ?? null) : null) ?? ($play['media_title'] ?? '')),
            'episode' => $episode ? $code . (string) ($play['media_title'] ?? '') : null,
            'type' => in_array($mediaType, ['movie', 'episode'], true) ? $mediaType : 'other',
            'ms' => (int) ($play['duration_ms'] ?? 0),
            'started' => $started,
            'stopped' => $stopped,
            'watched' => (bool) ($play['watched'] ?? false),
            'app' => (string) ($play['product'] ?? '') ?: (string) ($play['player'] ?? ''),
            'device' => (string) ($play['device'] ?? '') ?: (string) ($play['player'] ?? ''),
            'platform' => (string) ($play['platform'] ?? ''),
            'transcode' => isset($play['is_transcode']) ? (bool) $play['is_transcode'] : null,
            'genres' => array_values(array_filter((array) ($play['genres'] ?? []), 'is_string')),
        ];
    }

    /** A Silo play as PeopleStats reads it: the app is Silo's client name ("Silo for Apple TV"). */
    public function peopleSiloPlay(array $play, array $owner): array
    {
        $episode = ($play['media_type'] ?? '') === 'episode';
        $code = $episode && isset($play['season_number'], $play['episode_number']) ? sprintf('S%02dE%02d · ', (int) $play['season_number'], (int) $play['episode_number']) : '';
        $method = strtolower((string) ($play['video_decision'] ?? '') . ' ' . (string) ($play['play_method'] ?? ''));

        return [
            'who' => $owner['who'] ?? null,
            'username' => (string) ($play['username'] ?? ''),
            'server' => (string) ($owner['server'] ?? 'Silo') ?: 'Silo',
            'server_type' => 'silo',
            'title' => (string) (($episode ? ($play['series_title'] ?? null) : null) ?? ($play['media_title'] ?? '')),
            'episode' => $episode ? $code . (string) ($play['media_title'] ?? '') : null,
            'type' => $episode ? 'episode' : (($play['media_type'] ?? '') === 'movie' ? 'movie' : 'other'),
            'ms' => (int) round(((float) ($play['watched_seconds'] ?? $play['played_seconds'] ?? 0)) * 1000),
            'started' => strtotime((string) ($play['started_at'] ?? '')) ?: 0,
            'stopped' => strtotime((string) ($play['ended_at'] ?? '')) ?: null,
            'watched' => (bool) ($play['completed'] ?? false),
            'app' => (string) ($play['client_name'] ?? '') ?: 'Silo',
            'device' => '',
            'platform' => (string) ($play['device_platform'] ?? ''),
            'transcode' => trim($method) === '' ? null : str_contains($method, 'transcode'),
            'genres' => [],
        ];
    }

    /** Paymenter's side of every customer with a Paywarr subscription. */
    public function peopleCustomers(iterable $services, int $now): array
    {
        $byUser = [];
        foreach ($services as $service) {
            $user = $service->user ?? null;
            if ($user) {
                $byUser[$service->user_id ?? $user->id][] = $service;
            }
        }
        $invoices = $this->invoicesByUser(array_keys($byUser));
        $tickets = $this->ticketsByUser(array_keys($byUser));
        $requests = [];
        foreach ($this->cachedRequestRows($services) ?? [] as $row) {
            $requests[strtolower($row['email'])][] = $row;
        }

        $customers = [];
        foreach ($byUser as $id => $list) {
            $user = $list[0]->user;
            $userProps = $this->propertiesOf($user);
            $statuses = array_map(fn ($service) => (string) $service->status, $list);
            $status = in_array('active', $statuses, true) ? 'active' : (in_array('suspended', $statuses, true) ? 'suspended' : ($statuses[0] ?? ''));
            $current = array_values(array_filter($list, fn ($service) => in_array((string) $service->status, ['active', 'suspended'], true))) ?: $list;
            $servers = [];
            $trial = false;
            foreach ($current as $service) {
                $props = $this->propertiesOf($service);
                foreach ($props as $key => $value) {
                    if (str_starts_with((string) $key, 'wizarr_server:') && $value !== '') {
                        $servers[] = (string) $value;
                    }
                }
                $settings = $this->settingsArray($service->product->settings ?? []);
                $ends = strtotime((string) ($props['trial_ends_at'] ?? '')) ?: null;
                $trial = $trial || (((int) ($settings['trial_days'] ?? 0) > 0 || (int) ($props['trial_days_given'] ?? 0) > 0)
                    && empty($props['trial_converted_to']) && ($ends === null || $ends > $now));
                if (!array_filter(array_keys($props), fn ($key) => str_starts_with((string) $key, 'wizarr_server:'))) {
                    $servers[] = (string) ($service->product->server->name ?? '');
                }
            }
            $created = array_filter(array_map(fn ($service) => !empty($service->created_at) ? strtotime((string) $service->created_at) : null, $list));
            $mine = $invoices[$id] ?? [];
            usort($mine, fn ($a, $b) => ($b['at'] ?? 0) <=> ($a['at'] ?? 0));
            $paid = array_filter($mine, fn ($invoice) => $invoice['status'] === 'paid');
            $unpaid = array_filter($mine, fn ($invoice) => $invoice['status'] === 'pending');
            $ownRequests = $requests[strtolower((string) $user->email)] ?? [];
            $customers[] = [
                'id' => $id,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: (string) ($user->email ?? '?'),
                'email' => (string) ($user->email ?? ''),
                'discord' => ($userProps['discord_username'] ?? '') !== '' ? (string) $userProps['discord_username'] : (!empty($userProps['discord_id']) ? (string) $userProps['discord_id'] : null),
                'since' => $created ? min($created) : null,
                'products' => array_values(array_unique(array_map(fn ($service) => (string) ($service->product->name ?? ''), $current))),
                'status' => $status,
                'trial' => $trial,
                'servers' => array_values(array_unique(array_filter($servers))),
                'paid_total' => array_sum(array_column($paid, 'total')),
                'paid_count' => count($paid),
                'unpaid' => count($unpaid),
                'unpaid_total' => array_sum(array_column($unpaid, 'total')),
                'currency' => (string) ($mine[0]['currency'] ?? ($list[0]->currency_code ?? '')),
                'requests' => $ownRequests ? [
                    'used' => array_sum(array_column($ownRequests, 'used')),
                    'limit' => array_sum(array_column($ownRequests, 'limit')),
                    'unlimited' => (bool) array_filter(array_column($ownRequests, 'unlimited')),
                ] : null,
                'tickets' => count($tickets[$id] ?? []),
                'open_tickets' => count(array_filter($tickets[$id] ?? [], fn ($ticket) => $ticket['status'] !== 'closed')),
                'invoices' => array_slice($mine, 0, 12),
                'ticket_list' => array_slice($tickets[$id] ?? [], 0, 8),
            ];
        }

        return $customers;
    }

    /** Request quotas as on the Stats overview (asking Seerr and Silo takes a moment: kept 10 minutes). */
    private function cachedRequestRows(iterable $services): ?array
    {
        $requests = Cache::get('paywarr-stats:admin:requests');
        if (!is_array($requests) && $requests !== false) {
            try {
                $requests = $this->requestRows($services) ?? false;
            } catch (Throwable $e) {
                $requests = false;
            }
            Cache::put('paywarr-stats:admin:requests', $requests, 600);
        }

        return $requests === false ? null : $requests;
    }

    /** @return array<int, array> user ID => invoices (newest first): id, status, total, currency, at */
    private function invoicesByUser(array $userIds): array
    {
        if (!$userIds || !class_exists(\App\Models\Invoice::class)) {
            return [];
        }
        $rows = [];
        try {
            foreach (\App\Models\Invoice::with(['items', 'adjustmentNotes'])->whereIn('user_id', $userIds)->orderByDesc('id')->get() as $invoice) {
                $rows[$invoice->user_id][] = [
                    'id' => $invoice->id,
                    'number' => (string) ($invoice->number ?? $invoice->id),
                    'status' => (string) $invoice->status,
                    'total' => (float) $invoice->total,
                    'currency' => (string) ($invoice->currency_code ?? ''),
                    'at' => $invoice->created_at ? strtotime((string) $invoice->created_at) : null,
                ];
            }
        } catch (Throwable $e) {
            Log::warning('Paywarr Stats: invoices could not be read: ' . $e->getMessage());
        }

        return $rows;
    }

    /** @return array<int, array> user ID => tickets (newest first): id, subject, status, at */
    private function ticketsByUser(array $userIds): array
    {
        if (!$userIds || !class_exists(\App\Models\Ticket::class)) {
            return [];
        }
        $rows = [];
        try {
            foreach (\App\Models\Ticket::whereIn('user_id', $userIds)->orderByDesc('id')->get() as $ticket) {
                $rows[$ticket->user_id][] = [
                    'id' => $ticket->id,
                    'subject' => (string) $ticket->subject,
                    'status' => (string) $ticket->status,
                    'at' => $ticket->created_at ? strtotime((string) $ticket->created_at) : null,
                ];
            }
        } catch (Throwable $e) {
            Log::warning('Paywarr Stats: tickets could not be read: ' . $e->getMessage());
        }

        return $rows;
    }
}
