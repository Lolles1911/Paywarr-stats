<?php

namespace Paymenter\Extensions\Others\PaywarrStats\Concerns;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The year in review ("Wrapped"): from December 1 to January 31 a "Your
 * year" section on top of My stats; in the first week of December a mail and
 * a private Discord message to every active customer who watched something,
 * and one post with the server's year in Paywarr's announcements channel.
 * Part of PaywarrStats.
 */
trait Wrapped
{
    /** User: the year whose review was sent. */
    public const PROP_WRAPPED_SENT = 'paywarr_wrapped_sent';

    /** The year shown now (December: this year, January: last year), or null outside the season. */
    public static function wrappedYear(?int $now = null): ?int
    {
        $now ??= time();
        $month = (int) date('n', $now);

        return match ($month) {
            12 => (int) date('Y', $now),
            1 => (int) date('Y', $now) - 1,
            default => null,
        };
    }

    /** One customer's year from their media server accounts (as mediaAccounts() gives them), kept 6 hours. */
    public function yearFor(array $accounts, int $year): array
    {
        if (!$accounts) {
            return $this->summarizeYear([], $year);
        }
        $key = 'paywarr-stats:year:' . $year . ':' . md5(json_encode($accounts));

        return Cache::remember($key, 6 * 3600, function () use ($accounts, $year) {
            $plays = [];
            // Silo through Tracearr when it watches Silo (3.3+), else Silo itself.
            $viaTracearr = $this->tracearrHasSilo();
            $silo = $viaTracearr ? [] : array_values(array_filter($accounts, fn ($account) => $account['type'] === 'silo'));
            $others = $viaTracearr ? $accounts : array_values(array_filter($accounts, fn ($account) => $account['type'] !== 'silo'));
            if ($others && $this->configured('tracearr')) {
                foreach ($this->matchIdentities($others, $this->client()->users()) as $id) {
                    foreach ($this->client()->userHistory($id, 3000) as $play) {
                        $plays[] = $this->peopleTracearrPlay($play, []);
                    }
                }
            }
            if ($silo && $this->siloDirect()) {
                foreach ($silo as $account) {
                    if (ctype_digit((string) $account['id'])) {
                        foreach ($this->silo()->history((string) $account['id'], 2000) as $play) {
                            $plays[] = $this->peopleSiloPlay($play, ['who' => null, 'server' => 'Silo']);
                        }
                    }
                }
            }

            return $this->summarizeYear($plays, $year);
        });
    }

    /**
     * The numbers of a year: plays, hours, movies, episodes, days watched,
     * top titles, top genre, busiest month, favourite weekday, longest streak
     * and the first play of the year. Plays as PeopleStats reads them.
     */
    public function summarizeYear(array $plays, int $year): array
    {
        $plays = array_values(array_filter($plays, fn ($play) => (int) date('Y', $play['started']) === $year && ($play['ms'] >= 120000 || $play['watched'])));
        usort($plays, fn ($a, $b) => $a['started'] <=> $b['started']);
        $titles = [];
        $genres = [];
        $months = array_fill(1, 12, 0);
        $weekdays = array_fill(1, 7, 0);
        $days = [];
        $profiles = [];
        foreach ($plays as $play) {
            $profiles[(string) ($play['profile'] ?? '')] = ($profiles[(string) ($play['profile'] ?? '')] ?? 0) + (int) $play['ms'];
            $key = $play['type'] . '|' . strtolower($play['title']);
            $titles[$key] ??= ['title' => $play['title'], 'type' => $play['type'], 'plays' => 0, 'ms' => 0];
            $titles[$key]['plays']++;
            $titles[$key]['ms'] += $play['ms'];
            foreach ($play['genres'] ?? [] as $genre) {
                $genres[$genre] = ($genres[$genre] ?? 0) + 1;
            }
            $months[(int) date('n', $play['started'])]++;
            $weekdays[(int) date('N', $play['started'])]++;
            $days[date('Y-m-d', $play['started'])] = true;
        }
        uasort($titles, fn ($a, $b) => [$b['ms'], $b['plays']] <=> [$a['ms'], $a['plays']]);
        arsort($genres);
        $streak = 0;
        $run = 0;
        $previous = null;
        foreach (array_keys($days) as $day) {
            $run = $previous !== null && strtotime($day) - strtotime($previous) === 86400 ? $run + 1 : 1;
            $streak = max($streak, $run);
            $previous = $day;
        }

        return [
            'year' => $year,
            'plays' => count($plays),
            'ms' => array_sum(array_column($plays, 'ms')),
            'movies' => count(array_filter($plays, fn ($play) => $play['type'] === 'movie')),
            'episodes' => count(array_filter($plays, fn ($play) => $play['type'] === 'episode')),
            'days' => count($days),
            'top' => array_values(array_slice($titles, 0, 5)),
            'genre' => (string) (array_key_first($genres) ?? ''),
            'month' => $plays ? (int) array_search(max($months), $months, true) : null,
            'weekday' => $plays ? (int) array_search(max($weekdays), $weekdays, true) : null,
            'streak' => $streak,
            'first' => $plays ? ['title' => $plays[0]['title'], 'at' => $plays[0]['started']] : null,
            // The profile that watched the most, when the account has more than its main one.
            'profile' => count($profiles) > 1 || (count($profiles) === 1 && array_key_first($profiles) !== '')
                ? (string) array_search(max($profiles), $profiles, true) : null,
        ];
    }

