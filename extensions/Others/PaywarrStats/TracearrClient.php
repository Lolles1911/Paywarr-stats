<?php

namespace Paymenter\Extensions\Others\PaywarrStats;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tracearr's public API v2 (/api/v2/public), read only. A key is made in
 * Tracearr under Settings and starts with "trr_pub_".
 */
class TracearrClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 10,
    ) {}

    /**
     * Every Tracearr identity with its accounts on each media server.
     *
     * @return array<int, array{id: string, accounts: array<int, array{server_type: string, username: string, removed_at: ?string}>}>
     */
    public function users(): array
    {
        $users = [];
        $cursor = null;
        do {
            $page = $this->call('/users', array_filter(['pageSize' => 100, 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $user) {
                $users[] = $user;
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($users) < 10000);

        return $users;
    }

    /**
     * The media servers Tracearr watches: server_id, server_name, server_type,
     * status ("up", "down", "unknown"), historical (only imported history,
     * no live connection) and active_streams.
     */
    public function servers(): array
    {
        return $this->call('/servers')['data'] ?? [];
    }

    /**
     * Every play since $since on every server, newest first: up to $max, in
     * pages of 100. Each has server_name, user.username, media_title,
     * show_title, duration_ms (watched), watched, started_at and poster_url.
     */
    public function history(int $since, int $max = 3000): array
    {
        $plays = [];
        $cursor = null;
        do {
            $page = $this->call('/history', array_filter(['pageSize' => 100, 'since' => gmdate('Y-m-d\TH:i:s\Z', $since), 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $play) {
                $plays[] = $play;
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($plays) < $max);

        return $plays;
    }

    /** Plays and watch time (last 7 days, last 30 days, all time) and top genres. */
    public function userStats(string $id): array
    {
        return $this->call('/users/' . rawurlencode($id) . '/stats');
    }

    /** The latest plays, newest first: up to $max, in pages of 100. */
    public function userHistory(string $id, int $max = 200): array
    {
        $plays = [];
        $cursor = null;
        do {
            $page = $this->call('/users/' . rawurlencode($id) . '/history', array_filter(['pageSize' => min(100, $max - count($plays)), 'cursor' => $cursor]));
            foreach ($page['data'] ?? [] as $play) {
                $plays[] = $play;
            }
            $cursor = $page['meta']['nextCursor'] ?? null;
        } while ($cursor !== null && count($plays) < $max);

        return $plays;
    }

    /**
     * Every movie or show this identity has watched (fully or partly), with
     * plays and, for shows, episodes watched out of episodes in the library.
     */
    public function watchedMedia(string $id, string $type): array
    {
        return $this->call('/watched-media', ['media_type' => $type, 'user_id' => $id, 'min_state' => 'partial', 'pageSize' => 1000])['data'] ?? [];
    }

    /** What is being streamed right now, on every server (GET /streams). */
    public function streams(): array
    {
        return $this->call('/streams')['data'] ?? [];
    }

    /** The most played movies or shows over the last $days days, all users together. */
    public function topMedia(string $type, int $days, int $count = 10): array
    {
        return $this->call('/top-media', ['media_type' => $type, 'days' => $days, 'sort' => 'plays', 'pageSize' => $count])['data'] ?? [];
    }

    /**
     * The poster of a title (a movie, or a show by its episodes), from its
     * latest play: the path on Tracearr's image proxy, or null.
     */
    public function mediaPoster(string $mediaId): ?string
    {
        $play = $this->call('/media/' . rawurlencode($mediaId) . '/history', ['pageSize' => 1])['data'][0] ?? null;
        $poster = (string) ($play['poster_url'] ?? '');

        return str_starts_with($poster, '/api/v1/images/proxy?') ? $poster : null;
    }

    /**
     * Plays per day over the last 30 days, per server: rows of date and count.
     * From the public API v1 (/api/v1/public/activity), which the same key opens.
     */
    public function playsPerDay(string $timezone): array
    {
        return $this->call('/activity', ['period' => 'month', 'timezone' => $timezone], '/api/v1/public')['plays'] ?? [];
    }

    /**
     * A poster through Tracearr's image proxy (/api/v1/images/proxy, which
     * needs no key). Only that path is ever fetched, so this cannot be used
     * to reach anything else on Tracearr's network.
     *
     * @return array{body: string, type: string}|null  null when it is not an image
     */
    public function poster(string $path): ?array
    {
        if (!str_starts_with($path, '/api/v1/images/proxy?')) {
            return null;
        }
        try {
            $response = Http::withHeaders(['Accept' => 'image/*'])->timeout($this->timeout)->get(rtrim($this->baseUrl, '/') . $path);
        } catch (Throwable $e) {
            return null;
        }
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if ($response->status() !== 200 || !str_starts_with($type, 'image/') || $type === 'image/svg+xml') {
            return null;
        }

        return ['body' => $response->body(), 'type' => $type];
    }

    private function call(string $path, array $query = [], string $base = '/api/v2/public'): array
    {
        $url = rtrim($this->baseUrl, '/') . $base . $path;
        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey, 'Accept' => 'application/json'])
                ->timeout($this->timeout)
                ->get($url . ($query ? '?' . http_build_query($query) : ''));
        } catch (Throwable $e) {
            throw new TracearrException('Could not reach Tracearr at ' . $url . ': ' . $e->getMessage());
        }

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            throw new TracearrException('Tracearr rejected the API key (' . $status . '). Make a public API key in Tracearr under Settings; it starts with "trr_pub_".');
        }
        if ($status < 200 || $status >= 300) {
            throw new TracearrException('Tracearr answered ' . $status . ' for GET ' . $path . '.');
        }
        $body = $response->json();

        return is_array($body) ? $body : [];
    }
}
