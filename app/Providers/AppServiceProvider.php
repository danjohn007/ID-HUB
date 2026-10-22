<?php

namespace App\Providers;

use App\Models;
use App\Models\User;
use App\Support\LivewireHandleRequests;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);

        // Soporte para servir la app desde una subcarpeta (ver App\Support\LivewireHandleRequests)
        $this->app->instance(HandleRequests::class, new LivewireHandleRequests);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MySQL 5.7 con utf8mb4: índices de hasta 191 caracteres.
        Schema::defaultStringLength(191);

        Carbon::setLocale('es');
        setlocale(LC_TIME, 'es_MX.UTF-8', 'es_ES.UTF-8', 'es');

        // Push a la app de campo cuando se asigna un ticket o una instalación.
        \App\Services\Messaging\StaffPush::register();

        Relation::enforceMorphMap([
            'client' => Models\Client::class,
            'ticket' => Models\Ticket::class,
            'installation_order' => Models\InstallationOrder::class,
            'collection_visit' => Models\CollectionVisit::class,
            'payment' => Models\Payment::class,
            'charge' => Models\Charge::class,
            'repeater' => Models\Repeater::class,
            'device' => Models\NetworkDevice::class,
            'tower' => Models\Tower::class,
            'prospect' => Models\Prospect::class,
            'inventory_item' => Models\InventoryItem::class,
            'zone' => Models\Zone::class,
            'user' => Models\User::class,
            'tenant' => Models\Tenant::class,
            'maintenance' => Models\Maintenance::class,
        ]);

        Gate::before(function (User $user, string $ability) {
            if (! $user->active) {
                return false;
            }
            // Módulos que dependen del plan SaaS del WISP (sección 48): sin la función contratada
            // se ocultan del menú y sus pantallas responden 403.
            $feature = \App\Models\SaasPlan::ABILITY_FEATURES[$ability] ?? null;
            if ($feature && ($tenant = tenant()) && ! $tenant->hasFeature($feature)) {
                return false;
            }
            if (array_key_exists($ability, config('permissions.abilities'))) {
                return $user->hasAbility($ability);
            }

            return null;
        });

        Gate::define('saas.manage', fn (User $user) => $user->isSuperadmin());

        // Las peticiones de Livewire re-aplican el requisito de WISP activo
        \Livewire\Livewire::addPersistentMiddleware([\App\Http\Middleware\RequireTenant::class]);

        // En una subcarpeta, el script de Livewire también debe pedirse con ella como prefijo
        if (! $this->app->runningInConsole() && ($base = request()->getBaseUrl())) {
            config(['livewire.asset_url' => $base.'/'.app(FrontendAssets::class)->javaScriptRoute->uri]);
        }
    }
}