    private function monthName(int $month): string
    {
        return match ($month) {
            1 => self::t('January'), 2 => self::t('February'), 3 => self::t('March'), 4 => self::t('April'), 5 => self::t('May'), 6 => self::t('June'),
            7 => self::t('July'), 8 => self::t('August'), 9 => self::t('September'), 10 => self::t('October'), 11 => self::t('November'), default => self::t('December'),
        };
    }

    private function weekdayName(int $day): string
    {
        return match ($day) {
            1 => self::t('Monday'), 2 => self::t('Tuesday'), 3 => self::t('Wednesday'), 4 => self::t('Thursday'), 5 => self::t('Friday'), 6 => self::t('Saturday'), default => self::t('Sunday'),
        };
    }

    /** "Your year" on top of My stats. */
    public function wrappedHtml(array $year): string
    {
        if ($year['plays'] === 0) {
            return '';
        }
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $facts = [
            [self::t('Watched'), $this->duration($year['ms'])],
            [self::t('Plays'), (string) $year['plays']],
            [self::t('Days with something on'), (string) $year['days']],
            [self::t('Longest streak'), $year['streak'] === 1 ? self::t('1 day') : self::t(':count days', ['count' => $year['streak']])],
        ];
        if ($year['month'] !== null) {
            $facts[] = [self::t('Busiest month'), $this->monthName($year['month'])];
        }
        if ($year['weekday'] !== null) {
            $facts[] = [self::t('Favourite day'), $this->weekdayName($year['weekday'])];
        }
        if ($year['genre'] !== '') {
            $facts[] = [self::t('Favourite genre'), $year['genre']];
        }
        if (($year['profile'] ?? null) !== null) {
            $facts[] = [self::t('Watched the most'), $year['profile'] !== '' ? $year['profile'] : self::t('Main profile')];
        }
        $html = '<section class="ps-card ps-wrapped" style="margin-bottom:1.25rem;background:linear-gradient(135deg,hsl(var(--color-primary)/.28),hsl(var(--color-primary)/.06));border-color:hsl(var(--color-primary)/.4)">'
            . '<div class="ps-label">' . $e(self::t('Your :year', ['year' => $year['year']])) . '</div>'
            . '<div class="ps-big" style="margin:.2rem 0 .8rem">' . $e(self::t(':hours hours of watching', ['hours' => number_format($year['ms'] / 3600000, 0, '', self::t(','))])) . '</div>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.8rem;margin-bottom:1rem">';
        foreach ($facts as [$label, $value]) {
            $html .= '<div><div class="ps-muted ps-tiny">' . $e($label) . '</div><div class="ps-title">' . $e($value) . '</div></div>';
        }
        $html .= '</div>';
        if ($year['top']) {
            $html .= '<div class="ps-label" style="margin-bottom:.4rem">' . $e(self::t('Your top :count', ['count' => count($year['top'])])) . '</div><ol style="margin:0;padding-left:1.3rem">';
            foreach ($year['top'] as $title) {
                $html .= '<li style="margin:.15rem 0"><span class="ps-title">' . $e($title['title']) . '</span> <span class="ps-muted ps-small">· ' . $e($this->duration($title['ms'])) . '</span></li>';
            }
            $html .= '</ol>';
        }
        if ($year['first']) {
            $html .= '<p class="ps-muted ps-small" style="margin-top:.8rem">' . $e(self::t('It started on :date with :title.', ['date' => self::t(':month :day', ['month' => $this->monthName((int) date('n', $year['first']['at'])), 'day' => date('j', $year['first']['at'])]), 'title' => $year['first']['title']])) . '</p>';
        }

        return $html . $this->wrappedImageButtons($year, $facts) . '</section>';
    }

