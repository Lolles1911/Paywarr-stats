<?php

namespace Paymenter\Extensions\Others\PaywarrStats\Admin\Pages;

use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Others\PaywarrStats\Admin\StatsPage;

/**
 * Servers: one tab per media server with its viewers, busiest hours, apps,
 * most watched titles, converted streams and most streams at once, over the
 * last 30 days.
 */
class StatsServers extends Page
{
    use StatsPage;

    protected static string|\BackedEnum|null $navigationIcon = 'ri-server-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr Stats';

    protected static ?int $navigationSort = 402;

    protected static ?string $navigationLabel = 'Viewing per server';

    protected static ?string $title = 'Viewing per server';

    protected static ?string $slug = 'paywarr-stats-servers';

    protected function getHeaderActions(): array
    {
        return [$this->refreshAction()];
    }

    public function content(Schema $schema): Schema
    {
        $servers = $this->stats()->servers();
        if (!$servers) {
            return $schema->components([Section::make('Nothing watched in the last 30 days')
                ->description('Plays come from Tracearr (Jellyfin, Emby, Plex) and from Silo, as set up on the Paywarr Stats extension.')]);
        }
        $customerUrl = fn ($who) => StatsCustomers::getUrl(['customer' => $who]);
        $tabs = [];
        foreach ($servers as $server) {
            $tabs[] = Tab::make($server['name'])->badge((string) $server['viewers'])->schema([
                Section::make()->columns(['default' => 2, 'lg' => 6])->schema([
                    TextEntry::make('type')->label('Kind')->state(ucfirst($server['type'] ?: '?')),
                    TextEntry::make('viewers')->label('Viewers')->state($server['viewers'] . ' (' . $server['customers'] . ' customers)'),
                    TextEntry::make('plays')->label('Plays')->state((string) $server['plays']),
                    TextEntry::make('time')->label('Watch time')->state(self::duration($server['ms'])),
                    TextEntry::make('peak')->label('Most at once')->state($server['peak']['streams'] . ($server['peak']['at'] ? ' (' . date('M j, H:i', $server['peak']['at']) . ')' : '')),
                    TextEntry::make('converted')->label('Transcoded')->state($server['transcode_share'] === null ? 'Unknown' : $server['transcode_share'] . '% of plays')
                        ->tooltip('Converted by the server instead of played as is: more work for the server'),
                ]),
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Section::make('Busiest hours')->description('Plays started per hour of the day')->schema([Html::make(self::bars($server['hours'], self::hourLabels()))]),
                    Section::make('Weekdays')->description('Plays per weekday')->schema([Html::make(self::bars($server['weekdays'], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']))]),
                ]),
                Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Section::make('Who watches most')->schema([Html::make(self::list($server['top_viewers'], [
                        'Viewer' => fn ($v) => $v['who'] !== null
                            ? new HtmlString('<a href="' . e($customerUrl($v['who'])) . '" style="text-decoration:underline">' . e($v['name']) . '</a>')
                            : new HtmlString(e($v['name']) . ' <span style="opacity:.6;font-size:12px">(no customer)</span>'),
                        'Time' => fn ($v) => self::duration($v['ms']),
                    ]))]),
                    Section::make('Most watched')->schema([Html::make(self::list($server['titles'], [
                        'Title' => fn ($t) => $t['title'],
                        'Plays' => fn ($t) => $t['plays'],
                        'Viewers' => fn ($t) => $t['viewers'],
                    ]))]),
                    Section::make('Apps')->schema([Html::make(self::list(array_map(fn ($app, $plays) => ['app' => $app, 'plays' => $plays], array_keys($server['apps']), $server['apps']), [
                        'App' => fn ($a) => $a['app'],
                        'Plays' => fn ($a) => $a['plays'],
                        'Share' => fn ($a) => (int) round($a['plays'] / max(1, $server['plays']) * 100) . '%',
                    ]))]),
                ]),
            ]);
        }

        return $schema->components([Tabs::make('servers')->tabs($tabs)->persistTabInQueryString()]);
    }
}
