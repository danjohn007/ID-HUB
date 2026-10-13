<?php

namespace App\Livewire\Captive;

use App\Livewire\Concerns\Toasts;
use App\Models\CaptiveAd;
use App\Models\CaptiveAdStat;
use App\Models\CaptiveSession;
use App\Models\CaptiveVoucher;
use App\Support\Audit;
use App\Support\InputRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Portal cautivo (sección 28): configuración, publicidad local, vouchers y sesiones.
 */
#[Title('Portal cautivo')]
class Index extends Component
{
    use Toasts, WithFileUploads, WithPagination;

    #[Url]
    public string $tab = 'configuracion';

    // ---------------- Configuración
    public array $settings = [];

    public $backgroundImage;

    // ---------------- Anuncios
    public bool $showAd = false;

    public ?int $adId = null;

    public array $ad = [];

    public $adImage;

    public string $statsAd = '';

    // ---------------- Vouchers
    public array $gen = ['quantity' => 20, 'duration_minutes' => 60, 'data_limit_mb' => '', 'price' => '10', 'expires_days' => 30, 'batch' => ''];

    public string $voucherStatus = '';

    public string $voucherBatch = '';

    public string $voucherSearch = '';

    // ---------------- Sesiones
    public string $sessionMethod = '';

    public string $sessionResult = '';

    public function mount(): void
    {
        $cfg = tenant()->settingsFor('captive');
        $this->settings = [
            'title' => (string) $cfg['title'], 'welcome' => (string) $cfg['welcome'], 'terms' => (string) $cfg['terms'],
            'methods' => array_values((array) $cfg['methods']), 'redirect_url' => (string) ($cfg['redirect_url'] ?? ''),
            'background' => (string) ($cfg['background'] ?? '') ?: '#0B1B3A', 'background_image' => $cfg['background_image'] ?? null,
            'show_ads' => (bool) $cfg['show_ads'], 'show_promotions' => (bool) $cfg['show_promotions'],
            'notice' => (string) ($cfg['notice'] ?? ''), 'contact' => (string) ($cfg['contact'] ?? ''),
            'show_plans' => (bool) ($cfg['show_plans'] ?? true),
            'hotspot_dns' => (string) ($cfg['hotspot_dns'] ?? ''),
        ];
        $this->ad = $this->blankAd();
        $this->gen['batch'] = 'LOTE-'.now()->format('ymd-Hi');
    }

    public function updating($property): void
    {
        if (in_array($property, ['voucherStatus', 'voucherBatch', 'voucherSearch'], true)) {
            $this->resetPage('vouchers');
        }
        if (in_array($property, ['sessionMethod', 'sessionResult'], true)) {
            $this->resetPage('sesiones');
        }
    }

    public function updatedStatsAd(): void
    {
        $this->dispatch('chart-data-ads-chart', options: $this->adsChart());
    }

    // ============================================================ Configuración

