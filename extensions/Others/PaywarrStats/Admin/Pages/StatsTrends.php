<?php

namespace Paymenter\Extensions\Others\PaywarrStats\Admin\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Others\PaywarrStats\Admin\StatsPage;

/**
 * Rankings and trends: who watches most, who watches much less than before
 * (they may be about to stop), who watches more, plays per week, and new and
 * cancelled subscriptions per month.
 */
class StatsTrends extends Page
{
    use StatsPage;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-line-chart-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr Stats';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Rankings and trends';

    protected static ?string $title = 'Rankings and trends';

    protected static ?string $slug = 'paywarr-stats-trends';

    protected function getHeaderActions(): array
    {
        return [$this->refreshAction()];
    }

    public function content(Schema $schema): Schema
    {
        $t = $this->stats()->trends($this->people()['subscriptions']);
        $link = fn (array $row) => new HtmlString('<a href="' . e(StatsCustomers::getUrl(['customer' => $row['id']])) . '" style="text-decoration:underline">' . e($row['name']) . '</a>');
        $weekLabels = array_map(fn ($start) => date('M j', $start), array_keys($t['weeks']));
        $monthLabels = array_map(fn ($month) => date('M', strtotime($month . '-01')), array_keys($t['months']));

        return $schema->components([
            Section::make()->columns(['default' => 2, 'lg' => 4])->schema([
                TextEntry::make('paying')->label('Paying customers')->state((string) $t['paying']),
                TextEntry::make('watching')->label('Watched in 30 days')->state($t['watching'] . ' of ' . $t['paying']),
                TextEntry::make('idle')->label('Nothing in 14 days')->state((string) $t['idle_14'])->color($t['idle_14'] ? 'warning' : null),
                TextEntry::make('less')->label('Watching half or less than before')->state((string) count($t['less']))->color($t['less'] ? 'danger' : null),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Watching less')->description('Paying customers who watched an hour or more in the 30 days before, and half or less in the last 30. They may be about to stop.')->schema([
                    Html::make(self::list($t['less'], [
                        'Customer' => $link,
                        'Before' => fn ($r) => self::duration($r['ms_before']),
                        'Now' => fn ($r) => self::duration($r['ms_30']),
                        'Last watched' => fn ($r) => self::ago($r['last']),
                    ], 'Nobody: everyone watches about as much as before')),
                ]),
                Section::make('Top viewers')->description('Watch time, last 30 days')->schema([
                    Html::make(self::list($t['top'], [
                        'Customer' => $link,
                        'Plays' => fn ($r) => $r['plays_30'],
                        'Time' => fn ($r) => self::duration($r['ms_30']),
                        'Than before' => fn ($r) => self::trendText($r['trend']),
                    ], 'Nothing watched in 30 days')),
                ]),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Plays per week')->description('The last 8 weeks, everyone together')->schema([
                    Html::make(self::bars(array_column($t['weeks'], 'plays'), $weekLabels)),
                ]),
                Section::make('Viewers per week')->description('People who watched something that week')->schema([
                    Html::make(self::bars(array_column($t['weeks'], 'viewers'), $weekLabels, 'viewers')),
                ]),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('New subscriptions per month')->schema([
                    Html::make(self::bars(array_column($t['months'], 'new'), $monthLabels, 'new')),
                ]),
                Section::make('Cancelled per month')->schema([
                    Html::make(self::bars(array_column($t['months'], 'cancelled'), $monthLabels, 'cancelled')),
                ]),
            ]),
            Section::make('Watching more')->description('An hour or more in the last 30 days, and at least twice as much as before')->collapsible()->schema([
                Html::make(self::list($t['more'], [
                    'Customer' => $link,
                    'Before' => fn ($r) => self::duration($r['ms_before']),
                    'Now' => fn ($r) => self::duration($r['ms_30']),
                ], 'Nobody yet')),
            ]),
        ]);
    }
}
