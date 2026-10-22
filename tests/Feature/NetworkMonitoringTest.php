<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\InventoryItem;
use App\Models\NetworkDevice;
use App\Models\Outage;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\ServiceEvent;
use App\Models\Tenant;
use App\Models\Tower;
use App\Services\AutomationEngine;
use App\Services\InstallationService;
use App\Services\InventoryService;
use App\Services\MikroTik\MikroTikService;
use App\Services\MikroTik\PushTransport;
use App\Services\Network\MonitoringService;
use App\Services\Network\PingProbe;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Monitoreo de red con ping desde el MikroTik (REST simulado con Http::fake), señal de RouterOS v7 (wifi),
 * caída de repetidor sin MikroTik propio, consumo mensual con límite del plan, nuevas acciones de
 * automatización, CPE al activar una instalación y alerta de sobre-existencia de inventario.
 */
class NetworkMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected NetworkDevice $router;

    /** IP => tiempos de respuesta en ms (null = no responde). */
    protected array $pingTable = [];

    /** Filas de /queue/simple que devuelve el MikroTik falso. */
    protected array $queues = [];

    /** Sesiones PPPoE activas del MikroTik falso. */
    protected array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::create(['name' => 'WISP Red', 'slug' => 'wisp-red']);
        app(TenantContext::class)->set($this->tenant);
        $this->router = NetworkDevice::create([
            'type' => 'mikrotik', 'name' => 'MK-Torre', 'ip' => '10.0.0.1', 'api_mode' => 'rest', 'api_port' => 80,
            'api_user' => 'admin', 'api_password' => 'x', 'service_method' => 'pppoe', 'status' => 'online',
        ]);
        $this->pingTable = ['8.8.8.8' => [20.0, 22.0, 24.0]];
        $this->fakeRouter();
    }

    /** MikroTik REST falso: responde según las propiedades del test. */
    protected function fakeRouter(): void
    {
        Http::fake(function (HttpRequest $request) {
            $path = trim(parse_url($request->url(), PHP_URL_PATH), '/');
            $path = preg_replace('#^rest/#', '', $path);
            $method = $request->method();

            if ($method === 'POST' && $path === 'ping') {
                return Http::response($this->pingRows((string) $request['address'], (int) $request['count']));
            }
            if ($method !== 'GET') {
                return Http::response([]);
            }

            return match ($path) {
                'system/resource' => Http::response([['cpu-load' => '12', 'free-memory' => '150000000', 'total-memory' => '268435456', 'uptime' => '3d', 'version' => '7.15.3 (stable)', 'board-name' => 'CCR2004']]),
                'ppp/active' => Http::response($this->sessions),
                'interface/wireless/registration-table', 'interface/wifiwave2/registration-table' => Http::response(['error' => 400, 'message' => 'Bad Request', 'detail' => 'no such command or directory (wireless)'], 400),
                'interface/wifi/registration-table' => Http::response([['mac-address' => 'AA:BB:CC:00:00:01', 'signal' => '-67', 'tx-rate' => '144.4Mbps', 'uptime' => '2h']]),
                'queue/simple' => Http::response($this->queues),
                default => Http::response([]),
            };
        });
    }

    /** Salida de /ping de la REST API (acumulados en cada fila, como RouterOS v7). */
    protected function pingRows(string $ip, int $count): array
    {
        $times = $this->pingTable[$ip] ?? null;
        $rows = [];
        $ok = [];
        for ($i = 0; $i < $count; $i++) {
            $t = $times[$i] ?? null;
            $row = ['host' => $ip, 'seq' => (string) $i, 'sent' => (string) ($i + 1)];
            if ($t !== null) {
                $ok[] = $t;
                $row += ['size' => '56', 'ttl' => '64', 'time' => $t.'ms'];
            } else {
                $row['status'] = 'timeout';
            }
            $row['received'] = (string) count($ok);
            $row['packet-loss'] = (string) (int) round(($i + 1 - count($ok)) / ($i + 1) * 100);
            if ($ok) {
                $row['avg-rtt'] = (array_sum($ok) / count($ok)).'ms';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    protected function poll(): array
    {
        return app(MonitoringService::class)->pollTenant($this->tenant);
    }

    // ------------------------------------------------------------------ Ping

    public function test_ping_output_is_parsed_for_api_rest_and_push_formats(): void
    {
        $api = [
            ['!type' => '!re', 'host' => '10.0.0.2', 'time' => '1ms500us', 'sent' => '1', 'received' => '1', 'avg-rtt' => '1ms500us'],
            ['!type' => '!re', 'host' => '10.0.0.2', 'status' => 'timeout', 'sent' => '2', 'received' => '1', 'avg-rtt' => '1ms500us'],
            ['!type' => '!re', 'host' => '10.0.0.2', 'time' => '2ms500us', 'sent' => '3', 'received' => '2', 'avg-rtt' => '2ms'],
            ['!type' => '!done'],
        ];
        $this->assertSame(['latency' => 2.0, 'loss' => 33.33, 'sent' => 3, 'received' => 2], MikroTikService::parsePing($api, 3));

        // Agente push (as-value, sin acumulados)
        $push = [['host' => '1.1.1.1', 'time' => '00:00:00.012'], ['host' => '1.1.1.1', 'time' => '00:00:00.018'], ['host' => '1.1.1.1', 'status' => 'timeout']];
        $this->assertSame(['latency' => 15.0, 'loss' => 33.33, 'sent' => 3, 'received' => 2], MikroTikService::parsePing($push));

        $dead = MikroTikService::parsePing([['host' => '10.9.9.9', 'status' => 'timeout', 'sent' => '3', 'received' => '0', 'packet-loss' => '100']]);
        $this->assertNull($dead['latency']);
        $this->assertSame(100.0, $dead['loss']);

        $this->assertSame(1.5, MikroTikService::durationMs('1ms500us'));
        $this->assertSame(0.85, MikroTikService::durationMs('850us'));
        $this->assertSame(1200.0, MikroTikService::durationMs('1s200ms'));

        // Lista de destinos del agente push: el servidor la entrega en la descarga de comandos
        Cache::put(PushTransport::targetsKey($this->router->id), ['10.0.1.2', 'no-es-ip'], now()->addHour());
        $this->assertStringContainsString(':global idhubPingTargets {"10.0.1.2"}', PushTransport::pullScript($this->router));
        $results = app(PingProbe::class)->pushResults(['ping_targets' => ['10.0.1.2' => [['host' => '10.0.1.2', 'time' => '3ms'], ['host' => '10.0.1.2', 'status' => 'timeout'], ['host' => '10.0.1.2', 'time' => '5ms']]]]);
        $this->assertSame(['latency' => 4.0, 'loss' => 33.33, 'sent' => 3, 'received' => 2], $results['10.0.1.2']);
        $this->assertSame(['latency' => null, 'loss' => null, 'sent' => 0, 'received' => 0], MikroTikService::parsePing([['!type' => '!done']], 3));
    }

    public function test_router_pings_devices_and_repeater_and_stores_latency_and_loss(): void
    {
        $repeater = Repeater::create(['code' => 'R-01', 'name' => 'Cerro', 'router_device_id' => $this->router->id, 'management_ip' => '10.0.1.2']);
        $ap = NetworkDevice::create(['type' => 'ap', 'name' => 'AP Cerro', 'ip' => '10.0.1.3', 'repeater_id' => $repeater->id, 'status' => 'unknown']);
        $switch = NetworkDevice::create(['type' => 'switch', 'name' => 'Switch Cerro', 'ip' => '10.0.1.4', 'repeater_id' => $repeater->id, 'status' => 'online']);
        $this->pingTable += ['10.0.1.2' => [4.0, 6.0, 8.0], '10.0.1.3' => [2.0, null, 4.0], '10.0.1.4' => [null, null, null]];

        $summary = $this->poll();

        $this->assertSame(1, $summary['routers_ok']);
        $this->router->refresh();
        $this->assertEquals(22.0, (float) $this->router->latency_ms); // ping al upstream 8.8.8.8, no tiempo de la API
        $this->assertEquals(0.0, (float) $this->router->packet_loss);

        $ap->refresh();
        $this->assertSame('warning', $ap->status); // responde, pero con 33% de pérdida (máximo 5%)
        $this->assertEquals(3.0, (float) $ap->latency_ms);
        $this->assertEquals(33.33, (float) $ap->packet_loss);
        $this->assertNotNull($ap->last_seen_at);
        $this->assertTrue(Alert::query()->open()->where('type', 'packet_loss')->where('subject_id', $ap->id)->exists());

        $switch->refresh();
        $this->assertSame('offline', $switch->status);
        $this->assertEquals(100.0, (float) $switch->packet_loss);
        $this->assertTrue(Alert::query()->open()->where('type', 'device_offline')->where('subject_type', $switch->getMorphClass())->where('subject_id', $switch->id)->exists());

        $repeater->refresh();
        $this->assertEquals(6.0, (float) $repeater->latency_ms);
        $this->assertEquals(0.0, (float) $repeater->packet_loss);
        $this->assertNotNull($repeater->last_ping_at);

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/rest/ping') && $r['address'] === '10.0.1.3' && $r['count'] === '3');
    }

    public function test_ping_targets_are_bounded_per_router_and_rotate(): void
    {
        $repeater = Repeater::create(['code' => 'R-02', 'name' => 'Valle', 'router_device_id' => $this->router->id, 'management_ip' => '10.0.2.1']);
        foreach (range(10, 14) as $n) {
            NetworkDevice::create(['type' => 'cpe', 'name' => "CPE {$n}", 'ip' => "10.0.2.{$n}", 'repeater_id' => $repeater->id, 'status' => 'unknown']);
        }
        $probe = app(PingProbe::class);
        $plan = $probe->plan(collect([$this->router]), [$this->router->id => ['ok' => true]])[$this->router->id];
        $this->assertCount(6, $plan);

        $first = $probe->choose($this->router->id, $plan, 3);
        $second = $probe->choose($this->router->id, $plan, 3);
        $this->assertCount(3, $first);
        $this->assertArrayHasKey('10.0.2.1', $first);  // el repetidor siempre se mide
        $this->assertArrayHasKey('10.0.2.1', $second);
        $this->assertNotEquals(array_keys($first), array_keys($second)); // los CPE rotan entre ciclos
    }

    public function test_simulated_router_produces_plausible_ping_and_usage(): void
    {
        $sim = NetworkDevice::create(['type' => 'mikrotik', 'name' => 'MK-Demo', 'ip' => '10.1.0.1', 'api_mode' => 'simulated', 'status' => 'online']);
        $this->router->update(['status' => 'retired']);
        $repeater = Repeater::create(['code' => 'R-20', 'name' => 'Demo', 'router_device_id' => $sim->id, 'management_ip' => '10.1.1.1']);
        $client = Client::create(['code' => '7001', 'first_name' => 'Demo', 'pppoe_user' => 'demo', 'ip_address' => '10.1.2.5', 'mac_address' => 'AA:AA:AA:00:00:05',
            'router_device_id' => $sim->id, 'repeater_id' => $repeater->id, 'service_status' => 'activo', 'online' => true]);
        $cpe = NetworkDevice::create(['type' => 'cpe', 'name' => 'CPE Demo', 'ip' => '10.1.2.6', 'client_id' => $client->id, 'repeater_id' => $repeater->id]);
        $client->update(['cpe_device_id' => $cpe->id]);

        $summary = $this->poll();

        $this->assertSame([], $summary['errors']);
        $repeater->refresh();
        $this->assertNotNull($repeater->last_ping_at);
        $this->assertGreaterThan(0, (float) $repeater->latency_ms);
        $this->assertLessThan(20, (float) $repeater->latency_ms);
        $this->assertNotNull($cpe->fresh()->latency_ms);
        $this->assertNotNull($client->fresh()->usage_counter); // cola simulada "wc-7001" con contadores
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ Señal v7

    public function test_routeros_v7_wifi_signal_is_normalized(): void
    {
        $this->assertSame(-71.0, MikroTikService::signalDbm(['signal-strength' => '-71dBm@HT20-7']));
        $this->assertSame(-63.0, MikroTikService::signalDbm(['signal' => '-63']));
        $this->assertNull(MikroTikService::signalDbm(['signal' => '']));

        $client = Client::create(['code' => '1001', 'first_name' => 'Rosa', 'pppoe_user' => 'rosa', 'mac_address' => 'AA:BB:CC:00:00:01',
            'router_device_id' => $this->router->id, 'service_status' => 'activo']);
        $this->sessions = [['name' => 'rosa', 'service' => 'pppoe', 'caller-id' => 'AA:BB:CC:00:00:01', 'address' => '10.10.0.2', 'uptime' => '1h']];

        $this->poll();

        $client->refresh();
        $this->assertTrue((bool) $client->online);
        $this->assertEquals(-67.0, (float) $client->signal_dbm);
        // El menú wireless no existe en este equipo (v7 con paquete wifi): no se vuelve a consultar en cada ciclo
        $this->assertTrue(Cache::has('mikrotik:nomenu:'.$this->router->id.':'.md5('/interface/wireless/registration-table')));
    }

    // ------------------------------------------------------------------ Repetidor sin MikroTik

    public function test_repeater_without_router_goes_down_when_its_management_ip_stops_answering(): void
    {
        $repeater = Repeater::create(['code' => 'R-09', 'name' => 'Loma', 'management_ip' => '10.0.9.1', 'status' => 'online']);
        $this->pingTable['10.0.9.1'] = [null, null, null];

        $this->poll();

        $repeater->refresh();
        $this->assertSame('offline', $repeater->status);
        $outage = Outage::query()->where('repeater_id', $repeater->id)->whereNull('ended_at')->first();
        $this->assertNotNull($outage);
        $this->assertStringContainsString('Sin respuesta a ping de 10.0.9.1', $outage->cause);
        $this->assertTrue(Alert::query()->open()->where('type', 'repeater_down')->where('subject_id', $repeater->id)->exists());

        // Vuelve a responder: se cierra la falla
        $this->pingTable['10.0.9.1'] = [5.0, 5.0, 5.0];
        $this->poll();

        $this->assertSame('online', $repeater->fresh()->status);
        $this->assertNull(Outage::query()->where('repeater_id', $repeater->id)->whereNull('ended_at')->first());
        $this->assertFalse(Alert::query()->open()->where('type', 'repeater_down')->where('subject_id', $repeater->id)->exists());
    }

    public function test_repeater_without_router_goes_down_when_all_its_clients_are_offline(): void
    {
        $repeater = Repeater::create(['code' => 'R-10', 'name' => 'Ejido', 'status' => 'online']);
        foreach (['2001', '2002', '2003'] as $code) {
            Client::create(['code' => $code, 'first_name' => 'Cliente '.$code, 'pppoe_user' => 'u'.$code, 'repeater_id' => $repeater->id,
                'router_device_id' => $this->router->id, 'service_status' => 'activo', 'online' => true]);
        }

        $this->poll();

        $this->assertSame('offline', $repeater->fresh()->status);
        $this->assertSame(3, (int) Outage::query()->where('repeater_id', $repeater->id)->value('affected_clients'));
    }

    // ------------------------------------------------------------------ Consumo y límite

    public function test_monthly_usage_accumulates_from_queue_counters_and_raises_data_cap_alert(): void
    {
        $plan = Plan::create(['name' => 'Básico 1 GB', 'download_mbps' => 10, 'upload_mbps' => 2, 'price' => 300, 'data_cap_gb' => 1]);
        $client = Client::create(['code' => '3001', 'first_name' => 'Marta', 'plan_id' => $plan->id, 'ip_address' => '10.10.0.5',
            'router_device_id' => $this->router->id, 'service_status' => 'activo']);
        $rule = AutomationRule::create(['name' => 'Aviso de límite', 'event' => 'data_cap_exceeded', 'active' => true, 'conditions' => [],
            'actions' => [['type' => 'create_alert', 'params' => ['alert_type' => 'automation', 'severity' => 'info', 'title' => 'Límite: {cliente}']]]]);
        $gb = MonitoringService::GB;
        $queue = fn (int $up, int $down) => [['.id' => '*1', 'name' => 'wc-3001', 'target' => '10.10.0.5/32', 'bytes' => "{$up}/{$down}", 'disabled' => 'false']];

        // 1) Primera lectura: solo línea base
        $this->queues = $queue(10_000_000, 90_000_000);
        $this->poll();
        $client->refresh();
        $this->assertEquals(0.0, (float) $client->month_usage_gb);
        $this->assertSame(100_000_000, (int) $client->usage_counter);
        $this->assertSame(now()->format('Y-m'), $client->usage_month);

        // 2) +1.5 GB: supera el límite de 1 GB → alerta + automatización
        $this->queues = $queue(10_000_000 + (int) (0.5 * $gb), 90_000_000 + $gb);
        $this->poll();
        $this->assertEquals(1.5, (float) $client->fresh()->month_usage_gb);
        $alert = Alert::query()->open()->where('type', 'data_cap_exceeded')->where('subject_id', $client->id)->first();
        $this->assertNotNull($alert);
        $this->assertEquals(1, $alert->data['data_cap_gb']);
        $this->assertTrue(AutomationRun::query()->where('automation_rule_id', $rule->id)->where('status', 'success')->exists());

        // 3) Contador reiniciado (reinicio del equipo): se suma lo leído
        $this->queues = $queue(0, (int) (0.25 * $gb));
        $this->poll();
        $this->assertEquals(1.75, (float) $client->fresh()->month_usage_gb);

        // 4) Cambio de mes: se reinicia el acumulado y se resuelve la alerta
        Client::query()->whereKey($client->id)->update(['usage_month' => '2000-01']);
        $this->poll();
        $client->refresh();
        $this->assertEquals(0.0, (float) $client->month_usage_gb);
        $this->assertSame(now()->format('Y-m'), $client->usage_month);
        $this->assertFalse(Alert::query()->open()->where('type', 'data_cap_exceeded')->exists());
    }

    // ------------------------------------------------------------------ Acciones de automatización

    public function test_change_plan_and_address_list_automation_actions(): void
    {
        $basic = Plan::create(['name' => 'Básico', 'download_mbps' => 10, 'upload_mbps' => 2, 'price' => 300]);
        $slow = Plan::create(['name' => 'Reducido', 'download_mbps' => 2, 'upload_mbps' => 1, 'price' => 0]);
        $client = Client::create(['code' => '4001', 'first_name' => 'Pablo', 'plan_id' => $basic->id, 'ip_address' => '10.10.0.9',
            'router_device_id' => $this->router->id, 'service_status' => 'activo']);
        $rule = AutomationRule::create(['name' => 'Reducir al superar límite', 'event' => 'data_cap_exceeded', 'active' => true, 'conditions' => [],
            'actions' => [
                ['type' => 'change_plan', 'params' => ['plan_id' => $slow->id, 'keep_billing' => true]],
                ['type' => 'address_list', 'params' => ['list' => 'LIMITE_CONSUMO', 'operation' => 'add']],
            ]]);

        $run = app(AutomationEngine::class)->runRule($rule, $client);

        $this->assertSame('success', $run->status, implode(' | ', $run->log));
        $this->assertSame($basic->id, (int) $client->fresh()->plan_id); // solo velocidad: el plan facturado no cambia
        $this->assertTrue(ServiceEvent::query()->where('client_id', $client->id)->where('type', 'speed_change')->where('network_applied', true)->exists());
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/rest/queue/simple') && $r['max-limit'] === '1000k/2000k');
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/rest/ip/firewall/address-list')
            && $r['list'] === 'LIMITE_CONSUMO' && $r['address'] === '10.10.0.9');

        // Cambio de plan completo y retiro de la address-list
        $rule->update(['actions' => [
            ['type' => 'change_plan', 'params' => ['plan_id' => $slow->id]],
            ['type' => 'address_list', 'params' => ['list' => 'LIMITE_CONSUMO', 'operation' => 'remove']],
        ]]);
        $run = app(AutomationEngine::class)->runRule($rule->fresh(), $client->fresh());
        $this->assertSame('success', $run->status, implode(' | ', $run->log));
        $this->assertSame($slow->id, (int) $client->fresh()->plan_id);
        $this->assertTrue(ServiceEvent::query()->where('client_id', $client->id)->where('type', 'plan_change')->exists());

        // Sin IP no se puede usar la address-list: la ejecución queda como fallida
        $client->update(['ip_address' => null]);
        $rule->update(['actions' => [['type' => 'address_list', 'params' => ['list' => 'LIMITE_CONSUMO', 'operation' => 'add']]]]);
        $this->assertSame('failed', app(AutomationEngine::class)->runRule($rule->fresh(), $client->fresh())->status);
    }

    public function test_mass_disconnect_and_anomaly_alerts_fire_automations(): void
    {
        $repeater = Repeater::create(['code' => 'R-05', 'name' => 'Centro']);
        $client = Client::create(['code' => '5001', 'first_name' => 'Iris']);
        $mass = AutomationRule::create(['name' => 'Masiva', 'event' => 'mass_disconnect', 'active' => true, 'conditions' => [['field' => 'offline', 'operator' => '>=', 'value' => '10']],
            'actions' => [['type' => 'create_alert', 'params' => ['alert_type' => 'automation', 'severity' => 'info', 'title' => 'Masiva {repetidor}']]]]);
        $anomaly = AutomationRule::create(['name' => 'Anomalía', 'event' => 'anomaly_detected', 'active' => true, 'conditions' => [['field' => 'alert_type', 'operator' => '=', 'value' => 'anomaly_flapping']],
            'actions' => [['type' => 'set_status', 'params' => ['status' => 'en_revision']]]]);

        $alerts = app(\App\Services\AlertService::class);
        $alerts->raise('mass_disconnect', 'critical', 'Muchos desconectados', '', $repeater, ['offline' => 12]);
        $alerts->raise('anomaly_flapping', 'warning', 'Desconexiones frecuentes', '', $client, ['disconnects_24h' => 5]);

        $this->assertSame('success', AutomationRun::query()->where('automation_rule_id', $mass->id)->value('status'));
        $this->assertSame('success', AutomationRun::query()->where('automation_rule_id', $anomaly->id)->value('status'));
        $this->assertSame('en_revision', $client->fresh()->service_status);
    }

    // ------------------------------------------------------------------ Instalación → CPE

    public function test_installation_activation_creates_and_links_the_client_cpe(): void
    {
        $tower = Tower::create(['code' => 'T-01', 'name' => 'Torre Norte']);
        $ap = NetworkDevice::create(['type' => 'ap', 'name' => 'AP Norte', 'ip' => '10.0.5.2', 'tower_id' => $tower->id]);
        $repeater = Repeater::create(['code' => 'R-03', 'name' => 'Norte', 'tower_id' => $tower->id, 'ap_device_id' => $ap->id]);
        $client = Client::create(['code' => '6001', 'first_name' => 'Hugo', 'mac_address' => 'aa-bb-cc-dd-ee-01', 'ip_address' => '10.20.0.7',
            'service_status' => 'instalacion_pendiente', 'repeater_id' => $repeater->id]);
        $order = InstallationOrder::create(['folio' => 'INS-001', 'client_id' => $client->id, 'repeater_id' => $repeater->id, 'status' => 'instalada', 'signal_dbm' => -61]);
        $item = InventoryItem::create(['sku' => 'CPE-LHG', 'name' => 'LHG 5', 'category' => 'cpe', 'brand' => 'MikroTik', 'model' => 'LHG 5 ac', 'stock' => 5, 'min_stock' => 0]);
        app(InventoryService::class)->move($item, 'instalacion', 1, ['installation_order_id' => $order->id, 'client_id' => $client->id, 'serial' => 'SN-778']);

        app(InstallationService::class)->activate($order, false);

        $client->refresh();
        $cpe = NetworkDevice::find($client->cpe_device_id);
        $this->assertNotNull($cpe);
        $this->assertSame('cpe', $cpe->type);
        $this->assertSame('AA:BB:CC:DD:EE:01', $cpe->mac);
        $this->assertSame('10.20.0.7', $cpe->ip);
        $this->assertSame('LHG 5 ac', $cpe->model);
        $this->assertSame('SN-778', $cpe->serial);
        $this->assertSame($repeater->id, (int) $cpe->repeater_id);
        $this->assertSame($tower->id, (int) $cpe->tower_id);
        $this->assertSame($ap->id, (int) $cpe->parent_id);
        $this->assertSame($client->id, (int) $cpe->client_id);

        // Ya vinculado: no se duplica
        app(InstallationService::class)->linkCpe($order, $client);
        $this->assertSame(1, NetworkDevice::query()->where('client_id', $client->id)->count());
    }

    // ------------------------------------------------------------------ Pantallas

    public function test_zone_polygon_is_saved_and_drawn_on_the_map_with_live_staff(): void
    {
        $admin = \App\Models\User::create(['tenant_id' => $this->tenant->id, 'name' => 'Admin', 'email' => 'admin@red.test', 'password' => bcrypt('x'), 'role' => 'admin', 'active' => true]);
        \App\Models\User::create(['tenant_id' => $this->tenant->id, 'name' => 'Téc Uno', 'email' => 'tec@red.test', 'password' => bcrypt('x'), 'role' => 'tecnico', 'active' => true,
            'last_lat' => 20.6, 'last_lng' => -100.4, 'last_location_at' => now()]);
        $this->actingAs($admin);
        $square = [[20.60, -100.40], [20.60, -100.38], [20.58, -100.38], [20.58, -100.40]];

        \Livewire\Livewire::test(\App\Livewire\Settings\Index::class, ['tab' => 'zonas'])
            ->call('newZone')
            ->set('zone.name', 'Centro')->set('zone.lat', '')->set('zone.lng', '')
            ->set('zone.polygon', [[20.6, -100.4], [20.6, -100.38]])
            ->call('saveZone')->assertHasErrors('zone.polygon')
            ->set('zone.polygon', $square)
            ->call('saveZone')->assertHasNoErrors();

        $zone = \App\Models\Zone::query()->where('name', 'Centro')->firstOrFail();
        $this->assertCount(4, $zone->polygon);
        $this->assertEquals(20.59, (float) $zone->lat); // centroide

        \Livewire\Livewire::test(\App\Livewire\Map\Index::class)
            ->assertOk()
            ->call('refreshStaff')
            ->assertDispatched('map-layer-main-map', fn ($name, $params) => $params['layers'] === ['technicians'] && count($params['markers']) === 1);

        $plan = Plan::create(['name' => 'Reducido', 'download_mbps' => 2, 'upload_mbps' => 1, 'price' => 0]);
        \Livewire\Livewire::test(\App\Livewire\Automations\Edit::class)
            ->set('form.name', 'Límite de consumo')->set('form.event', 'data_cap_exceeded')
            ->set('newAction', 'change_plan')->call('addAction')
            ->set('newAction', 'address_list')->call('addAction')
            ->assertSee('Plan destino')->assertSee('Address-list')
            ->set('form.actions.1.params.list', 'MAL NOMBRE')
            ->call('save')->assertHasErrors(['form.actions.0.params.plan_id', 'form.actions.1.params.list'])
            ->set('form.actions.0.params.plan_id', (string) $plan->id)->set('form.actions.1.params.list', 'LIMITE_CONSUMO')
            ->call('save')->assertHasNoErrors();
        $rule = AutomationRule::query()->where('event', 'data_cap_exceeded')->firstOrFail();
        $this->assertSame($plan->id, $rule->actions[0]['params']['plan_id']);
    }

    // ------------------------------------------------------------------ Inventario

    public function test_over_stock_raises_and_resolves_alert(): void
    {
        $item = InventoryItem::create(['sku' => 'CAB-UTP', 'name' => 'Cable UTP', 'category' => 'cable', 'unit' => 'm', 'stock' => 5, 'min_stock' => 1, 'max_stock' => 10]);
        $inventory = app(InventoryService::class);

        $inventory->move($item, 'entrada', 10);
        $alert = Alert::query()->open()->where('type', 'over_stock')->where('subject_id', $item->id)->first();
        $this->assertNotNull($alert);
        $this->assertEquals(10, $alert->data['max']);

        $inventory->move($item, 'salida', 8);
        $this->assertFalse(Alert::query()->open()->where('type', 'over_stock')->exists());
    }
}
