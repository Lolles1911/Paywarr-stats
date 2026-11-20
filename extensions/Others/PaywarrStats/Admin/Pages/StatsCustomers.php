<?php

namespace Paymenter\Extensions\Others\PaywarrStats\Admin\Pages;

use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Url;
use Paymenter\Extensions\Others\PaywarrStats\Admin\StatsPage;
use Throwable;

/**
 * Customers: one row per customer with what they watch, pay and ask for;
 * click one for their card (viewing, when, apps and devices, money,
 * requests, Discord and tickets).
 */
class StatsCustomers extends Page implements HasTable
{
    use InteractsWithTable;
    use StatsPage {
        getNavigationItems as private bothGroups;
    }

    protected static string|\BackedEnum|null $navigationIcon = 'ri-user-heart-line';

    protected static string|\UnitEnum|null $navigationGroup = 'Paywarr Stats';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Viewing per customer';

    protected static ?string $title = 'Viewing per customer';

    protected static ?string $slug = 'paywarr-stats-customers';

    /** The customer card: the Paymenter user ID. */
    #[Url]
    public ?int $customer = null;

    /** Also the Stats overview (the page with everyone's plays, in the client area) in "Paywarr Stats". */
    public static function getNavigationItems(): array
    {
        $items = self::bothGroups();
        try {
            $items[] = NavigationItem::make('Stats overview')->key('paywarr-stats-overview')->group('Paywarr Stats')
                ->icon('ri-dashboard-3-line')->sort(0)->url(route('extensions.paywarr-stats.admin'));
        } catch (Throwable $e) {
            // Without the route there is nothing to link to.
        }

        return $items;
    }

    public function getTitle(): string
    {
        $card = $this->customer ? $this->stats()->customer($this->customer) : null;

        return $card ? $card['name'] : 'Viewing per customer';
    }

