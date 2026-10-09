# WISPCONTROL — Guía de desarrollo

Plataforma SaaS multi-WISP en **Laravel 12 + Livewire 3 + Alpine + Tailwind 4**.
Restricciones de producción: **cPanel con PHP ≥ 8.2 y MySQL 5.7** (sin Node, sin Redis, sin workers permanentes).

## Entorno local

| Qué | Cómo |
|---|---|
| PHP (con zip, gd, intl, opcache) | `"/c/Users/j0301/OneDrive/Escritorio/ID HUB/.tools/php" artisan ...` (ejecutar desde `wispcontrol/`) |
| Composer | `../.tools/php ../.tools/composer.phar ...` |
| BD | MariaDB 10.4 de XAMPP (`root` sin contraseña), BD `wispcontrol` |
| Servidor | `http://127.0.0.1:8000` (ya corriendo, `../.tools/serve.sh`) |
| Vite | dev server corriendo en `:5173` (el CSS se regenera solo al crear vistas) |
| Usuarios demo | `admin@wispcontrol.com` / `password` (WISP Valle Verde). También `finanzas@`, `soporte@`, `supervisor@`, `tecnico@`, `cobrador@` (`@wispcontrol.com`), `superadmin@wispcontrol.com`, `admin@sierragorda.mx` |

**Nunca** ejecutar `migrate:fresh` / `db:wipe` (otros desarrolladores usan los datos). Para nuevas columnas/tablas crear una
migración nueva y correr `php artisan migrate`.

## Compatibilidad MySQL 5.7 (obligatorio)

- Sin CTE (`WITH`), sin funciones de ventana (`ROW_NUMBER() OVER`), sin `JSON_TABLE`, sin índices funcionales, sin `CHECK`.
- Columnas `json`/`text` **sin default**. Strings indexados ≤ 191 (ya configurado).
- `GROUP BY` debe incluir todas las columnas no agregadas (ONLY_FULL_GROUP_BY).
- Geografía: usar `haversine_km()` en PHP (no funciones espaciales).

## Arquitectura

```
app/Models            Modelos (todos los de un WISP extienden TenantModel → scope automático por tenant_id)
app/Services          Lógica de negocio (Billing, ServiceControl, MikroTik, Messaging, Automation, Alerts, Metrics...)
app/Livewire/<Mod>    Componentes de página (full-page Livewire)
resources/views/livewire/<mod>/   Vistas de esos componentes
resources/views/components/ui/    Kit de UI (x-ui.*)
routes/modules/<mod>.php   Rutas del WISP (ya dentro de auth + tenant). Un archivo por módulo.
routes/public/<mod>.php    Rutas públicas (pago, webhooks, portal cautivo, agente MikroTik)
routes/saas/<mod>.php      Panel superadmin (prefijo /saas, can:saas.manage)
routes/api/<mod>.php       API v1 (Sanctum)
routes/schedule/<mod>.php  Tareas programadas del módulo (Schedule::...)
config/wisp.php        Catálogos de estados (etiqueta + color) y defaults de configuración por WISP
config/permissions.php Permisos por rol
config/navigation.php  Menú lateral (los nombres de ruta ya están definidos; usarlos tal cual)
```

### Multi-tenant
- `tenant()` devuelve el WISP activo. Los modelos `TenantModel` filtran y asignan `tenant_id` solos.
- En consola/scheduler no hay tenant: iterar `Tenant::where('status','active')` y usar
  `app(TenantContext::class)->run($tenant, fn () => ...)`.
- `User` NO tiene scope automático: usar `User::forTenant()->active()->role('tecnico')`.
- Config del WISP: `tenant()->setting('billing.grace_days')`, `tenant()->settingsFor('messaging')`, `tenant()->putSettings('billing', [...])`.

### Permisos
`@can('clients.manage')`, `$this->authorize('billing.manage')`, rutas con `->middleware('can:tickets.view')`.
Lista en `config/permissions.php`.