    public function saveSettings(): void
    {
        $this->validate([
            'settings.title' => 'required|string|max:80|'.InputRules::TEXT,
            'settings.welcome' => 'nullable|string|max:300',
            'settings.terms' => 'nullable|string|max:5000',
            'settings.methods' => 'required|array|min:1',
            'settings.methods.*' => [Rule::in(array_keys(config('wisp.growth.captive_login_methods')))],
            'settings.redirect_url' => 'nullable|url|max:300',
            'settings.background' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'settings.notice' => 'nullable|string|max:500',
            'settings.contact' => 'nullable|string|max:300',
            'settings.hotspot_dns' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9.-]+$/'],
            'backgroundImage' => 'nullable|image|max:4096',
        ], [], [
            'settings.title' => 'título', 'settings.methods' => 'métodos de acceso', 'settings.redirect_url' => 'URL de redirección',
            'settings.background' => 'color de fondo', 'backgroundImage' => 'imagen de fondo', 'settings.hotspot_dns' => 'nombre DNS del hotspot',
        ]);
        if ($this->backgroundImage) {
            if ($this->settings['background_image']) {
                Storage::disk('public')->delete($this->settings['background_image']);
            }
            $this->settings['background_image'] = $this->backgroundImage->store('captive/'.tenant()->id, 'public');
            $this->reset('backgroundImage');
        }
        $data = $this->settings;
        $data['redirect_url'] = $data['redirect_url'] ?: null;
        tenant()->putSettings('captive', $data);
        Audit::log('settings', 'actualizó la configuración del portal cautivo', tenant());
        $this->toast('Configuración del portal guardada');
    }

    public function removeBackground(): void
    {
        if ($this->settings['background_image']) {
            Storage::disk('public')->delete($this->settings['background_image']);
        }
        $this->settings['background_image'] = null;
        tenant()->putSettings('captive', ['background_image' => null]);
        $this->toast('Imagen de fondo eliminada');
    }

    public function loginHtml(): string
    {
        $url = route('captive.portal', tenant()->slug);

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Conectando…</title>
<meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="font-family:sans-serif;text-align:center;padding-top:40px">
\$(if error)<p style="color:#c00">\$(error)</p>\$(endif)
<p>Conectando con el portal…</p>
<form name="wc" action="{$url}" method="get">
  <input type="hidden" name="mac" value="\$(mac)">
  <input type="hidden" name="ip" value="\$(ip)">
  <input type="hidden" name="link-login-only" value="\$(link-login-only)">
  <input type="hidden" name="link-orig" value="\$(link-orig)">
  <input type="hidden" name="error" value="\$(error)">
  <noscript><button type="submit">Continuar</button></noscript>
</form>
<script>document.wc.submit();</script>
</body></html>
HTML;
    }

    // ============================================================ Anuncios

    protected function blankAd(): array
    {
        return [
            'advertiser' => '', 'business_type' => 'comercio', 'campaign' => '', 'title' => '', 'body' => '', 'url' => '',
            'starts_at' => now()->toDateString(), 'ends_at' => now()->addMonth()->toDateString(), 'weight' => '1', 'price' => '0', 'active' => true,
        ];
    }

    public function createAd(): void
    {
        $this->resetValidation();
        $this->reset('adImage', 'adId');
        $this->ad = $this->blankAd();
        $this->showAd = true;
    }

    public function editAd(int $id): void
    {
        $a = CaptiveAd::findOrFail($id);
        $this->resetValidation();
        $this->reset('adImage');
        $this->adId = $a->id;
        $this->ad = [
            'advertiser' => $a->advertiser, 'business_type' => $a->business_type, 'campaign' => (string) $a->campaign, 'title' => $a->title,
            'body' => (string) $a->body, 'url' => (string) $a->url, 'starts_at' => $a->starts_at?->toDateString() ?? '', 'ends_at' => $a->ends_at?->toDateString() ?? '',
            'weight' => (string) $a->weight, 'price' => (string) (float) $a->price, 'active' => (bool) $a->active,
        ];
        $this->showAd = true;
    }

    public function saveAd(): void
    {
        $data = $this->validate([
            'ad.advertiser' => 'required|string|max:120|'.InputRules::TEXT,
            'ad.business_type' => ['required', Rule::in(array_keys(config('wisp.ad_business_types')))],
            'ad.campaign' => 'nullable|string|max:120|'.InputRules::TEXT,
            'ad.title' => 'required|string|max:120',
            'ad.body' => 'nullable|string|max:190',
            'ad.url' => 'nullable|url|max:500',
            'ad.starts_at' => 'nullable|date',
            'ad.ends_at' => 'nullable|date|after_or_equal:ad.starts_at',
            'ad.weight' => 'required|integer|between:1,100',
            'ad.price' => 'nullable|numeric|min:0|max:1000000',
            'ad.active' => 'boolean',
            'adImage' => 'nullable|image|max:4096',
        ], [], [
            'ad.advertiser' => 'anunciante', 'ad.title' => 'título', 'ad.url' => 'URL', 'ad.weight' => 'peso', 'ad.price' => 'precio',
            'ad.ends_at' => 'fin', 'adImage' => 'imagen',
        ])['ad'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['price'] = (float) ($data['price'] ?? 0);
        $ad = $this->adId ? CaptiveAd::findOrFail($this->adId) : new CaptiveAd;
        if ($this->adImage) {
            if ($ad->image_path) {
                Storage::disk('public')->delete($ad->image_path);
            }
            $data['image_path'] = $this->adImage->store('captive/'.tenant()->id.'/ads', 'public');
        }
        $ad->fill($data)->save();
        $this->showAd = false;
        $this->reset('adImage');
        $this->toast($this->adId ? 'Anuncio actualizado' : 'Anuncio creado');
    }

    public function toggleAd(int $id): void
    {
        $a = CaptiveAd::findOrFail($id);
        $a->update(['active' => ! $a->active]);
        $this->toast($a->active ? 'Anuncio activado' : 'Anuncio pausado');
    }

    public function deleteAd(int $id): void
    {
        $a = CaptiveAd::findOrFail($id);
        if ($a->image_path) {
            Storage::disk('public')->delete($a->image_path);
        }
        $a->delete();
        $this->showAd = false;
        $this->toast('Anuncio eliminado', 'warning');
    }

    protected function adsChart(): array
    {
        $from = now()->subDays(29)->startOfDay();
        $adIds = $this->statsAd !== '' ? [(int) $this->statsAd] : CaptiveAd::query()->pluck('id')->all();
        $rows = CaptiveAdStat::query()->whereIn('captive_ad_id', $adIds ?: [0])->where('date', '>=', $from->toDateString())
            ->selectRaw('date, SUM(impressions) imp, SUM(clicks) clk')->groupBy('date')->get()
            ->keyBy(fn ($r) => \Illuminate\Support\Carbon::parse($r->date)->toDateString());
        $days = collect(range(0, 29))->map(fn ($i) => $from->copy()->addDays($i));

        return [
            'legend' => ['top' => 0, 'right' => 0],
            'grid' => ['left' => 8, 'right' => 12, 'top' => 32, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $days->map(fn ($d) => $d->format('d/m'))->all(), 'axisTick' => ['show' => false]],
            'yAxis' => [
                ['type' => 'value', 'name' => 'Impresiones', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
                ['type' => 'value', 'name' => 'Clics', 'splitLine' => ['show' => false]],
            ],
            'series' => [
                ['name' => 'Impresiones', 'type' => 'bar', 'barMaxWidth' => 14, 'itemStyle' => ['color' => '#BCDBFF', 'borderRadius' => [3, 3, 0, 0]],
                    'data' => $days->map(fn ($d) => (int) ($rows[$d->toDateString()]->imp ?? 0))->all()],
                ['name' => 'Clics', 'type' => 'line', 'yAxisIndex' => 1, 'smooth' => true, 'symbolSize' => 5, 'itemStyle' => ['color' => '#6048D0'],
                    'data' => $days->map(fn ($d) => (int) ($rows[$d->toDateString()]->clk ?? 0))->all()],
            ],
        ];
    }

    // ============================================================ Vouchers

    public function generateVouchers(): void
    {
        $this->validate([
            'gen.quantity' => 'required|integer|between:1,500',
            'gen.duration_minutes' => 'required|integer|between:5,525600',
            'gen.data_limit_mb' => 'nullable|integer|min:1|max:10000000',
            'gen.price' => 'nullable|numeric|min:0|max:100000',
            'gen.expires_days' => 'nullable|integer|between:1,3650',
            'gen.batch' => 'required|string|max:40|'.InputRules::CODE,
        ], [], ['gen.quantity' => 'cantidad', 'gen.duration_minutes' => 'duración', 'gen.data_limit_mb' => 'límite de datos', 'gen.batch' => 'lote']);

        $tid = tenant()->id;
        $existing = CaptiveVoucher::query()->pluck('code')->flip();
        $codes = [];
        while (count($codes) < (int) $this->gen['quantity']) {
            $code = strtoupper(Str::random(8));
            $code = strtr($code, ['0' => 'X', 'O' => 'Y', '1' => 'Z', 'I' => 'W', 'L' => 'K']);
            if (! isset($existing[$code]) && ! isset($codes[$code])) {
                $codes[$code] = true;
            }
        }
        $now = now();
        $rows = collect(array_keys($codes))->map(fn ($c) => [
            'tenant_id' => $tid, 'code' => $c, 'batch' => $this->gen['batch'], 'duration_minutes' => (int) $this->gen['duration_minutes'],
            'data_limit_mb' => $this->gen['data_limit_mb'] !== '' ? (int) $this->gen['data_limit_mb'] : null,
            'price' => (float) ($this->gen['price'] ?: 0), 'status' => 'available',
            'expires_at' => $this->gen['expires_days'] ? $now->copy()->addDays((int) $this->gen['expires_days']) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($rows->chunk(200) as $chunk) {
            DB::table('captive_vouchers')->insert($chunk->values()->all());
        }
        Audit::log('created', "generó {$rows->count()} vouchers (lote {$this->gen['batch']})", tenant());
        $this->voucherBatch = $this->gen['batch'];
        $this->gen['batch'] = 'LOTE-'.now()->addMinute()->format('ymd-Hi');
        $this->resetPage('vouchers');
        $this->toast("{$rows->count()} vouchers generados");
    }

    public function expireVoucher(int $id): void
    {
        CaptiveVoucher::where('status', 'available')->findOrFail($id)->update(['status' => 'expired']);
        $this->toast('Voucher anulado', 'warning');
    }

    public function deleteVoucher(int $id): void
    {
        CaptiveVoucher::where('status', '!=', 'used')->findOrFail($id)->delete();
        $this->toast('Voucher eliminado', 'warning');
    }

    public function expireOverdue(): void
    {
        $n = CaptiveVoucher::where('status', 'available')->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => 'expired']);
        $this->toast("$n voucher(s) marcados como expirados");
    }

    // ============================================================ Datos

    protected function sessionsChart(): array
    {
        $from = now()->subDays(29)->startOfDay();
        $rows = CaptiveSession::query()->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) d, method, COUNT(*) n')->groupBy(DB::raw('DATE(created_at)'), 'method')->get();
        $days = collect(range(0, 29))->map(fn ($i) => $from->copy()->addDays($i)->toDateString());
        $colors = ['usuario' => '#1A7FF5', 'codigo' => '#6048D0', 'voucher' => '#00A0A8'];

        return [
            'legend' => ['top' => 0, 'right' => 0],
            'grid' => ['left' => 8, 'right' => 12, 'top' => 32, 'bottom' => 8, 'containLabel' => true],
            'xAxis' => ['type' => 'category', 'data' => $days->map(fn ($d) => substr($d, 8, 2).'/'.substr($d, 5, 2))->all(), 'axisTick' => ['show' => false]],
            'yAxis' => ['type' => 'value', 'splitLine' => ['lineStyle' => ['type' => 'dashed', 'color' => '#E2E8F0']]],
            'series' => collect(config('wisp.growth.captive_session_methods'))->map(fn ($m, $key) => [
                'name' => $m[0], 'type' => 'bar', 'stack' => 'total', 'barMaxWidth' => 16, 'itemStyle' => ['color' => $colors[$key] ?? '#94A3B8'],
                'data' => $days->map(fn ($d) => (int) $rows->first(fn ($r) => (string) $r->d === $d && $r->method === $key)?->n)->all(),
            ])->values()->all(),
        ];
    }

    public function render()
    {
        $data = ['tenantSlug' => tenant()->slug];

        if ($this->tab === 'configuracion') {
            $data['portalUrl'] = route('captive.portal', tenant()->slug);
            $data['loginHtml'] = $this->loginHtml();
            $data['host'] = parse_url(url('/'), PHP_URL_HOST);
        }
        if ($this->tab === 'anuncios') {
            $ads = CaptiveAd::query()->orderByDesc('active')->latest()->get();
            $data['ads'] = $ads;
            $data['adTotals'] = ['impressions' => (int) $ads->sum('impressions'), 'clicks' => (int) $ads->sum('clicks'), 'active' => $ads->where('active', true)->count(),
                'revenue' => (float) $ads->where('active', true)->sum('price')];
            $data['adsChart'] = $this->adsChart();
            $data['last30'] = CaptiveAdStat::query()->whereIn('captive_ad_id', $ads->pluck('id')->all() ?: [0])->where('date', '>=', now()->subDays(29)->toDateString())
                ->selectRaw('captive_ad_id, SUM(impressions) imp, SUM(clicks) clk')->groupBy('captive_ad_id')->get()->keyBy('captive_ad_id');
        }
        if ($this->tab === 'vouchers') {
            $data['vouchers'] = CaptiveVoucher::query()
                ->when($this->voucherStatus, fn ($q) => $q->where('status', $this->voucherStatus))
                ->when($this->voucherBatch, fn ($q) => $q->where('batch', $this->voucherBatch))
                ->when(trim($this->voucherSearch) !== '', fn ($q) => $q->where(fn ($w) => $w->where('code', 'like', '%'.trim($this->voucherSearch).'%')->orWhere('mac', 'like', '%'.trim($this->voucherSearch).'%')))
                ->latest('id')->paginate(30, ['*'], 'vouchers');
            $data['voucherCounts'] = CaptiveVoucher::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
            $data['batches'] = CaptiveVoucher::query()->whereNotNull('batch')->selectRaw('batch, COUNT(*) n, MAX(id) last_id')->groupBy('batch')->orderByDesc('last_id')->limit(30)->get()
                ->mapWithKeys(fn ($b) => [$b->batch => $b->batch.' ('.$b->n.')']);
            $data['soldValue'] = (float) CaptiveVoucher::where('status', 'used')->sum('price');
        }
        if ($this->tab === 'sesiones') {
            $data['sessions'] = CaptiveSession::query()->with('client:id,code,first_name,last_name')
                ->when($this->sessionMethod, fn ($q) => $q->where('method', $this->sessionMethod))
                ->when($this->sessionResult !== '', fn ($q) => $q->where('success', $this->sessionResult === '1'))
                ->latest()->paginate(30, ['*'], 'sesiones');
            $stats = CaptiveSession::query()->where('created_at', '>=', now()->subDays(30))
                ->selectRaw('COUNT(*) total, SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) ok, COUNT(DISTINCT mac) macs')->first();
            $data['sessionStats'] = [
                'today' => CaptiveSession::query()->where('created_at', '>=', today())->count(),
                'total30' => (int) $stats->total, 'success' => $stats->total ? round($stats->ok / $stats->total * 100, 1) : 0, 'macs' => (int) $stats->macs,
            ];
            $data['sessionsChart'] = $this->sessionsChart();
            $data['byMethod'] = CaptiveSession::query()->where('created_at', '>=', now()->subDays(30))->selectRaw('method, COUNT(*) n')->groupBy('method')->pluck('n', 'method');
        }

        return view('livewire.captive.index', $data);
    }
}