    protected function getHeaderActions(): array
    {
        $paywarr = null;
        try {
            $paywarr = $this->customer ? route('filament.admin.pages.paywarr-customers', ['customer' => $this->customer]) : null;
        } catch (Throwable $e) {
            // Paywarr's admin pages are not there (Paywarr older than 1.24).
        }

        return [
            Action::make('back')->label('All customers')->icon('ri-arrow-left-line')->color('gray')
                ->visible(fn () => $this->customer !== null)->url(fn () => static::getUrl()),
            Action::make('paywarr')->label('Manage in Paywarr')->icon('ri-settings-3-line')->color('gray')
                ->visible($paywarr !== null)->url($paywarr),
            $this->refreshAction(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components($this->customer ? $this->card() : [EmbeddedTable::make()]);
    }

    /* ---------------- the table ---------------- */

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters, ?string $sortColumn, ?string $sortDirection) => $this->rows($search, $filters ?? [], $sortColumn, $sortDirection))
            ->paginated([25, 50, 100])
            ->defaultSort('ms_30', 'desc')
            ->searchable()
            ->recordUrl(fn (array $record) => static::getUrl(['customer' => $record['id']]))
            ->columns([
                TextColumn::make('name')->label('Customer')->sortable()
                    ->description(fn (array $record) => $record['email'] . ($record['discord'] ? ' · ' . $record['discord'] : '')),
                TextColumn::make('products')->label('Subscription')
                    ->state(fn (array $record) => implode(', ', $record['products']) ?: '—')
                    ->description(fn (array $record) => implode(', ', $record['servers'])),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (array $record) => $record['trial'] ? 'Trial' : ucfirst($record['status']))
                    ->color(fn (string $state) => match ($state) { 'Active' => 'success', 'Trial' => 'info', 'Suspended' => 'warning', default => 'gray' }),
                TextColumn::make('plays_30')->label('Plays (30 days)')->numeric()->sortable()->alignEnd(),
                TextColumn::make('ms_30')->label('Watch time')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state) => self::duration((int) $state)),
                TextColumn::make('trend')->label('Than before')->sortable()->alignEnd()->badge()
                    ->state(fn (array $record) => $record['ms_30'] === 0 && $record['ms_before'] === 0 ? '—' : self::trendText($record['trend']))
                    ->color(fn (array $record) => $record['trend'] === null ? 'gray' : ($record['trend'] <= -50 ? 'danger' : ($record['trend'] < 0 ? 'warning' : 'success')))
                    ->tooltip('Watch time over the last 30 days against the 30 days before'),
                TextColumn::make('last')->label('Last watched')->sortable()
                    ->formatStateUsing(fn ($state) => self::ago($state ? (int) $state : null))->placeholder('Never'),
                TextColumn::make('app')->label('Mostly in')->placeholder('—')
                    ->description(fn (array $record) => $record['devices'] > 1 ? $record['devices'] . ' devices' : null),
                TextColumn::make('paid_total')->label('Paid')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state, array $record) => self::money((float) $state, $record['currency']))
                    ->description(fn (array $record) => $record['unpaid'] ? $record['unpaid'] . ' unpaid' : null),
                TextColumn::make('requests')->label('Requests')->alignEnd()->toggleable()
                    ->state(fn (array $record) => $record['requests'] === null ? '—' : ($record['requests']['unlimited'] ? $record['requests']['used'] . ' (no limit)' : $record['requests']['used'] . ' of ' . $record['requests']['limit'])),
                TextColumn::make('since')->label('Customer since')->sortable()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state ? date('M j, Y', (int) $state) : '—'),
                TextColumn::make('tickets')->label('Tickets')->numeric()->alignEnd()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(['active' => 'Active', 'trial' => 'Trial', 'suspended' => 'Suspended', 'cancelled' => 'Cancelled']),
                SelectFilter::make('server')->label('Media server')->options(fn () => $this->serverOptions()),
                SelectFilter::make('watching')->label('Watching')->options([
                    'idle' => 'Nothing in 14 days', 'less' => 'Half or less than before', 'more' => 'More than before', 'unpaid' => 'With unpaid invoices',
                ]),
            ])
            ->emptyStateHeading('No customers here')
            ->emptyStateDescription('Customers are everyone with a Paywarr subscription.');
    }

    private function rows(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection): array
    {
        $rows = $this->stats()->customerRows();
        $status = $filters['status']['value'] ?? null;
        $server = $filters['server']['value'] ?? null;
        $watching = $filters['watching']['value'] ?? null;
        $rows = array_filter($rows, function ($row) use ($search, $status, $server, $watching) {
            if ($search && !str_contains(strtolower($row['name'] . ' ' . $row['email'] . ' ' . $row['discord'] . ' ' . implode(' ', $row['products'])), strtolower($search))) {
                return false;
            }
            if ($status && ($status === 'trial' ? !$row['trial'] : ($row['status'] !== $status || ($status === 'active' && $row['trial'])))) {
                return false;
            }
            if ($server && !in_array($server, $row['servers'], true)) {
                return false;
            }

            return match ($watching) {
                'idle' => $row['last'] === null || time() - $row['last'] >= 14 * 86400,
                'less' => $row['ms_before'] > 0 && $row['ms_30'] <= $row['ms_before'] / 2,
                'more' => $row['ms_30'] > $row['ms_before'],
                'unpaid' => $row['unpaid'] > 0,
                default => true,
            };
        });
        $column = in_array($sortColumn, ['name', 'plays_30', 'ms_30', 'trend', 'last', 'paid_total', 'since'], true) ? $sortColumn : 'ms_30';
        uasort($rows, function ($a, $b) use ($column) {
            $x = $a[$column] ?? null;
            $y = $b[$column] ?? null;

            return is_string($x) || is_string($y) ? strcasecmp((string) $x, (string) $y) : ($x ?? PHP_INT_MIN) <=> ($y ?? PHP_INT_MIN);
        });

        return $sortDirection === 'asc' || ($sortDirection === null && $column === 'name') ? $rows : array_reverse($rows, true);
    }

    private function serverOptions(): array
    {
        $servers = [];
        foreach ($this->stats()->customerRows() as $row) {
            foreach ($row['servers'] as $server) {
                $servers[$server] = $server;
            }
        }
        ksort($servers);

        return $servers;
    }

    /* ---------------- the card ---------------- */

    private function card(): array
    {
        $c = $this->stats()->customer($this->customer);
        if ($c === null) {
            return [Section::make('Not found')->description('This customer has no Paywarr subscription.')];
        }
        $requests = $c['requests'] === null ? 'No requests' : ($c['requests']['unlimited'] ? $c['requests']['used'] . ' used, no limit' : $c['requests']['used'] . ' of ' . $c['requests']['limit'] . ' used');

        return [
            Section::make('Customer')->columns(4)->schema([
                TextEntry::make('email')->label('Email')->state($c['email']),
                TextEntry::make('discord')->label('Discord')->state($c['discord'] ?? 'Not connected'),
                TextEntry::make('subscription')->label('Subscription')->state((implode(', ', $c['products']) ?: '—') . ' · ' . ($c['trial'] ? 'Trial' : ucfirst($c['status']))),
                TextEntry::make('since')->label('Customer since')->state($c['since'] ? date('M j, Y', $c['since']) . ' (' . max(1, (int) round((time() - $c['since']) / (30 * 86400))) . ' months)' : '—'),
            ]),
            Section::make('Viewing')->description('Watch time over the last 30 days against the 30 days before.')->columns(4)->schema([
                TextEntry::make('plays')->label('Plays, 30 days')->state($c['plays_30'] . ' (before: ' . $c['plays_before'] . ')'),
                TextEntry::make('time')->label('Watch time, 30 days')->state(self::duration($c['ms_30']) . ' (before: ' . self::duration($c['ms_before']) . ')'),
                TextEntry::make('trend')->label('Than before')->badge()->state($c['ms_30'] === 0 && $c['ms_before'] === 0 ? 'Nothing watched' : self::trendText($c['trend']))
                    ->color($c['trend'] === null ? 'gray' : ($c['trend'] <= -50 ? 'danger' : ($c['trend'] < 0 ? 'warning' : 'success'))),
                TextEntry::make('last')->label('Last watched')->state(self::ago($c['last'])),
                TextEntry::make('kinds')->label('Movies and episodes, 60 days')->state($c['movies'] . ' movies, ' . $c['episodes'] . ' episodes'),
                TextEntry::make('servers')->label('Watches on')->state(implode(', ', $c['servers_watched']) ?: '—'),
                TextEntry::make('genres')->label('Genres')->state(implode(', ', array_keys($c['genres'])) ?: '—')->columnSpan(2),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('When they watch')->description('Plays per hour of the day, 60 days')->schema([
                    Html::make(self::bars($c['hours'], self::hourLabels())),
                ]),
                Section::make('Weekdays')->description('Plays per weekday, 60 days')->schema([
                    Html::make(self::bars($c['weekdays'], ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])),
                ]),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Most watched')->description('60 days; a show counts its episodes together')->schema([
                    Html::make(self::list($c['titles'], [
                        'Title' => fn ($t) => $t['title'] . ($t['type'] === 'movie' ? ' (movie)' : ''),
                        'Plays' => fn ($t) => $t['plays'],
                        'Time' => fn ($t) => self::duration($t['ms']),
                    ], 'Nothing watched in 60 days')),
                ]),
                Section::make('Apps and devices')->description('60 days, as Tracearr and Silo see them')->schema([
                    Html::make(self::list($c['apps'], [
                        'App' => fn ($a) => new HtmlString(e($a['app'] ?: 'Unknown') . ($a['device'] !== '' || $a['platform'] !== '' ? '<br><span style="opacity:.6;font-size:12px">' . e(trim($a['device'] . ($a['platform'] !== '' && $a['platform'] !== $a['device'] ? ' · ' . $a['platform'] : ''))) . '</span>' : '')),
                        'Plays' => fn ($a) => $a['plays'],
                        'Transcoded' => fn ($a) => $a['transcode_share'] === null ? '—' : $a['transcode_share'] . '%',
                        'Last used' => fn ($a) => self::ago($a['last']),
                    ], 'No apps seen in 60 days')),
                ]),
            ]),
            Section::make('Latest plays')->collapsible()->schema([
                Html::make(self::list($c['recent'], [
                    'Title' => fn ($p) => new HtmlString(e($p['title']) . ($p['episode'] ? '<br><span style="opacity:.6;font-size:12px">' . e($p['episode']) . '</span>' : '')),
                    'Where' => fn ($p) => trim($p['server'] . ' · ' . ($p['app'] ?: '?'), ' ·'),
                    'Watched' => fn ($p) => self::duration($p['ms']) . ($p['watched'] ? ' ✓' : ''),
                    'When' => fn ($p) => date('M j, H:i', $p['started']),
                ], 'Nothing watched in 60 days')),
            ]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Section::make('Money')->schema([
                    TextEntry::make('paid')->label('Paid in total')->state(self::money($c['paid_total'], $c['currency']) . ' (' . $c['paid_count'] . ' invoices)'),
                    TextEntry::make('unpaid')->label('Unpaid')->state($c['unpaid'] ? $c['unpaid'] . ' invoice(s), ' . self::money($c['unpaid_total'], $c['currency']) : 'Nothing')
                        ->color($c['unpaid'] ? 'danger' : null),
                    Html::make(self::list($c['invoices'], [
                        'Invoice' => fn ($i) => '#' . $i['number'] . ($i['at'] ? ' · ' . date('M j, Y', $i['at']) : ''),
                        'Status' => fn ($i) => ucfirst($i['status']),
                        'Amount' => fn ($i) => self::money($i['total'], $i['currency']),
                    ], 'No invoices')),
                ]),
                Section::make('Requests, Discord and support')->schema([
                    TextEntry::make('requests')->label('Requests')->state($requests),
                    TextEntry::make('discord2')->label('Discord')->state($c['discord'] ? 'Connected as ' . $c['discord'] : 'Not connected'),
                    TextEntry::make('tickets')->label('Tickets')->state($c['tickets'] . ($c['open_tickets'] ? ' (' . $c['open_tickets'] . ' open)' : '')),
                    Html::make(self::list($c['ticket_list'], [
                        'Ticket' => fn ($t) => $t['subject'],
                        'Status' => fn ($t) => ucfirst($t['status']),
                        'When' => fn ($t) => $t['at'] ? date('M j, Y', $t['at']) : '',
                    ], 'No tickets')),
                ]),
            ]),
        ];
    }
}