### Servicios existentes (reutilizar, no duplicar)
- `BillingService`: `registerPayment($client, $amount, $method, $attrs)`, `createCharge()`, `generateCharges($tenant)`, `chargePeriod()`, `addLateFee()`, `paymentLinkFor($client)`, `recalculate()`, `overdueDays()`, `cancelPayment()`, `cancelCharge()`, `platformFee()`.
- `ServiceControl`: `suspend()`, `reconnect()`, `activate()`, `changePlan()`, `cancel()`, `routerFor($client)`, `onRouter()`.
- `MikroTik\MikroTikService::for($device)`: `test()`, `resources()`, `interfaces()`, `pppActive()`, `pppSecrets()`, `hotspotActive()`, `dhcpLeases()`, `ipAddresses()`, `registrations()`, `activeConnections()`, `clientConnection()`, `provisionClient()`, `suspendClient()`, `reactivateClient()`, `applyPlan()`, `removeClient()`. Modos: api / rest / push / simulated (`PushTransport::pullScript()`).
- `Messaging\MessageService`: `notify($client, $event, $vars, $channels)`, `queue($tenant, $channel, $to, $body, ...)`, `render()`, `clientVars()`, `phone()`.
- `AutomationEngine::fire($event, $subject, $context)` — eventos en `config('wisp.automation_events')`.
- `AlertService::raise($type, $severity, $title, $message, $subject)` / `resolve($dedupeKey)` / `resolveFor($type, $subject)`.
- `TicketService`: `create()`, `assign()`, `changeStatus()`.
- `RiskService::evaluate()/refresh()`, `CoverageService::check($lat, $lng)`, `ReferralService`, `MetricsService` (KPIs), `ReportExporter::download()`, `TenantProvisioner::provision()`.
- `App\Support\MapData` (marcadores para mapas), `Catalog::label/color/options`, `Audit::log()`.
- Helpers: `money()`, `pct()`, `initials()`, `haversine_km()`, `next_folio($table, $prefix)`.

## Convenciones de UI

- Página = componente Livewire con `#[Title('...')]` (layout `components.layouts.app` por defecto).
- Usar SIEMPRE el kit: `x-ui.page-header`, `x-ui.card`, `x-ui.stat` (sólido o `variant="soft"`), `x-ui.table` + `x-ui.th`,
  `x-ui.badge`, `x-ui.status catalog=".." :value=".."`, `x-ui.button`, `x-ui.input`, `x-ui.select`, `x-ui.textarea`,
  `x-ui.toggle`, `x-ui.modal wire:model="showX"`, `x-ui.tabs`, `x-ui.dropdown(-item)`, `x-ui.empty`, `x-ui.progress`,
  `x-ui.map`, `x-ui.chart`, `x-ui.search`, `x-ui.detail`, `x-ui.avatar`. Íconos: `<x-heroicon-o-users class="h-5 w-5"/>`.
- Listados: trait `App\Livewire\Concerns\WithTable` (búsqueda, orden, paginación) + `Toasts` (`$this->toast('Guardado')`).
- Ejemplo de referencia completo: `app/Livewire/Clients/*` y `resources/views/livewire/clients/*`.
- Colores semánticos: `brand`, `success`, `danger`, `warning`, `purple`, `teal`, `navy`, `slate`.
- Textos en **español**, fechas `d/m/Y`, dinero con `money()`.
- Responsivo: las pantallas de campo (técnico/cobrador) deben ser cómodas en celular.

### Gotchas conocidos
- **No envolver `<x-slot:...>` en `@if`/`@can`**: rompe el compilador. Poner la condición DENTRO del slot.
- `x-ui.map` y `x-ui.chart` usan `wire:ignore`; para actualizarlos desde PHP: `$this->dispatch('map-data-<id>', data: [...])` / `$this->dispatch('chart-data-<id>', options: [...])`.
- Rutas que aún no existen: proteger enlaces con `Route::has('nombre')`.
- Tras crear vistas, validar: `php artisan view:cache` y luego `php -l` sobre `storage/framework/views/*.php`.

## Verificación

```bash
S=/c/Users/j0301/AppData/Local/Temp/claude/c--Users-j0301-OneDrive-Escritorio-ID-HUB/49be0efa-ffdc-41fb-853b-46cd84b9d19a/scratchpad
QA_URLS="/ruta1,/ruta2" QA_EMAIL=admin@wispcontrol.com QA_SHOT=$S/x.png \
  node /c/Users/j0301/.claude/skills/browser-automation/browser.mjs http://127.0.0.1:8000/login --script $S/qa-login.mjs --timeout 60000
```
Devuelve status HTTP, `h1` y errores de consola/500 por página; el PNG se puede leer para revisar el diseño.
Errores de servidor: `storage/logs/laravel.log`.
