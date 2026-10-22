<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\CaptiveAd;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Services\CaptivePortalService;
use App\Support\InputRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Portal cautivo público por WISP (sección 28). El login.html del hotspot MikroTik redirige aquí
 * con mac, ip, link-login-only y link-orig; al validar el acceso se devuelven las credenciales
 * al MikroTik para que habilite la navegación.
 */
class CaptivePortalController extends Controller
{
    public function __construct(protected CaptivePortalService $portal) {}

    protected function resolve(string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        abort_unless($tenant && $tenant->isActive() && $tenant->hasFeature('portal_cautivo'), 404);
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }

    /** Parámetros que envía el MikroTik y que viajan en el formulario. */
    protected function hotspot(Request $request): array
    {
        $mac = (string) $request->input('mac', '');

        return [
            'mac' => preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $mac) ? strtoupper(str_replace('-', ':', $mac)) : '',
            'ip' => filter_var($request->input('ip'), FILTER_VALIDATE_IP) ?: '',
            'link-login-only' => mb_substr((string) $request->input('link-login-only', ''), 0, 300),
            'link-orig' => mb_substr((string) $request->input('link-orig', ''), 0, 500),
        ];
    }

    protected function page(Tenant $tenant, Request $request, array $extra = [])
    {
        $cfg = $tenant->settingsFor('captive');
        $methods = array_values(array_intersect((array) $cfg['methods'], array_keys(config('wisp.growth.captive_login_methods'))));

        return view('public.captive.portal', $extra + [
            'tenant' => $tenant,
            'cfg' => $cfg,
            'methods' => $methods ?: ['codigo'],
            'method' => old('method', $request->input('method', $methods[0] ?? 'codigo')),
            'hotspot' => $this->hotspot($request),
            'mikrotikError' => mb_substr((string) $request->input('error', ''), 0, 200),
            'background' => ($cfg['background'] ?? null) ?: '#0B1B3A',
            'backgroundImage' => ! empty($cfg['background_image']) ? Storage::disk('public')->url($cfg['background_image']) : null,
            'ads' => ($cfg['show_ads'] ?? true) ? $this->portal->pickAds(3) : collect(),
            'promotions' => ($cfg['show_promotions'] ?? true) ? Promotion::query()->current()->where('show_in_portal', true)->limit(3)->get() : collect(),
            'plans' => ($cfg['show_plans'] ?? true) ? Plan::query()->where('active', true)->where('public', true)->orderBy('price')->limit(4)->get() : collect(),
        ]);
    }

    public function show(Request $request, string $slug)
    {
        return $this->page($this->resolve($slug), $request);
    }

    public function login(Request $request, string $slug)
    {
        $tenant = $this->resolve($slug);
        $cfg = $tenant->settingsFor('captive');
        $request->validate([
            'method' => ['required', 'in:'.implode(',', (array) $cfg['methods'])],
            'accept' => $cfg['terms'] ? 'accepted' : 'nullable',
            'username' => ['nullable', 'string', 'max:80', InputRules::USERNAME],
            'password' => 'nullable|string|max:80',
            'code' => ['nullable', 'string', 'max:30', InputRules::CODE],
            'phone4' => ['nullable', 'digits:4'],
            'voucher' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\s\-]+$/'],
        ], ['accept.accepted' => 'Debes aceptar los términos de uso.'], ['username' => 'usuario', 'password' => 'contraseña', 'code' => 'código de cliente', 'phone4' => 'últimos 4 dígitos', 'voucher' => 'código de ficha']);

        $hotspot = $this->hotspot($request);
        $result = $this->portal->authenticate($tenant, $request->input('method'), $request->only('username', 'password', 'code', 'phone4', 'voucher'), $hotspot['mac'] ?: null, $hotspot['ip'] ?: $request->ip());

        if (! $result['ok']) {
            return $this->page($tenant, $request, ['loginError' => $result['error']]);
        }

        $destination = ($cfg['redirect_url'] ?? null) ?: ($hotspot['link-orig'] ?: null);

        return view('public.captive.connect', [
            'tenant' => $tenant,
            'loginUrl' => $this->portal->safeLoginUrl($tenant, $hotspot['link-login-only']),
            'username' => $result['username'],
            'password' => $result['password'],
            'destination' => $destination && filter_var($destination, FILTER_VALIDATE_URL) ? $destination : null,
            'client' => $result['client'],
        ]);
    }

    /** Clic en un anuncio: se registra y se redirige al sitio del anunciante. */
    public function ad(string $slug, int $ad)
    {
        $this->resolve($slug);
        $ad = CaptiveAd::query()->whereNotNull('url')->findOrFail($ad);
        $this->portal->track($ad, 'clicks');

        return redirect()->away($ad->url);
    }
}
