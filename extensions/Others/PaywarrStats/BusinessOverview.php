<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

/**
 * The business side of the administrators' overview, from Paymenter's own
 * subscriptions and invoices: subscriptions per server, trials, monthly
 * revenue, unpaid invoices, growth, renewals, customers who don't watch,
 * Discord and requests.
 *
 * Works on plain rows (PaywarrStats::businessRows() makes them), so it can be
 * tested without a database.
 */
class BusinessOverview
{
    /**
     * @param  array  $services  rows: id, status, server, product, price, quantity, currency, plan_type,
     *                           period, unit, created_at, cancelled_at, expires_at (timestamps or null), trial,
     *                           trial_ended, trial_converted, name, email, discord, discord_left, discord_suspended
     * @param  array  $invoices  rows: status, total, currency, due_at
     * @param  array  $watching  e-mail addresses of customers with a play in the last 30 days
     * @param  array|null  $requests  rows: name, email, label, used, limit, unlimited; null when unknown
     */
    public function gather(array $services, array $invoices, array $watching, ?array $requests, ?int $now = null): array
    {
        $now ??= time();
        $since = $now - 30 * 86400;
        $watching = array_flip(array_map('strtolower', $watching));

        $servers = [];
        $trials = ['running' => 0, 'ended' => 0, 'converted' => 0];
        $revenue = [];
        $products = [];
        $growth = ['new' => 0, 'new_trials' => 0, 'cancelled' => 0];
        $renewals = [];
        $active = [];
        $discord = ['connected' => 0, 'warned' => [], 'suspended' => 0];

        foreach ($services as $row) {
            $status = (string) $row['status'];
            $server = (string) ($row['server'] ?: '?');
            $servers[$server] ??= ['name' => $server, 'active' => 0, 'trials' => 0, 'suspended' => 0, 'pending' => 0, 'cancelled' => 0, 'customers' => []];

            if ($row['trial']) {
                if ($row['trial_converted']) {
                    $trials['converted']++;
                } elseif ($status === 'active' && !$row['trial_ended']) {
                    $trials['running']++;
                    $servers[$server]['trials']++;
                } elseif ($row['trial_ended']) {
                    $trials['ended']++;
                }
            } elseif (isset($servers[$server][$status])) {
                $servers[$server][$status]++;
            }
            if ($status === 'active') {
                $servers[$server]['customers'][strtolower($row['email'])] = true;
            }

            if ($row['created_at'] !== null && $row['created_at'] >= $since) {
                $growth[$row['trial'] ? 'new_trials' : 'new']++;
            }
            if ($status === 'cancelled' && $row['cancelled_at'] !== null && $row['cancelled_at'] >= $since && !$row['trial']) {
                $growth['cancelled']++;
            }

            if ($status !== 'active' || $row['trial']) {
                if ($row['discord_suspended'] && $status === 'suspended') {
                    $discord['suspended']++;
                }

                continue;
            }

            // A paying subscription.
            $monthly = $this->monthly((float) $row['price'] * max(1, (int) $row['quantity']), (string) $row['plan_type'], (int) $row['period'], (string) $row['unit']);
            $currency = (string) ($row['currency'] ?: '');
            if ($monthly > 0) {
                $revenue[$currency] = ($revenue[$currency] ?? 0) + $monthly;
                $products[$row['product'] . '|' . $currency] ??= ['product' => (string) $row['product'], 'currency' => $currency, 'count' => 0, 'monthly' => 0.0];
                $products[$row['product'] . '|' . $currency]['count']++;
                $products[$row['product'] . '|' . $currency]['monthly'] += $monthly;
            }
            if ($row['expires_at'] !== null && $row['expires_at'] >= $now - 86400 && $row['expires_at'] <= $now + 7 * 86400 && $row['plan_type'] === 'recurring') {
                $renewals[] = ['name' => $row['name'], 'email' => $row['email'], 'product' => $row['product'], 'date' => $row['expires_at'],
                    'amount' => (float) $row['price'] * max(1, (int) $row['quantity']), 'currency' => $currency];
            }
            $email = strtolower($row['email']);
            $active[$email] ??= ['name' => $row['name'], 'email' => $row['email'], 'products' => [], 'discord' => $row['discord']];
            $active[$email]['products'][] = $row['product'];
            if ($row['discord_left'] !== null) {
                $discord['warned'][] = ['name' => $row['name'], 'email' => $row['email'], 'product' => $row['product'], 'since' => $row['discord_left']];
            }
        }

        foreach ($active as $customer) {
            if ($customer['discord']) {
                $discord['connected']++;
            }
        }
        $idle = array_values(array_filter($active, fn ($c) => !isset($watching[strtolower($c['email'])])));

        $unpaid = ['count' => 0, 'overdue' => 0, 'amounts' => []];
        foreach ($invoices as $invoice) {
            if ($invoice['status'] !== 'pending') {
                continue;
            }
            $unpaid['count']++;
            $unpaid['amounts'][$invoice['currency']] = ($unpaid['amounts'][$invoice['currency']] ?? 0) + (float) $invoice['total'];
            if ($invoice['due_at'] !== null && $invoice['due_at'] < $now) {
                $unpaid['overdue']++;
            }
        }

        $requestSummary = null;
        if ($requests !== null) {
            $requestSummary = ['used' => 0, 'customers' => 0, 'full' => []];
            foreach ($requests as $row) {
                $requestSummary['used'] += (int) $row['used'];
                $requestSummary['customers']++;
                if (!$row['unlimited'] && (int) $row['limit'] > 0 && (int) $row['used'] >= (int) $row['limit']) {
                    $requestSummary['full'][] = $row;
                }
            }
        }

        foreach ($servers as &$server) {
            $server['customers'] = count($server['customers']);
        }
        unset($server);
        ksort($servers);
        usort($renewals, fn ($a, $b) => $a['date'] <=> $b['date']);
        usort($idle, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($products, fn ($a, $b) => $b['monthly'] <=> $a['monthly']);
        $judged = $trials['converted'] + $trials['ended'];

        return [
            'servers' => array_values($servers),
            'trials' => $trials + ['rate' => $judged > 0 ? (int) round(100 * $trials['converted'] / $judged) : null],
            'revenue' => $revenue,
            'products' => $products,
            'unpaid' => $unpaid,
            'growth' => $growth,
            'renewals' => $renewals,
            'paying' => count($active),
            'idle' => $idle,
            'discord' => $discord,
            'requests' => $requestSummary,
        ];
    }

    /** A price per billing period as a price per month; 0 for free and one-time plans. */
    public function monthly(float $price, string $type, int $period, string $unit): float
    {
        if ($type !== 'recurring' || $price <= 0) {
            return 0.0;
        }
        $months = max(1, $period) * match ($unit) {
            'hour' => 1 / 720,
            'day' => 1 / 30,
            'week' => 7 / 30,
            'year' => 12,
            default => 1,
        };

        return $price / $months;
    }
}
