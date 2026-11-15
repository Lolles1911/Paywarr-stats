<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The calls Paywarr Stats makes to Silo's native API v2, for customers whose
 * access comes from a Silo server in Paywarr. Silo records every play
 * itself, so Tracearr is not needed for them.
 *
 * Written against Silo's API contract (contracts/api/v2/openapi.json and
 * its fixtures, November 2026); Silo is not at 1.0 yet. Authentication:
 * "Authorization: Bearer sa_...", a key of an admin account.
 *
 * - Numbers and history: /admin/users/{id}/watch-summary and
 *   /admin/playback-history, both inside the admin:monitor:read scope.
 * - Posters and genres: /catalog/items/{id}, which acts as the key owner's
 *   primary profile (/profiles). Outside that scope; with a scoped key the
 *   page simply has no posters or genres.
 */
class SiloClient
{
    private ?string $profileId = null;

    /** Item details asked for in this request, by content ID (null: unavailable). */
    private array $items = [];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = 10,
    ) {}

    /** Plays and seconds watched over the last $days days (1 to 365). */
    public function watchSummary(string $accountId, int $days): array
    {
        return $this->call('/api/v2/admin/users/' . rawurlencode($accountId) . '/watch-summary', ['days' => max(1, min(365, $days))]);
    }

    /** The account's latest plays, newest first, up to $max, in pages of up to 200. */
    public function history(string $accountId, int $max = 200): array
    {
        $plays = [];
        $cursor = null;
        do {
            $page = $this->call('/api/v2/admin/playback-history', array_filter([
                'user_id' => $accountId,
                'limit' => min(200, $max - count($plays)),
                'cursor' => $cursor,
            ]));
            foreach ($page['items'] ?? [] as $play) {
                // Only this account's plays, whatever the filter did.
                if ((string) ($play['user_id'] ?? '') === $accountId) {
                    $plays[] = $play;
                }
            }
            $cursor = !empty($page['page']['has_more']) ? ($page['page']['next_cursor'] ?? null) : null;
        } while ($cursor !== null && count($plays) < $max);

        return $plays;
    }

    /** Every playback going on right now, all accounts (admin:monitor:read). */
    public function sessions(): array
    {
        return $this->call('/api/v2/admin/sessions', ['limit' => 200])['items'] ?? [];
    }

    /** The most played titles and the most active profiles over the last $days days (1 to 30). */
    public function topActivity(int $days, int $limit = 25): array
    {
        return $this->call('/api/v2/admin/stats/top-activity', ['days' => max(1, min(30, $days)), 'limit' => max(1, min(25, $limit))]);
    }

    /** Plays started per hour over the last $hours hours (up to 744), as buckets of hour and counts. */
    public function playsPerHour(int $hours): array
    {
        return $this->call('/api/v2/admin/stats/playback-activity', ['hours' => max(1, min(744, $hours))])['buckets'] ?? [];
    }

    /**
     * A catalog item (title, type, genres, poster, series_id, episode_count),
     * or null when it can't be read: a key without catalog access, a
     * PIN-locked owner profile, or an item that is gone.
     */
    public function item(string $contentId): ?array
    {
        if ($contentId === '') {
            return null;
        }
        if (array_key_exists($contentId, $this->items)) {
            return $this->items[$contentId];
        }
        try {
            $profile = $this->profileId();
            $item = $profile === '' ? null : $this->call('/api/v2/catalog/items/' . rawurlencode($contentId), ['image_size' => 'small'], ['X-Profile-Id' => $profile]);
        } catch (Throwable $e) {
            $item = null;
        }

        return $this->items[$contentId] = is_array($item) ? $item : null;
    }

    /**
     * The poster of an item (a series' poster for an episode), as image bytes
     * and type; null when there is none or it is not a plain image.
     *
     * @return array{body: string, type: string}|null
     */
    public function poster(string $contentId): ?array
    {
        $item = $this->item($contentId);
        $url = (string) ($item['series_poster_url'] ?? '') ?: (string) ($item['poster_url'] ?? '');
        if ($url === '') {
            return null;
        }
        // A signed Silo route is relative; object storage is a full address.
        if (str_starts_with($url, '/')) {
            $url = rtrim($this->baseUrl, '/') . $url;
        } elseif (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        try {
            $response = Http::withHeaders(['Accept' => 'image/*'] + (str_starts_with($url, rtrim($this->baseUrl, '/') . '/') ? ['Authorization' => 'Bearer ' . $this->apiKey] : []))
                ->timeout($this->timeout)->get($url);
        } catch (Throwable $e) {
            return null;
        }
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($response->status() !== 200 || !str_starts_with($type, 'image/') || $type === 'image/svg+xml') {
            return null;
        }

        return ['body' => $response->body(), 'type' => $type];
    }

    /** The key owner's primary profile, which catalog reads act as. "" when there is none to use. */
    private function profileId(): string
    {
        if ($this->profileId === null) {
            $this->profileId = '';
            foreach ($this->call('/api/v2/profiles')['items'] ?? [] as $profile) {
                if (!empty($profile['is_primary']) && empty($profile['has_pin'])) {
                    $this->profileId = (string) $profile['id'];
                }
            }
        }

        return $this->profileId;
    }

    private function call(string $path, array $query = [], array $headers = []): array
    {
        $url = rtrim($this->baseUrl, '/') . $path . ($query ? '?' . http_build_query($query) : '');
        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'] + $headers)
                ->timeout($this->timeout)
                ->get($url);
        } catch (Throwable $e) {
            throw new TracearrException('Could not reach Silo at ' . rtrim($this->baseUrl, '/') . ': ' . $e->getMessage());
        }

        $status = $response->status();
        if ($status === 401) {
            throw new TracearrException('Silo rejected the API key (401). Make one in Silo under Admin -> API Keys; it starts with "sa_".');
        }
        if ($status === 403) {
            throw new TracearrException('Silo refused GET ' . $path . ' (403). The key must belong to an admin account, with full access or the admin:monitor:read scope.');
        }
        if ($status < 200 || $status >= 300) {
            throw new TracearrException('Silo answered ' . $status . ' for GET ' . $path . '.');
        }
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