    /** What the shareable image shows: the same as the section, short. */
    public function wrappedImageData(array $year, array $facts): array
    {
        $site = '';
        try {
            $site = (string) config('app.name');
            $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        } catch (\Throwable $e) {
            $host = '';
        }

        return [
            'label' => self::t('My :year', ['year' => $year['year']]),
            'hours' => number_format($year['ms'] / 3600000, 0, '', self::t(',')),
            'unit' => self::t('hours of watching'),
            // Without "Watched": the hours are the big number.
            'facts' => array_map(fn ($f) => [(string) $f[0], (string) $f[1]], array_slice($facts, 1, 6)),
            'topLabel' => self::t('My top :count', ['count' => '{n}']),
            'top' => array_map(fn ($t) => [(string) $t['title'], $this->duration((int) $t['ms'])], array_slice($year['top'], 0, 5)),
            'site' => $site,
            'host' => $host,
            'file' => strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', ($site !== '' ? $site . '-' : '') . 'wrapped-' . $year['year'])),
        ];
    }

    /** "Save as image": a story (1080x1920) and a square (1080x1080), drawn in the browser. */
    private function wrappedImageButtons(array $year, array $facts): string
    {
        $e = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $data = json_encode($this->wrappedImageData($year, $facts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $button = 'class="ps-wrapped-save" style="border:1px solid hsl(var(--color-primary)/.5);border-radius:.6rem;padding:.35rem .8rem;font-weight:600;background:hsl(var(--color-primary)/.15);cursor:pointer"';

        return '<div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-top:1rem"><span class="ps-muted ps-small">' . $e(self::t('Share your year:')) . '</span>'
            . '<button type="button" data-format="story" ' . $button . '>📱 ' . $e(self::t('Story')) . '</button>'
            . '<button type="button" data-format="square" ' . $button . '>⬛ ' . $e(self::t('Square')) . '</button></div>'
            . '<script type="application/json" id="ps-wrapped-data">' . $data . '</script>'
            . '<script>' . self::WRAPPED_IMAGE_JS . '</script>';
    }

    /** Draws the image on a canvas and shares it (phones) or downloads it. */
    private const WRAPPED_IMAGE_JS = <<<'JS'
(function(){
var el=document.getElementById('ps-wrapped-data');if(!el)return;var d=JSON.parse(el.textContent);
function colour(){var v=getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();return !v?'#db2777':/^(#|rgb|hsl|oklch)/.test(v)?v:'hsl('+v+')';}
function fit(c,t,w){if(c.measureText(t).width<=w)return t;while(t.length>1&&c.measureText(t+'…').width>w)t=t.slice(0,-1);return t+'…';}
function draw(fmt){
 var W=1080,H=fmt==='story'?1920:1080,s=fmt==='story',cv=document.createElement('canvas');cv.width=W;cv.height=H;var c=cv.getContext('2d');
 var font=getComputedStyle(document.body).fontFamily||'sans-serif',p=colour(),P=s?96:80;
 c.fillStyle='#0b0b12';c.fillRect(0,0,W,H);
 var g=c.createRadialGradient(W*.85,H*.1,0,W*.85,H*.1,H*.9);g.addColorStop(0,p);g.addColorStop(1,'rgba(11,11,18,0)');c.globalAlpha=.55;c.fillStyle=g;c.fillRect(0,0,W,H);
 var g2=c.createRadialGradient(0,H,0,0,H,H*.7);g2.addColorStop(0,p);g2.addColorStop(1,'rgba(11,11,18,0)');c.globalAlpha=.3;c.fillStyle=g2;c.fillRect(0,0,W,H);c.globalAlpha=1;
 c.textBaseline='alphabetic';c.fillStyle='#fff';
 var y=s?190:150;
 c.font='600 '+(s?40:34)+'px '+font;c.globalAlpha=.75;c.fillText(fit(c,d.site,W-2*P),P,y-(s?70:56));c.globalAlpha=1;
 c.font='800 '+(s?64:54)+'px '+font;c.fillText(fit(c,d.label,W-2*P),P,y);
 y+=s?260:190;c.font='900 '+(s?250:180)+'px '+font;c.fillText(d.hours,P,y);
 y+=s?80:64;c.font='600 '+(s?56:44)+'px '+font;c.globalAlpha=.9;c.fillText(d.unit,P,y);c.globalAlpha=1;
 var facts=d.facts.slice(0,s?6:4),cw=(W-2*P)/2,rh=s?150:118;y+=s?120:90;
 for(var i=0;i<facts.length;i++){var x=P+(i%2)*cw,yy=y+Math.floor(i/2)*rh;
  c.font='500 '+(s?34:28)+'px '+font;c.globalAlpha=.65;c.fillText(fit(c,facts[i][0],cw-30),x,yy);c.globalAlpha=1;
  c.font='800 '+(s?52:42)+'px '+font;c.fillText(fit(c,facts[i][1],cw-30),x,yy+(s?62:50));}
 y+=Math.ceil(facts.length/2)*rh+(s?50:20);
 var top=d.top.slice(0,s?5:3);
 if(top.length&&y<H-200){c.font='700 '+(s?40:32)+'px '+font;c.globalAlpha=.75;c.fillText(d.topLabel.replace('{n}',top.length),P,y);c.globalAlpha=1;y+=s?24:16;
  for(var j=0;j<top.length&&y<H-(s?190:120);j++){y+=s?84:64;
   c.fillStyle=p;c.font='900 '+(s?52:40)+'px '+font;c.fillText(String(j+1),P,y);c.fillStyle='#fff';
   c.font='700 '+(s?48:38)+'px '+font;var tw=c.measureText(top[j][1]).width;
   c.fillText(fit(c,top[j][0],W-2*P-(s?90:70)-tw-(s?190:150)),P+(s?70:56),y);
   c.font='500 '+(s?36:30)+'px '+font;c.globalAlpha=.65;var dw=c.measureText(top[j][1]).width;c.fillText(top[j][1],W-P-dw,y);c.globalAlpha=1;}}
 if(d.host){c.font='600 '+(s?38:30)+'px '+font;c.globalAlpha=.7;c.fillText(d.host,P,H-(s?96:64));c.globalAlpha=1;}
 c.fillStyle=p;c.fillRect(W-P-(s?120:90),H-(s?110:76),s?120:90,s?14:10);
 return cv;}
function save(fmt,btn){var cv=draw(fmt),name=d.file+'-'+fmt+'.png';
 cv.toBlob(function(b){if(!b)return;var f;try{f=new File([b],name,{type:'image/png'});}catch(e){}
  if(f&&navigator.canShare&&navigator.canShare({files:[f]})){navigator.share({files:[f]}).catch(function(){});return;}
  var a=document.createElement('a');a.href=URL.createObjectURL(b);a.download=name;document.body.appendChild(a);a.click();setTimeout(function(){URL.revokeObjectURL(a.href);a.remove();},1000);},'image/png');}
document.querySelectorAll('.ps-wrapped-save').forEach(function(b){b.addEventListener('click',function(){save(b.getAttribute('data-format'),b);});});
window.psWrappedDraw=draw;
})();
JS;

    /**
     * Daily, December 1 to 7: every active customer who watched something
     * this year gets their year by mail (and Discord), once; and Paywarr's
     * announcements channel gets the server's year, once.
     *
     * @return array{customers: int[], posted: bool}
     */
    public function sendWrapped(?int $now = null, ?iterable $services = null, ?callable $notify = null, ?callable $announce = null): array
    {
        $now ??= time();
        $result = ['customers' => [], 'posted' => false];
        if ((int) date('n', $now) !== 12 || (int) date('j', $now) > 7 || in_array((string) ($this->config('wrapped') ?? '1'), ['0', 'off', 'false'], true)) {
            return $result;
        }
        $year = (int) date('Y', $now);
        $paywarr = 'Paymenter\\Extensions\\Servers\\Wizarr\\Wizarr';
        $services ??= Service::with(['properties', 'product.server', 'user'])->where('status', 'active')->get();
        $byUser = [];
        foreach ($services as $service) {
            if (($service->product?->server?->extension ?? null) === 'Wizarr' && $service->user) {
                $byUser[$service->user_id ?? $service->user->id][] = $service;
            }
        }
        $notify ??= function ($service, string $title, string $text, array $button) use ($paywarr) {
            if (!class_exists($paywarr) || !method_exists($paywarr, 'notifyCustomer')) {
                return false;
            }

            // Paywarr 1.35+: tagged, so the customer can unsubscribe from just these.
            $kind = (new \ReflectionMethod($paywarr, 'notifyCustomer'))->getNumberOfParameters() >= 8 ? ['kind' => 'stats'] : [];

            return (new $paywarr([]))->forService($service)->notifyCustomer($service, $title, $text, $button, ...$kind) !== [];
        };
        $everyone = [];
        foreach ($byUser as $userId => $list) {
            $user = $list[0]->user;
            if ((string) ($this->propertiesOf($user)[self::PROP_WRAPPED_SENT] ?? '') === (string) $year) {
                continue;
            }
            try {
                $summary = $this->yearFor($this->mediaAccounts(array_map(fn ($service) => $this->propertiesOf($service), $list)), $year);
            } catch (Throwable $e) {
                Log::info('Paywarr Stats: the year of user ' . $userId . ' could not be read, will retry tomorrow: ' . $e->getMessage());

                continue;
            }
            $user->properties()->updateOrCreate(['key' => self::PROP_WRAPPED_SENT], ['name' => 'Year in review sent', 'value' => (string) $year]);
            if ($summary['plays'] === 0) {
                continue;
            }
            $top = $summary['top'][0]['title'] ?? '';
            $text = self::t('You watched :hours hours across :plays plays this year, on :days different days.', ['hours' => number_format($summary['ms'] / 3600000, 0), 'plays' => $summary['plays'], 'days' => $summary['days']])
                . ($top !== '' ? "\n\n" . self::t('Your number one: :title.', ['title' => $top]) : '')
                . "\n\n" . self::t('See your whole year in My stats.');
            if ($notify($list[0], self::t('Your :year in review', ['year' => $year]), $text, [self::t('See your year'), $this->statsUrl()])) {
                $result['customers'][] = $userId;
            }
        }
        if (Cache::get('paywarr-stats:wrapped-posted') !== $year) {
            $result['posted'] = $this->postServerYear($year, $announce);
            if ($result['posted']) {
                Cache::forever('paywarr-stats:wrapped-posted', $year);
            }
        }

        return $result;
    }

    /** One post with the server's year: hours, plays, viewers, top movies and shows. No names of customers. */
    public function serverYearText(array $plays, int $year): string
    {
        $plays = array_values(array_filter($plays, fn ($play) => (int) date('Y', $play['started']) === $year && ($play['ms'] >= 120000 || $play['watched'])));
        $viewers = count(array_unique(array_map(fn ($play) => strtolower($play['server_type'] . '|' . ($play['username'] ?? '')), $plays)));
        $top = ['movie' => [], 'episode' => []];
        foreach ($plays as $play) {
            if (isset($top[$play['type']])) {
                $top[$play['type']][$play['title']] = ($top[$play['type']][$play['title']] ?? 0) + 1;
            }
        }
        $text = '**' . self::t('Our :year together', ['year' => $year]) . "**\n"
            . self::t(':hours hours watched, :plays plays, :viewers viewers.', ['hours' => number_format(array_sum(array_column($plays, 'ms')) / 3600000, 0), 'plays' => count($plays), 'viewers' => $viewers]) . "\n";
        foreach (['movie' => self::t('Most watched movies'), 'episode' => self::t('Most watched shows')] as $type => $heading) {
            arsort($top[$type]);
            if ($top[$type]) {
                $text .= "\n__" . $heading . "__\n";
                $i = 0;
                foreach (array_slice($top[$type], 0, 5, true) as $title => $count) {
                    $text .= ++$i . '. ' . $title . "\n";
                }
            }
        }

        return $text;
    }

    private function postServerYear(int $year, ?callable $announce): bool
    {
        $paywarr = 'Paymenter\\Extensions\\Servers\\Wizarr\\Wizarr';
        $announce ??= function (string $text) use ($paywarr) {
            return class_exists($paywarr) && method_exists($paywarr, 'announce') && (new $paywarr([]))->announce($text);
        };
        try {
            $plays = [];
            if ($this->configured('tracearr')) {
                foreach ($this->client()->history(strtotime($year . '-01-01 00:00:00'), 50000) as $play) {
                    $plays[] = $this->peopleTracearrPlay($play, []);
                }
            }
            if ($this->siloDirect()) {
                foreach ($this->silo()->accounts() as $account) {
                    if (($account['role'] ?? '') === 'admin') {
                        continue;
                    }
                    foreach ($this->silo()->history((string) $account['id'], 2000) as $play) {
                        $plays[] = $this->peopleSiloPlay($play, ['who' => null, 'server' => 'Silo']);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::info('Paywarr Stats: the server\'s year could not be read, will retry tomorrow: ' . $e->getMessage());

            return false;
        }
        if (!$plays) {
            return false;
        }

        return (bool) $announce($this->serverYearText($plays, $year));
    }
}
