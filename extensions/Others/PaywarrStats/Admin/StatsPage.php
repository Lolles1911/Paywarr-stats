<?php

namespace Paymenter\Extensions\Others\PaywarrStats\Admin;

use Filament\Actions\Action;
use Filament\Navigation\NavigationItem;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Others\PaywarrStats\PaywarrStats;
use Paymenter\Extensions\Others\PaywarrStats\PeopleStats;
use Throwable;

/**
 * Shared by the Paywarr Stats admin pages: shown in both the "Paywarr Stats"
 * and the "Paywarr" group, the numbers (kept 10 minutes) with a Refresh
 * button, and small bars, durations and money as HTML.
 */
trait StatsPage
{
    private ?PeopleStats $stats = null;

    private ?array $data = null;

    public static function canAccess(): bool
    {
        return PaywarrStats::isAdmin(Auth::user());
    }

    /** In "Paywarr Stats" and, as the same page, in "Paywarr". */
    public static function getNavigationItems(): array
    {
        $items = parent::getNavigationItems();
        $route = static::getNavigationItemActiveRoutePattern();
        $items[] = NavigationItem::make(static::getNavigationLabel())
            ->key(static::class . '@paywarr')
            ->group('Paywarr')
            ->icon(static::getNavigationIcon())
            ->isActiveWhen(fn (): bool => request()->routeIs($route))
            ->sort(50 + (int) static::getNavigationSort())
            ->url(static::getNavigationUrl());

        return $items;
    }

    protected function people(): array
    {
        return $this->data ??= $this->extension()->people();
    }

    protected function stats(): PeopleStats
    {
        if ($this->stats === null) {
            $data = $this->people();
            $this->stats = new PeopleStats($data['plays'], $data['customers'], time());
        }

        return $this->stats;
    }

    protected function extension(): PaywarrStats
    {
        return new PaywarrStats([]);
    }

    protected function refreshAction(): Action
    {
        return Action::make('refresh')->label('Refresh')->icon('ri-refresh-line')->color('gray')
            ->action(function () {
                PaywarrStats::forgetPeople();
                $this->data = null;
                $this->stats = null;
                Notification::make()->title('Fetched again')->success()->send();
            });
    }

    /** "Export CSV": a semicolon-separated file Excel opens as it is (UTF-8 with BOM). */
    protected function csvAction(string $name, callable $make): Action
    {
        return Action::make('csv')->label('Export CSV')->icon('ri-download-2-line')->color('gray')
            ->action(function () use ($name, $make) {
                [$header, $rows] = $make();
                $text = self::csvText($header, $rows);

                return response()->streamDownload(function () use ($text) {
                    echo $text;
                }, $name . '-' . date('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
            });
    }

    public static function csvText(array $header, iterable $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $header, ';');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($cell) => is_array($cell) ? implode(', ', $cell) : (string) $cell, $row), ';');
        }
        rewind($out);
        $text = stream_get_contents($out);
        fclose($out);

        return $text;
    }

    /** "As of 14:05 · Tracearr: …" under the heading. */
    public function getSubheading(): ?string
    {
        try {
            $data = $this->people();
        } catch (Throwable $e) {
            report($e);

            return 'Could not be read: ' . $e->getMessage();
        }
        $line = 'The last 60 days of plays from Tracearr and Silo, as of ' . date('H:i', (int) $data['at']) . '. A play counts after 2 minutes.';

        return $data['errors'] ? $line . ' Not all could be read: ' . implode(' ', $data['errors']) : $line;
    }

    public static function duration(int $ms): string
    {
        $minutes = intdiv($ms, 60000);
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $hours = intdiv($minutes, 60);

        return $hours < 48 ? $hours . ' h ' . ($minutes % 60 > 0 ? ($minutes % 60) . ' min' : '') : $hours . ' h';
    }

    public static function money(float $amount, string $currency): string
    {
        return trim(number_format($amount, 2) . ' ' . $currency);
    }

    public static function ago(?int $at): string
    {
        if ($at === null) {
            return 'Never';
        }
        $days = intdiv(max(0, time() - $at), 86400);

        return match (true) {
            $days === 0 => 'Today',
            $days === 1 => 'Yesterday',
            $days < 60 => $days . ' days ago',
            default => date('M j, Y', $at),
        };
    }

    public static function trendText(?int $trend): string
    {
        return $trend === null ? 'New' : ($trend > 0 ? '+' : '') . $trend . '%';
    }

    /**
     * Bars for a short series, e.g. plays per hour: $labels under them, the
     * value on hover. Works in light and dark.
     */
    public static function bars(array $values, array $labels, string $unit = 'plays', int $height = 90): HtmlString
    {
        $max = max(1, ...array_values($values));
        $bars = '';
        $names = '';
        foreach (array_values($values) as $i => $value) {
            $h = $value > 0 ? max(3, (int) round($value / $max * $height)) : 2;
            $label = (string) ($labels[$i] ?? '');
            $bars .= '<div title="' . e($label . ': ' . $value . ' ' . $unit) . '" style="flex:1;min-width:0;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:' . ($height + 14) . 'px">'
                . ($value > 0 ? '<div style="font-size:10px;opacity:.7;line-height:12px;margin-bottom:2px">' . $value . '</div>' : '')
                . '<div style="width:100%;height:' . $h . 'px;border-radius:3px 3px 0 0;background:' . ($value > 0 ? '#8b5cf6' : 'rgba(139,92,246,.18)') . '"></div></div>';
            $names .= '<div style="flex:1;text-align:center;font-size:10px;opacity:.6;overflow:hidden;white-space:nowrap">' . e($label) . '</div>';
        }

        return new HtmlString('<div style="display:flex;gap:3px">' . $bars . '</div><div style="display:flex;gap:3px;margin-top:4px">' . $names . '</div>');
    }

    /** Hour labels: every third hour, the rest empty. */
    public static function hourLabels(): array
    {
        return array_map(fn ($h) => $h % 3 === 0 ? sprintf('%02d', $h) : '', range(0, 23));
    }

    /** A list of rows as a small table: $columns label => fn(row) => string (escaped). */
    public static function list(array $rows, array $columns, string $empty = 'Nothing yet'): HtmlString
    {
        if (!$rows) {
            return new HtmlString('<p style="opacity:.6;font-size:14px">' . e($empty) . '</p>');
        }
        $head = '';
        foreach (array_keys($columns) as $i => $label) {
            $head .= '<th style="text-align:' . ($i === 0 ? 'left' : 'right') . ';font-weight:500;opacity:.6;padding:4px 8px;font-size:12px">' . e($label) . '</th>';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr style="border-top:1px solid rgba(127,127,127,.15)">';
            $i = 0;
            foreach ($columns as $render) {
                $cell = $render($row);
                $body .= '<td style="padding:6px 8px;font-size:14px;text-align:' . ($i++ === 0 ? 'left' : 'right') . '">' . ($cell instanceof HtmlString ? $cell->toHtml() : e((string) $cell)) . '</td>';
            }
            $body .= '</tr>';
        }

        return new HtmlString('<div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>');
    }
}
