<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Plan;
use App\Models\Repeater;
use App\Models\SaasPlan;
use App\Models\Tenant;
use App\Models\Tower;
use App\Models\User;
use App\Models\Zone;
use App\Services\RiskService;
use App\Services\TenantProvisioner;
use App\Support\Audit;
use App\Support\TenantContext;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Datos de demostración realistas: 2 WISP (uno grande, uno pequeño) con red,
 * clientes, 12+ meses de cobranza, tickets, prospectos, inventario, etc.
 * Contraseña de todos los usuarios demo: "password".
 */
class DemoSeeder extends Seeder
{
    protected $faker;

    protected Carbon $now;

    protected int $chargeSeq = 0;

    protected int $paymentSeq = 0;

    public function run(): void
    {
        Audit::$enabled = false;
        mt_srand(2026);
        $this->faker = Faker::create('es_MX');
        $this->faker->seed(2026);
        $this->now = now();

        $this->call(ProductionSeeder::class);
        User::where('role', 'superadmin')->update(['email' => 'superadmin@wispcontrol.com', 'password' => bcrypt('password')]);

        $this->seedTenant([
            'name' => 'Comunidad Valle Verde', 'slug' => 'valle-verde', 'plan' => 'ENTERPRISE', 'email_domain' => 'wispcontrol.com',
            'center' => [20.7350, -100.4480], 'repeaters' => 18, 'target_clients' => 900, 'admin_email' => 'admin@wispcontrol.com',
            'legal_name' => 'Conectividad Valle Verde S.A. de C.V.', 'rfc' => 'CVV180512AB3', 'phone' => '4425986318',
        ]);
        $this->seedTenant([
            'name' => 'Red Sierra Gorda', 'slug' => 'sierra-gorda', 'plan' => 'PRO', 'email_domain' => 'sierragorda.mx',
            'center' => [21.2120, -99.4600], 'repeaters' => 3, 'target_clients' => 70, 'admin_email' => 'admin@sierragorda.mx',
            'legal_name' => 'Red Sierra Gorda S. de R.L.', 'rfc' => 'RSG200301KK9', 'phone' => '4411234567',
        ]);

        app(TenantContext::class)->set(null);
        Audit::$enabled = true;
    }

    protected function firstName(): string
    {
        static $names = ['José', 'Juan', 'Luis', 'Carlos', 'Miguel', 'Jorge', 'Alejandro', 'Fernando', 'Ricardo', 'Eduardo', 'Francisco', 'Roberto', 'Daniel',
            'Javier', 'Arturo', 'Sergio', 'Raúl', 'Héctor', 'Manuel', 'Pedro', 'Antonio', 'Óscar', 'Gerardo', 'Alberto', 'Rubén', 'Mario', 'Hugo', 'Iván',
            'María', 'Guadalupe', 'Juana', 'Margarita', 'Verónica', 'Leticia', 'Rosa', 'Patricia', 'Gabriela', 'Alejandra', 'Elizabeth', 'Adriana', 'Claudia',
            'Laura', 'Silvia', 'Mónica', 'Sandra', 'Araceli', 'Lucía', 'Fernanda', 'Daniela', 'Andrea', 'Karla', 'Diana', 'Sofía', 'Valeria', 'Ximena', 'Paola'];

        return $names[mt_rand(0, count($names) - 1)];
    }

    protected function lastName(): string
    {
        static $names = ['Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez', 'Sánchez', 'Ramírez', 'Cruz', 'Flores', 'Gómez',
            'Morales', 'Vázquez', 'Reyes', 'Jiménez', 'Torres', 'Díaz', 'Gutiérrez', 'Ruiz', 'Mendoza', 'Aguilar', 'Ortiz', 'Moreno', 'Castillo', 'Romero',
            'Álvarez', 'Méndez', 'Chávez', 'Rivera', 'Juárez', 'Ramos', 'Domínguez', 'Herrera', 'Medina', 'Castro', 'Vargas', 'Guzmán', 'Velázquez', 'Muñoz',
            'Rojas', 'Contreras', 'Salazar', 'Luna', 'Ortega', 'Guerrero', 'Estrada', 'Bautista', 'Cortés', 'Soto', 'Alvarado', 'Ríos', 'Nava', 'Olvera', 'Trejo'];

        return $names[mt_rand(0, count($names) - 1)];
    }

    protected function rand(float $min, float $max): float
    {
        return $min + mt_rand() / mt_getrandmax() * ($max - $min);
    }

    protected function pick(array $weighted): string
    {
        $r = mt_rand(1, array_sum($weighted));
        foreach ($weighted as $k => $w) {
            if (($r -= $w) <= 0) {
                return $k;
            }
        }

        return array_key_first($weighted);
    }

    protected function offset(array $center, float $km): array
    {
        $angle = $this->rand(0, 2 * M_PI);
        $d = sqrt($this->rand(0, 1)) * $km;

        return [round($center[0] + $d / 111 * cos($angle), 7), round($center[1] + $d / (111 * cos(deg2rad($center[0]))) * sin($angle), 7)];
    }

    protected function mac(): string
    {
        return strtoupper(implode(':', array_map(fn () => str_pad(dechex(mt_rand(0, 255)), 2, '0', STR_PAD_LEFT), range(1, 6))));
    }

    protected function seedTenant(array $cfg): void
    {
        $now = $this->now;
        $saasPlan = SaasPlan::where('code', $cfg['plan'])->first();
        $tenant = Tenant::create([
            'name' => $cfg['name'], 'slug' => $cfg['slug'], 'legal_name' => $cfg['legal_name'], 'rfc' => $cfg['rfc'],
            'tax_regime' => '601 - General de Ley Personas Morales', 'address' => 'Paseo Constitución 100, Villas del Parque, Querétaro',
            'phone' => $cfg['phone'], 'whatsapp' => $cfg['phone'], 'email' => 'contacto@'.$cfg['email_domain'],
            'subdomain' => $cfg['slug'], 'status' => 'active', 'saas_plan_id' => $saasPlan->id,
            'subscription_ends_at' => $now->copy()->addMonths(2)->startOfMonth(),
            'settings' => ['map' => ['center' => $cfg['center']], 'billing' => ['billing_day' => 1, 'due_days' => 5, 'grace_days' => 3]],
            'created_at' => $now->copy()->subMonths(30),
        ]);
        app(TenantProvisioner::class)->provision($tenant);
        app(TenantContext::class)->set($tenant);
        $this->chargeSeq = 0;
        $this->paymentSeq = 0;
        $tid = $tenant->id;
        $big = $cfg['repeaters'] > 5;

        // ---------------------------------------------------------------- Usuarios
        $mk = fn ($name, $email, $role, $extra = []) => User::create(array_merge([
            'tenant_id' => $tid, 'name' => $name, 'email' => $email, 'role' => $role, 'password' => 'password',
            'phone' => '442'.mt_rand(1000000, 9999999), 'active' => true,
        ], $extra));
        $d = $cfg['email_domain'];
        $mk('Administrador', $cfg['admin_email'], 'admin');
        $mk('Laura Méndez', "finanzas@$d", 'finanzas');
        $mk('Diego Ramírez', "soporte@$d", 'soporte');
        $supervisor = $mk('Patricia Gómez', "supervisor@$d", 'supervisor');
        $techNames = $big ? ['Carlos Hernández', 'Miguel Torres', 'Jorge Castillo', 'Ricardo Vega'] : ['Fernando Ruiz'];
        $technicians = collect($techNames)->map(fn ($n, $i) => $mk($n, ($i === 0 ? 'tecnico' : 'tecnico'.($i + 1))."@$d", 'tecnico', [
            'on_call' => $i === 0,
            'last_lat' => $cfg['center'][0] + $this->rand(-0.03, 0.03), 'last_lng' => $cfg['center'][1] + $this->rand(-0.03, 0.03), 'last_location_at' => $now->copy()->subMinutes(mt_rand(1, 40)),
        ]));
        $collectorNames = $big ? ['Juan Morales', 'Rosa Jiménez', 'Andrés Flores'] : ['Luis Ortega'];
        $collectors = collect($collectorNames)->map(fn ($n, $i) => $mk($n, ($i === 0 ? 'cobrador' : 'cobrador'.($i + 1))."@$d", 'cobrador', [
            'last_lat' => $cfg['center'][0] + $this->rand(-0.03, 0.03), 'last_lng' => $cfg['center'][1] + $this->rand(-0.03, 0.03), 'last_location_at' => $now->copy()->subMinutes(mt_rand(1, 90)),
        ]));

        // ---------------------------------------------------------------- Zonas y planes
        $zoneDefs = $big
            ? [['Zona Norte', 'San Isidro', '#1A7FF5', 0.030, 0.000], ['Zona Centro', 'Valle Verde', '#22A06B', 0.000, 0.000], ['Zona Sur', 'El Pozo', '#F7881F', -0.030, 0.005],
               ['Zona Oriente', 'La Estancia', '#6048D0', 0.004, 0.035], ['Zona Poniente', 'Buenavista', '#00A0A8', 0.000, -0.035]]
            : [['Cabecera', 'Jalpan', '#1A7FF5', 0, 0], ['Rancherías', 'Tancoyol', '#22A06B', 0.02, 0.02]];
        $zones = collect($zoneDefs)->map(fn ($z) => Zone::create([
            'name' => $z[0], 'community' => $z[1], 'color' => $z[2],
            'lat' => $cfg['center'][0] + $z[3], 'lng' => $cfg['center'][1] + $z[4], 'monthly_cost' => mt_rand(8, 25) * 1000,
        ]));

        $plans = collect([
            ['Básico', 10, 3, 250, '#94A3B8', 'basico-10M'], ['Hogar', 20, 5, 350, '#1A7FF5', 'hogar-20M'],
            ['Plus', 30, 8, 450, '#F7881F', 'plus-30M'], ['Premium', 50, 15, 600, '#6048D0', 'premium-50M'],
        ])->map(fn ($p) => Plan::create([
            'name' => $p[0], 'description' => "Internet {$p[1]} Mbps", 'download_mbps' => $p[1], 'upload_mbps' => $p[2], 'price' => $p[3],
            'tax_rate' => 16, 'price_includes_tax' => true, 'periodicity' => 'monthly', 'reconnection_fee' => 0, 'installation_fee' => 600,
            'mikrotik_profile' => $p[5], 'color' => $p[4], 'active' => true, 'public' => true,
        ]));
        $planWeights = [0 => 20, 1 => 34, 2 => 30, 3 => 16];

        // ---------------------------------------------------------------- Torres, MikroTik, repetidores, sectores
        $towerCount = max(1, (int) ceil($cfg['repeaters'] / 3));
        $towers = collect();
        $routers = collect();
        for ($i = 1; $i <= $towerCount; $i++) {
            $zone = $zones[($i - 1) % $zones->count()];
            [$lat, $lng] = $this->offset([$zone->lat, $zone->lng], 1.2);
            $tower = Tower::create([
                'zone_id' => $zone->id, 'code' => 'T-'.str_pad($i, 2, '0', STR_PAD_LEFT), 'name' => 'Torre '.$zone->community,
                'lat' => $lat, 'lng' => $lng, 'height_m' => [18, 24, 30, 36][mt_rand(0, 3)], 'owner' => 'Propia', 'monthly_cost' => mt_rand(15, 40) * 100,
                'status' => 'online', 'address' => 'Cerro '.$zone->community,
            ]);
            $towers->push($tower);
            $routers->push(NetworkDevice::create([
                'type' => 'mikrotik', 'name' => 'MK-'.Str::upper(Str::slug($zone->community, '')).'-'.$i, 'manufacturer' => 'MikroTik',
                'model' => ['CCR2004-1G-12S+2XS', 'RB5009UG+S+IN', 'CCR1036-8G-2S+'][mt_rand(0, 2)], 'mac' => $this->mac(),
                'ip' => "10.10.$i.1", 'firmware' => '7.15.3', 'location' => $tower->name, 'lat' => $lat, 'lng' => $lng,
                'tower_id' => $tower->id, 'status' => 'online', 'installed_at' => $now->copy()->subMonths(mt_rand(12, 30)),
                'last_seen_at' => $now, 'cpu' => mt_rand(8, 45), 'memory' => mt_rand(25, 60), 'latency_ms' => $this->rand(2, 9), 'packet_loss' => 0,
                'uptime' => mt_rand(3, 60).'d'.mt_rand(0, 23).'h', 'api_mode' => 'simulated', 'api_host' => "10.10.$i.1", 'api_port' => 8728,
                'api_user' => 'wispcontrol', 'api_password' => 'demo', 'service_method' => 'pppoe', 'suspended_list' => 'WISP_MOROSOS',
                'push_token' => Str::random(40), 'last_sync_at' => $now, 'responsible_id' => $technicians->random()->id,
            ]));
            foreach ([0, 120, 240] as $j => $az) {
                DB::table('sectors')->insert([
                    'tenant_id' => $tid, 'tower_id' => $tower->id, 'name' => "Sector {$tower->code}-".($j + 1), 'azimuth' => ($az + mt_rand(-15, 15) + 360) % 360,
                    'beamwidth' => [60, 90, 120][mt_rand(0, 2)], 'range_km' => $this->rand(2.5, 5), 'height_m' => $tower->height_m,
                    'frequency' => ['5180 MHz', '5500 MHz', '5745 MHz', '5805 MHz'][mt_rand(0, 3)], 'power_dbm' => mt_rand(22, 28),
                    'technology' => ['airMAX AC', 'LTU', 'Nv2 MikroTik'][mt_rand(0, 2)], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        $repeaters = collect();
        for ($i = 1; $i <= $cfg['repeaters']; $i++) {
            $tower = $towers[($i - 1) % $towers->count()];
            $router = $routers[($i - 1) % $routers->count()];
            [$lat, $lng] = $this->offset([$tower->lat, $tower->lng], 2.2);
            $code = 'R-'.str_pad($i, 2, '0', STR_PAD_LEFT);
            $ap = NetworkDevice::create([
                'type' => 'ap', 'name' => "AP {$code}", 'manufacturer' => 'Ubiquiti', 'model' => ['Rocket 5AC Prism', 'LiteAP AC', 'LTU Rocket'][mt_rand(0, 2)],
                'mac' => $this->mac(), 'ip' => '10.20.'.$i.'.2', 'firmware' => 'v8.7.11', 'lat' => $lat, 'lng' => $lng, 'tower_id' => $tower->id,
                'status' => 'online', 'installed_at' => $now->copy()->subMonths(mt_rand(6, 28)), 'last_seen_at' => $now, 'parent_id' => $router->id,
                'cpu' => mt_rand(5, 40), 'memory' => mt_rand(30, 70), 'latency_ms' => $this->rand(1, 6), 'packet_loss' => 0, 'signal_dbm' => mt_rand(-58, -48),
            ]);
            $repeater = Repeater::create([
                'tower_id' => $tower->id, 'zone_id' => $tower->zone_id, 'router_device_id' => $router->id, 'ap_device_id' => $ap->id,
                'technician_id' => $technicians->random()->id, 'code' => $code, 'name' => 'Repetidor '.$this->lastName(),
                'location' => 'Col. '.$this->lastName(), 'lat' => $lat, 'lng' => $lng,
                'capacity' => $big ? [50, 60, 70, 80, 100][mt_rand(0, 4)] : 40, 'alert_threshold' => 85, 'coverage_radius_km' => $this->rand(1.8, 3.2),
                'bandwidth_mbps' => [300, 500, 1000][mt_rand(0, 2)], 'monthly_cost' => mt_rand(4, 12) * 100, 'status' => 'online', 'last_seen_at' => $now,
            ]);
            $ap->update(['repeater_id' => $repeater->id]);
            $repeaters->push($repeater);
        }
        if ($big) {
            $repeaters[7]->update(['capacity' => 100]); // R-08 al 89%
            $repeaters[3]->update(['capacity' => 100]);
        }

        // ---------------------------------------------------------------- Clientes
        $utilization = $repeaters->map(fn ($r, $i) => $big ? match ($i) { 7 => 0.89, 3 => 0.74, 0 => 0.97, 5 => 0.91, default => $this->rand(0.45, 0.84) } : $this->rand(0.6, 0.85));
        $streets = ['Hidalgo', 'Morelos', 'Juárez', 'Zaragoza', 'Allende', 'Guerrero', 'Independencia', 'Reforma', '5 de Mayo', 'Las Flores', 'Del Sol', 'Los Pinos', 'Insurgentes', 'Corregidora'];
        $profiles = [];
        $clientsByRepeater = [];
        $code = 1000;
        foreach ($repeaters as $idx => $repeater) {
            $count = (int) round($repeater->capacity * $utilization[$idx]);
            // incluye cancelados (no cuentan en capacidad)
            $count += (int) round($count * 0.08);
            for ($k = 0; $k < $count; $k++) {
                [$lat, $lng] = $this->offset([$repeater->lat, $repeater->lng], (float) $repeater->coverage_radius_km * 0.9);
                // Crecimiento: más altas recientes
                $monthsAgo = (int) floor(30 * (1 - pow($this->rand(0, 1), 0.65)));
                $installed = $now->copy()->subMonths($monthsAgo)->subDays(mt_rand(0, 27))->startOfDay();
                if ($installed->gt($now)) {
                    $installed = $now->copy()->subDays(mt_rand(1, 5));
                }
                $plan = $plans[(int) $this->pick($planWeights)];
                $first = $this->firstName();
                $last = $this->lastName().' '.$this->lastName();
                $phone = '442'.mt_rand(1000000, 9999999);
                $code++;
                $client = Client::create([
                    'code' => (string) $code, 'first_name' => $first, 'last_name' => $last, 'phone' => $phone, 'whatsapp' => $phone,
                    'email' => mt_rand(1, 100) <= 55 ? Str::slug($first.'.'.explode(' ', $last)[0], '.').mt_rand(1, 99).'@gmail.com' : null,
                    'address' => 'Calle '.$streets[array_rand($streets)].' #'.mt_rand(1, 350), 'references' => ['Frente a la tienda', 'Casa azul', 'Junto a la iglesia', 'Portón negro', null][mt_rand(0, 4)],
                    'lat' => $lat, 'lng' => $lng, 'zone_id' => $repeater->zone_id, 'community' => $zones->firstWhere('id', $repeater->zone_id)?->community,
                    'colonia' => 'Col. '.$this->lastName(), 'plan_id' => $plan->id, 'installation_date' => $installed->toDateString(),
                    'technician_id' => $technicians->random()->id, 'collector_id' => $collectors->random()->id,
                    'repeater_id' => $repeater->id, 'router_device_id' => $repeater->router_device_id,
                    'ip_address' => '10.'.(100 + $idx).'.'.intdiv($k, 250).'.'.(($k % 250) + 2), 'mac_address' => $this->mac(),
                    'pppoe_user' => 'c'.$code, 'pppoe_password' => Str::lower(Str::random(8)), 'service_status' => 'activo', 'billing_day' => 1,
                    'online' => true, 'signal_dbm' => mt_rand(-74, -52), 'month_usage_gb' => $this->rand(40, 520),
                    'created_at' => $installed, 'updated_at' => $installed,
                ]);
                $profiles[$client->id] = $this->pick(['punctual' => 82, 'late' => 13, 'chronic' => 5]);
                $clientsByRepeater[$repeater->id][] = $client;
            }
        }
        $allClients = Client::query()->get();

        // Cancelaciones (churn ~1.5-3% mensual) — clientes con >3 meses
        $cancelCandidates = $allClients->filter(fn ($c) => $c->installation_date->lt($now->copy()->subMonths(3)))->shuffle()->take((int) round($allClients->count() * 0.075));
        foreach ($cancelCandidates as $c) {
            $cancelAt = Carbon::instance($this->faker->dateTimeBetween($c->installation_date->copy()->addMonths(2), $now->copy()->subDays(3)));
            $c->forceFill(['service_status' => 'cancelado', 'cancelled_at' => $cancelAt, 'online' => false,
                'cancel_reason' => ['Cambio de domicilio', 'Contrató otro proveedor', 'Problemas económicos', 'Mala señal', 'Ya no lo necesita'][mt_rand(0, 4)]])->saveQuietly();
        }

        // Clientes nuevos pendientes de instalación / en revisión
        $pending = $allClients->where('service_status', 'activo')->filter(fn ($c) => $c->installation_date->gt($now->copy()->subDays(8)))->take($big ? 12 : 2);
        foreach ($pending as $c) {
            $c->forceFill(['service_status' => 'instalacion_pendiente', 'online' => false, 'installation_date' => null])->saveQuietly();
        }
        $allClients->where('service_status', 'activo')->random($big ? 2 : 1)->each(fn ($c) => $c->forceFill(['service_status' => 'en_revision'])->saveQuietly());

        // CPEs de clientes
        $cpeRows = [];
        foreach ($allClients as $c) {
            $c->refresh();
            if ($c->service_status === 'instalacion_pendiente') {
                continue;
            }
            $cpeRows[] = [
                'tenant_id' => $tid, 'type' => 'cpe', 'name' => 'CPE '.$c->code, 'manufacturer' => 'Ubiquiti', 'model' => ['LiteBeam 5AC Gen2', 'NanoStation 5AC Loco', 'PowerBeam 5AC'][mt_rand(0, 2)],
                'mac' => $c->mac_address, 'ip' => $c->ip_address, 'firmware' => 'v8.7.11', 'lat' => $c->lat, 'lng' => $c->lng,
                'repeater_id' => $c->repeater_id, 'client_id' => $c->id, 'status' => $c->service_status === 'cancelado' ? 'retired' : 'online',
                'installed_at' => $c->installation_date, 'last_seen_at' => $now, 'signal_dbm' => $c->signal_dbm, 'latency_ms' => $this->rand(3, 25), 'packet_loss' => 0,
                'api_ssl' => 0, 'created_at' => $c->created_at, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($cpeRows, 300) as $chunk) {
            DB::table('network_devices')->insert($chunk);
        }
        DB::statement("UPDATE clients c JOIN network_devices d ON d.client_id = c.id AND d.type = 'cpe' SET c.cpe_device_id = d.id WHERE c.tenant_id = ?", [$tid]);

        // ---------------------------------------------------------------- Cobranza histórica
        $this->seedBilling($tenant, $allClients->fresh(), $profiles, $collectors, $saasPlan);

        // ---------------------------------------------------------------- Red: estado actual
        $this->seedNetworkState($tenant, $repeaters, $routers, $big);

        // ---------------------------------------------------------------- Operación
        $this->seedOperations($tenant, $technicians, $collectors, $supervisor, $repeaters, $zones, $plans, $big);

        // ---------------------------------------------------------------- Riesgo de morosidad
        $risk = app(RiskService::class);
        foreach (Client::query()->billable()->with('plan')->get() as $c) {
            $r = $risk->evaluate($c);
            $c->forceFill(['risk_score' => $r['score'], 'risk_level' => $r['level']])->saveQuietly();
        }

        $this->seedAlerts($tenant, $repeaters, $big);
        $this->seedGrowth($tenant, $plans, $zones, $big);
        $this->seedSaasInvoices($tenant, $saasPlan);
    }

    protected function seedBilling(Tenant $tenant, $clients, array $profiles, $collectors, SaasPlan $saasPlan): void
    {
        $now = $this->now;
        $tid = $tenant->id;
        $methodWeights = ['efectivo' => 45, 'transferencia' => 18, 'mercadopago' => 14, 'oxxo' => 8, 'stripe' => 6, 'deposito' => 6, 'paypal' => 3];
        $fees = ['mercadopago' => [3.49, 4], 'stripe' => [3.6, 3], 'paypal' => [3.95, 4], 'oxxo' => [3.6, 3]];
        $graceDays = 3;
        $currentMonth = $now->copy()->startOfMonth();

        foreach ($clients as $client) {
            if (! $client->installation_date || ! $client->plan) {
                continue;
            }
            $profile = $profiles[$client->id] ?? 'punctual';
            $price = (float) $client->plan->price;
            $start = $client->installation_date->copy()->startOfMonth()->addMonth();
            $end = $client->service_status === 'cancelado' ? $client->cancelled_at->copy()->startOfMonth() : $currentMonth;
            $charges = [];
            $payments = [];
            $events = [];
            $debtCarry = 0;

            // Cargo de instalación
            $instDate = $client->installation_date->copy();
            $charges[] = ['type' => 'instalacion', 'desc' => 'Instalación de servicio', 'start' => null, 'end' => null, 'total' => 600, 'due' => $instDate->copy(), 'paid_at' => $instDate->copy()->setTime(mt_rand(10, 18), mt_rand(0, 59))];

            for ($m = $start->copy(); $m->lte($end); $m->addMonth()) {
                $due = $m->copy()->addDays(5);
                $isCurrent = $m->equalTo($currentMonth);
                // ¿cuándo paga?
                $delay = match ($profile) {
                    'punctual' => mt_rand(1, 100) <= 95 ? -mt_rand(0, 5) : mt_rand(1, 5),
                    'late' => mt_rand(1, 100) <= 55 ? -mt_rand(0, 3) : mt_rand(2, 14),
                    default => mt_rand(1, 100) <= 25 ? -mt_rand(0, 2) : mt_rand(6, 34),
                };
                $paidAt = $due->copy()->addDays($delay)->setTime(mt_rand(8, 20), mt_rand(0, 59));
                if ($paidAt->lt($m)) {
                    $paidAt = $m->copy()->setTime(mt_rand(9, 19), mt_rand(0, 59));
                }
                if ($paidAt->gt($now)) {
                    $paidAt = null; // aún no paga
                }
                // Los morosos crónicos acumulan: algunos no pagan el mes anterior
                if (! $isCurrent && $profile === 'chronic' && $m->gte($currentMonth->copy()->subMonth()) && mt_rand(1, 100) <= 45) {
                    $paidAt = null;
                }
                $charges[] = ['type' => 'mensualidad', 'desc' => "Servicio {$client->plan->name} ".$m->translatedFormat('M Y'), 'start' => $m->copy(), 'end' => $m->copy()->endOfMonth(), 'total' => $price, 'due' => $due, 'paid_at' => $paidAt];

                // Suspensión y reconexión si pagó después de la tolerancia
                $cutDate = $due->copy()->addDays($graceDays + 1)->setTime(10, 0);
                if (($paidAt === null && $cutDate->lt($now)) || ($paidAt && $paidAt->gt($cutDate))) {
                    $events[] = ['suspension', $cutDate];
                    $charges[] = ['type' => 'recargo', 'desc' => 'Recargo por pago tardío', 'start' => null, 'end' => null, 'total' => 50, 'due' => $cutDate->copy()->startOfDay(), 'paid_at' => $paidAt];
                    if ($paidAt) {
                        $events[] = ['reconnection', $paidAt->copy()->addMinutes(mt_rand(1, 3))];
                    }
                }
            }

            // Insertar cargos
            $chargeRows = [];
            foreach ($charges as $ch) {
                $this->chargeSeq++;
                $subtotal = round($ch['total'] / 1.16, 2);
                $chargeRows[] = [
                    'tenant_id' => $tid, 'client_id' => $client->id, 'folio' => 'C-'.str_pad($this->chargeSeq, 7, '0', STR_PAD_LEFT), 'type' => $ch['type'],
                    'description' => $ch['desc'], 'period_start' => $ch['start']?->toDateString(), 'period_end' => $ch['end']?->toDateString(),
                    'subtotal' => $subtotal, 'tax' => round($ch['total'] - $subtotal, 2), 'total' => $ch['total'],
                    'paid_amount' => $ch['paid_at'] ? $ch['total'] : 0, 'due_date' => $ch['due']->toDateString(),
                    'status' => $ch['paid_at'] ? 'paid' : 'pending', 'paid_at' => $ch['paid_at'],
                    'created_at' => ($ch['start'] ?? $ch['due'])->copy()->setTime(0, 5), 'updated_at' => $ch['paid_at'] ?? $now,
                ];
            }
            DB::table('charges')->insert($chargeRows);
            $chargeIds = DB::table('charges')->where('client_id', $client->id)->orderBy('id')->pluck('id')->all();

            // Pagos agrupados por fecha de pago (un pago puede cubrir mensualidad + recargo)
            $byPaid = [];
            foreach ($chargeRows as $i => $row) {
                if ($row['paid_at']) {
                    $byPaid[$row['paid_at']->format('Y-m-d H:i')][] = [$chargeIds[$i], (float) $row['total'], $row['paid_at']];
                }
            }
            $paymentRows = [];
            $allocPlan = [];
            foreach ($byPaid as $group) {
                $amount = array_sum(array_column($group, 1));
                $paidAt = $group[0][2];
                $method = $this->pick($methodWeights);
                $gatewayFee = isset($fees[$method]) ? round($amount * $fees[$method][0] / 100 + $fees[$method][1], 2) : 0;
                $platformFee = isset($fees[$method]) ? round($amount * (float) $saasPlan->commission_percent / 100, 2) : 0;
                $collector = $method === 'efectivo' ? ($client->collector_id ?? $collectors->random()->id) : null;
                $this->paymentSeq++;
                $paymentRows[] = [
                    'tenant_id' => $tid, 'client_id' => $client->id, 'folio' => 'REC-'.str_pad($this->paymentSeq, 6, '0', STR_PAD_LEFT), 'kind' => 'payment',
                    'method' => $method, 'amount' => $amount, 'gateway_fee' => $gatewayFee, 'platform_fee' => $platformFee,
                    'net_amount' => round($amount - $gatewayFee - $platformFee, 2), 'gateway' => isset($fees[$method]) ? ($method === 'oxxo' ? 'stripe' : $method) : null,
                    'reference' => in_array($method, ['transferencia', 'deposito']) ? (string) mt_rand(10000000, 99999999) : (isset($fees[$method]) ? strtoupper(Str::random(12)) : null),
                    'status' => 'completed', 'paid_at' => $paidAt, 'collector_id' => $collector,
                    'lat' => $collector ? $client->lat : null, 'lng' => $collector ? $client->lng : null,
                    'created_at' => $paidAt, 'updated_at' => $paidAt,
                ];
                $allocPlan[] = $group;
            }
            if ($paymentRows) {
                DB::table('payments')->insert($paymentRows);
                $paymentIds = DB::table('payments')->where('client_id', $client->id)->orderBy('id')->pluck('id')->all();
                $allocRows = [];
                foreach ($allocPlan as $i => $group) {
                    foreach ($group as [$chargeId, $amt, $paidAt]) {
                        $allocRows[] = ['payment_id' => $paymentIds[$i], 'charge_id' => $chargeId, 'amount' => $amt, 'created_at' => $paidAt, 'updated_at' => $paidAt];
                    }
                }
                DB::table('payment_allocations')->insert($allocRows);
            }

            // Eventos de servicio
            $eventRows = [['tenant_id' => $tid, 'client_id' => $client->id, 'type' => 'activation', 'reason' => 'Instalación', 'source' => 'manual', 'network_applied' => 1, 'network_result' => 'Usuario PPPoE creado', 'created_at' => $client->installation_date, 'updated_at' => $client->installation_date]];
            foreach ($events as [$type, $at]) {
                $eventRows[] = ['tenant_id' => $tid, 'client_id' => $client->id, 'type' => $type, 'reason' => $type === 'suspension' ? 'Automatización: Corte automático por falta de pago' : 'Pago recibido',
                    'source' => 'automation', 'network_applied' => 1, 'network_result' => $type === 'suspension' ? "Usuario PPPoE c{$client->code} deshabilitado" : "PPPoE c{$client->code} habilitado", 'created_at' => $at, 'updated_at' => $at];
            }
            if ($client->service_status === 'cancelado') {
                $eventRows[] = ['tenant_id' => $tid, 'client_id' => $client->id, 'type' => 'cancellation', 'reason' => $client->cancel_reason, 'source' => 'manual', 'network_applied' => 1, 'network_result' => 'Usuario eliminado del equipo', 'created_at' => $client->cancelled_at, 'updated_at' => $client->cancelled_at];
            }
            DB::table('service_events')->insert($eventRows);

            // Estado final
            $open = DB::table('charges')->where('client_id', $client->id)->where('status', 'pending');
            $balance = (float) (clone $open)->sum('total');
            $oldestDue = (clone $open)->min('due_date');
            $status = $client->service_status;
            $suspendedAt = null;
            if ($status !== 'cancelado' && $status !== 'en_revision' && $oldestDue) {
                $cut = Carbon::parse($oldestDue)->addDays($graceDays + 1)->setTime(10, 0);
                if ($cut->lt($now)) {
                    $status = 'suspendido';
                    $suspendedAt = $cut;
                } elseif (Carbon::parse($oldestDue)->lt($now->copy()->startOfDay())) {
                    $status = 'moroso';
                }
            }
            if ($status === 'cancelado') {
                DB::table('charges')->where('client_id', $client->id)->where('status', 'pending')->update(['status' => 'cancelled']);
                $balance = 0;
            }
            DB::table('clients')->where('id', $client->id)->update([
                'service_status' => $status, 'balance' => $balance, 'suspended_at' => $suspendedAt,
                'next_due_date' => $oldestDue ?? $currentMonth->copy()->addMonth()->addDays(5)->toDateString(),
                'online' => in_array($status, ['activo', 'moroso', 'en_revision']) && mt_rand(1, 100) <= 97 ? 1 : 0,
            ]);
        }

        // Cortes de caja semanales por cobrador
        foreach ($collectors as $collector) {
            $weeks = DB::table('payments')->where('tenant_id', $tid)->where('collector_id', $collector->id)->where('method', 'efectivo')
                ->where('paid_at', '>=', $now->copy()->subWeeks(10)->startOfWeek())
                ->selectRaw('YEARWEEK(paid_at, 1) as yw, MIN(paid_at) as first_at, MAX(paid_at) as last_at, COUNT(*) as n, SUM(amount) as total')
                ->groupBy('yw')->orderBy('yw')->get();
            foreach ($weeks as $i => $w) {
                $isCurrent = Carbon::parse($w->last_at)->gte($now->copy()->startOfWeek());
                $diff = ! $isCurrent && mt_rand(1, 100) <= 12 ? -mt_rand(1, 6) * 50 : 0;
                $cutId = DB::table('cash_cuts')->insertGetId([
                    'tenant_id' => $tid, 'folio' => 'CC-'.$collector->id.'-'.$w->yw, 'collector_id' => $collector->id,
                    'opened_at' => Carbon::parse($w->first_at)->startOfWeek(), 'closed_at' => $isCurrent ? null : Carbon::parse($w->first_at)->endOfWeek()->setTime(18, 0),
                    'payments_count' => $w->n, 'expected_amount' => $w->total, 'delivered_amount' => $isCurrent ? null : $w->total + $diff,
                    'difference' => $isCurrent ? null : $diff, 'status' => $isCurrent ? 'open' : ($i >= $weeks->count() - 2 ? 'delivered' : 'reconciled'),
                    'reconciled_at' => $isCurrent || $i >= $weeks->count() - 2 ? null : Carbon::parse($w->first_at)->endOfWeek()->addDay(),
                    'notes' => $diff ? 'Faltante reportado por el cobrador' : null, 'created_at' => $w->first_at, 'updated_at' => $now,
                ]);
                DB::table('payments')->where('collector_id', $collector->id)->where('method', 'efectivo')
                    ->whereRaw('YEARWEEK(paid_at, 1) = ?', [$w->yw])->update(['cash_cut_id' => $cutId]);
            }
        }

        // Visitas de cobradores (últimos 45 días)
        $debtors = DB::table('clients')->where('tenant_id', $tid)->whereIn('service_status', ['moroso', 'suspendido'])->get();
        $rows = [];
        foreach ($debtors as $c) {
            foreach (range(1, mt_rand(1, 3)) as $n) {
                $result = $this->pick(['promesa' => 30, 'no_localizado' => 25, 'cerrado' => 20, 'rechazo' => 8, 'pago' => 17]);
                $rows[] = [
                    'tenant_id' => $tid, 'client_id' => $c->id, 'collector_id' => $c->collector_id ?? $collectors->random()->id, 'result' => $result,
                    'amount_due' => $c->balance, 'promise_date' => $result === 'promesa' ? $now->copy()->addDays(mt_rand(1, 7))->toDateString() : null,
                    'promise_amount' => $result === 'promesa' ? $c->balance : null,
                    'notes' => ['Dice que paga el viernes', 'No había nadie', 'Pidió prórroga', 'Se le dejó aviso', null][mt_rand(0, 4)],
                    'lat' => $c->lat, 'lng' => $c->lng, 'visited_at' => $now->copy()->subDays(mt_rand(0, 45))->setTime(mt_rand(9, 18), mt_rand(0, 59)),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('collection_visits')->insert($chunk);
        }
    }

    protected function seedNetworkState(Tenant $tenant, $repeaters, $routers, bool $big): void
    {
        $now = $this->now;
        $tid = $tenant->id;

        // Repetidor caído (R-04) con outage activo
        if ($big) {
            $down = $repeaters[3];
            $down->update(['status' => 'offline', 'down_since' => $now->copy()->subMinutes(42), 'last_seen_at' => $now->copy()->subMinutes(42)]);
            NetworkDevice::whereKey($down->ap_device_id)->update(['status' => 'offline', 'last_seen_at' => $now->copy()->subMinutes(42)]);
            DB::table('clients')->where('repeater_id', $down->id)->update(['online' => 0]);
            DB::table('network_devices')->where('repeater_id', $down->id)->where('type', 'cpe')->update(['status' => 'offline']);
            $warn = $repeaters[7];
            $warn->update(['status' => 'warning']);
        }

        // Clientes con señal anormal
        $weak = DB::table('clients')->where('tenant_id', $tid)->where('online', 1)->inRandomOrder()->limit($big ? 5 : 1)->pluck('id');
        DB::table('clients')->whereIn('id', $weak)->update(['signal_dbm' => DB::raw('-1 * (80 + FLOOR(RAND() * 8))')]);
        DB::table('network_devices')->whereIn('client_id', $weak)->update(['status' => 'warning', 'signal_dbm' => -82]);
        DB::table('network_devices')->where('tenant_id', $tid)->where('type', 'cpe')->whereIn('client_id',
            DB::table('clients')->where('tenant_id', $tid)->where('online', 0)->whereIn('service_status', ['activo', 'moroso'])->pluck('id'))->update(['status' => 'offline']);
        DB::table('network_devices')->where('tenant_id', $tid)->where('type', 'cpe')->whereIn('client_id',
            DB::table('clients')->where('tenant_id', $tid)->where('service_status', 'suspendido')->pluck('id'))->update(['status' => 'unknown']);

        // Muestras de monitoreo (24 h cada 30 min)
        $rows = [];
        foreach ($repeaters as $r) {
            $load = $r->clientLoad();
            for ($i = 48; $i >= 0; $i--) {
                $t = $now->copy()->subMinutes($i * 30);
                $hour = (int) $t->format('H');
                $peak = $hour >= 19 && $hour <= 23 ? 1.0 : ($hour >= 8 ? 0.6 : 0.25);
                $online = ! ($r->status === 'offline' && $i <= 1);
                $rows[] = [
                    'tenant_id' => $tid, 'subject_type' => 'repeater', 'subject_id' => $r->id, 'online' => $online,
                    'cpu' => $online ? mt_rand(5, 35) + $peak * 20 : null, 'memory' => $online ? mt_rand(30, 55) : null,
                    'latency_ms' => $online ? round($this->rand(2, 8) + $peak * $this->rand(2, 18), 2) : null,
                    'packet_loss' => $online ? round($peak > 0.9 ? $this->rand(0, 2.5) : $this->rand(0, 0.4), 2) : 100,
                    'signal_dbm' => null, 'rx_bps' => $online ? (int) ($load * 2.2e6 * $peak * $this->rand(0.7, 1.2)) : 0,
                    'tx_bps' => $online ? (int) ($load * 0.4e6 * $peak * $this->rand(0.7, 1.2)) : 0,
                    'connected_clients' => $online ? (int) round($load * ($peak > 0.5 ? 0.97 : 0.9)) : 0, 'sampled_at' => $t,
                ];
            }
        }
        foreach ($routers as $r) {
            for ($i = 48; $i >= 0; $i--) {
                $t = $now->copy()->subMinutes($i * 30);
                $peak = ((int) $t->format('H') >= 19) ? 1 : 0.5;
                $rows[] = [
                    'tenant_id' => $tid, 'subject_type' => 'device', 'subject_id' => $r->id, 'online' => true, 'cpu' => round(mt_rand(6, 25) + $peak * 25, 2),
                    'memory' => mt_rand(30, 55), 'latency_ms' => round($this->rand(1, 5), 2), 'packet_loss' => 0, 'signal_dbm' => null,
                    'rx_bps' => (int) (mt_rand(80, 400) * 1e6 * $peak), 'tx_bps' => (int) (mt_rand(10, 60) * 1e6 * $peak), 'connected_clients' => null, 'sampled_at' => $t,
                ];
            }
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('monitoring_samples')->insert($chunk);
        }
    }

    protected function seedOperations(Tenant $tenant, $technicians, $collectors, User $supervisor, $repeaters, $zones, $plans, bool $big): void
    {
        $now = $this->now;
        $tid = $tenant->id;
        $clients = DB::table('clients')->where('tenant_id', $tid)->whereNotIn('service_status', ['cancelado', 'instalacion_pendiente'])->get();
        $categories = ['sin_internet' => 30, 'lento' => 25, 'antena' => 12, 'router' => 12, 'pago' => 8, 'domicilio' => 4, 'plan' => 6, 'otro' => 3];
        $subjects = [
            'sin_internet' => ['No tengo Internet desde la mañana', 'Sin servicio', 'Se cayó el Internet'],
            'lento' => ['Internet muy lento en la noche', 'Velocidad baja', 'Se traba mucho'],
            'antena' => ['La antena se movió con el viento', 'Antena con luz roja', 'Revisar alineación de antena'],
            'router' => ['El router se reinicia solo', 'Cambiar contraseña del WiFi', 'Router no enciende'],
            'pago' => ['Pagué y no se refleja', 'Pago por transferencia no aplicado'],
            'domicilio' => ['Me cambio de casa'], 'plan' => ['Quiero subir a Premium', 'Cambio de plan a 30 Mbps'], 'otro' => ['Solicitud de factura'],
        ];
        $priorityFor = ['sin_internet' => 'alta', 'lento' => 'media', 'antena' => 'alta', 'router' => 'media', 'pago' => 'media', 'domicilio' => 'baja', 'plan' => 'baja', 'otro' => 'baja'];
        $slaMin = ['critica' => 120, 'alta' => 240, 'media' => 480, 'baja' => 1440];
        $n = $big ? 260 : 25;
        $seq = 1257 - $n;
        for ($i = 0; $i < $n; $i++) {
            $c = $clients->random();
            $cat = $this->pick($categories);
            $prio = $priorityFor[$cat];
            $created = $now->copy()->subDays(mt_rand(2, 180))->setTime(mt_rand(8, 20), mt_rand(0, 59));
            $resolveMin = (int) ($slaMin[$prio] * $this->rand(0.3, 1.3));
            $tech = $technicians->random();
            $seq++;
            $id = DB::table('tickets')->insertGetId([
                'tenant_id' => $tid, 'folio' => 'T-'.str_pad($seq, 6, '0', STR_PAD_LEFT), 'client_id' => $c->id, 'category' => $cat, 'priority' => $prio,
                'status' => 'cerrado', 'subject' => $subjects[$cat][array_rand($subjects[$cat])], 'description' => 'Reporte del cliente.',
                'source' => ['cliente', 'whatsapp', 'telefono', 'admin'][mt_rand(0, 3)], 'technician_id' => $tech->id, 'repeater_id' => $c->repeater_id,
                'sla_due_at' => $created->copy()->addMinutes($slaMin[$prio]), 'sla_breached' => $resolveMin > $slaMin[$prio],
                'arrived_at' => $created->copy()->addMinutes((int) ($resolveMin * 0.6)), 'departed_at' => $created->copy()->addMinutes($resolveMin),
                'resolved_at' => $created->copy()->addMinutes($resolveMin), 'closed_at' => $created->copy()->addMinutes($resolveMin + 60),
                'resolution' => ['Se realineó la antena', 'Se cambió conector RJ45', 'Se reinició CPE', 'Se reemplazó router', 'Pago aplicado manualmente', 'Se ajustó canal del AP'][mt_rand(0, 5)],
                'created_at' => $created, 'updated_at' => $created->copy()->addMinutes($resolveMin + 60),
            ]);
            DB::table('ticket_comments')->insert([
                ['ticket_id' => $id, 'user_id' => null, 'type' => 'system', 'body' => 'Ticket creado', 'internal' => 0, 'created_at' => $created, 'updated_at' => $created],
                ['ticket_id' => $id, 'user_id' => $supervisor->id, 'type' => 'assignment', 'body' => "Asignado a {$tech->name}", 'internal' => 0, 'created_at' => $created->copy()->addMinutes(10), 'updated_at' => $created],
                ['ticket_id' => $id, 'user_id' => $tech->id, 'type' => 'status', 'body' => 'Estado: En proceso → Resuelto', 'internal' => 0, 'created_at' => $created->copy()->addMinutes($resolveMin), 'updated_at' => $created],
            ]);
        }

        // Tickets abiertos actuales
        $open = $big ? [
            ['critica', 'infraestructura', 'abierto', 40, 'Repetidor R-04 fuera de servicio', null],
            ['critica', 'sin_internet', 'en_proceso', 75, 'Sin Internet en toda la calle', 'tecnico'],
            ['alta', 'sin_internet', 'asignado', 210, 'No tengo Internet desde la mañana', 'tecnico'],
            ['alta', 'antena', 'asignado', 190, 'La antena se movió con el viento', 'tecnico'],
            ['alta', 'lento', 'en_proceso', 120, 'Internet muy lento en la noche', 'tecnico'],
            ['media', 'router', 'abierto', 60, 'El router se reinicia solo', null],
            ['media', 'pago', 'abierto', 30, 'Pagué y no se refleja', null],
            ['media', 'lento', 'asignado', 380, 'Velocidad baja en videollamadas', 'tecnico'],
            ['media', 'router', 'en_espera', 600, 'Cambio de router por garantía', 'tecnico'],
            ['baja', 'plan', 'abierto', 300, 'Quiero subir a Premium', null],
            ['baja', 'domicilio', 'asignado', 900, 'Me cambio de casa', 'tecnico'],
            ['baja', 'otro', 'abierto', 1300, 'Solicitud de factura', null],
            ['alta', 'antena', 'abierto', 15, 'Antena con luz roja', null],
            ['media', 'sin_internet', 'asignado', 450, 'Sin servicio después de la lluvia', 'tecnico'],
        ] : [
            ['alta', 'sin_internet', 'asignado', 100, 'Sin Internet', 'tecnico'],
            ['media', 'lento', 'abierto', 60, 'Velocidad baja', null],
        ];
        foreach ($open as [$prio, $cat, $status, $agoMin, $subject, $hasTech]) {
            $seq++;
            $c = $cat === 'infraestructura' ? null : $clients->random();
            $tech = $hasTech ? $technicians->random() : null;
            $created = $now->copy()->subMinutes($agoMin);
            $id = DB::table('tickets')->insertGetId([
                'tenant_id' => $tid, 'folio' => 'T-'.str_pad($seq, 6, '0', STR_PAD_LEFT), 'client_id' => $c?->id, 'category' => $cat, 'priority' => $prio,
                'status' => $status, 'subject' => $subject, 'description' => $cat === 'infraestructura' ? 'Generado automáticamente por la regla "Repetidor caído".' : 'Reporte del cliente vía WhatsApp.',
                'source' => $cat === 'infraestructura' ? 'automatico' : 'whatsapp', 'technician_id' => $tech?->id ?? ($cat === 'infraestructura' ? $technicians->first()->id : null),
                'repeater_id' => $cat === 'infraestructura' ? $repeaters[3]->id : $c?->repeater_id,
                'sla_due_at' => $created->copy()->addMinutes($slaMin[$prio]), 'sla_breached' => $agoMin > $slaMin[$prio], 'sla_warned' => false,
                'arrived_at' => $status === 'en_proceso' ? $now->copy()->subMinutes(20) : null,
                'created_at' => $created, 'updated_at' => $created,
            ]);
            DB::table('ticket_comments')->insert(['ticket_id' => $id, 'user_id' => null, 'type' => 'system', 'body' => 'Ticket creado', 'internal' => 0, 'created_at' => $created, 'updated_at' => $created]);
            if ($cat === 'infraestructura') {
                DB::table('tickets')->where('id', $id)->update(['status' => 'asignado']);
                $affected = DB::table('clients')->where('repeater_id', $repeaters[3]->id)->whereNotIn('service_status', ['cancelado'])->count();
                DB::table('outages')->insert([
                    'tenant_id' => $tid, 'repeater_id' => $repeaters[3]->id, 'device_id' => $repeaters[3]->ap_device_id, 'zone_id' => $repeaters[3]->zone_id,
                    'technician_id' => $technicians->first()->id, 'affected_clients' => $affected, 'started_at' => $created->copy()->subMinutes(2),
                    'last_communication_at' => $created->copy()->subMinutes(2), 'ticket_id' => $id, 'created_at' => $created, 'updated_at' => $created,
                ]);
            }
        }

        // Prospectos con concentración de demanda (Zona Norte fuera de cobertura)
        $north = $zones->first();
        $prospectRows = [];
        $n = $big ? 126 : 12;
        for ($i = 0; $i < $n; $i++) {
            $inNorthCluster = $big && $i < 47;
            [$lat, $lng] = $inNorthCluster ? $this->offset([$north->lat + 0.028, $north->lng + 0.006], 1.6) : $this->offset([$north->lat - 0.02, $north->lng], 7);
            $created = $i < 18 ? $now->copy()->subDays(mt_rand(0, 29)) : $now->copy()->subDays(mt_rand(30, 300));
            $status = $this->pick(['nuevo' => 30, 'contactado' => 25, 'viable' => 18, 'sin_cobertura' => 14, 'instalacion_pendiente' => 5, 'convertido' => 6, 'descartado' => 2]);
            $nearest = $repeaters->sortBy(fn ($r) => haversine_km($lat, $lng, (float) $r->lat, (float) $r->lng))->first();
            $dist = haversine_km($lat, $lng, (float) $nearest->lat, (float) $nearest->lng);
            $name = $this->firstName().' '.$this->lastName();
            $phone = '442'.mt_rand(1000000, 9999999);
            $prospectRows[] = [
                'tenant_id' => $tid, 'name' => $name, 'phone' => $phone, 'whatsapp' => $phone, 'address' => 'Calle '.$this->lastName().' #'.mt_rand(1, 200),
                'lat' => $lat, 'lng' => $lng, 'zone_id' => $north->id, 'desired_plan_id' => $plans->random()->id,
                'source' => $this->pick(['facebook' => 35, 'whatsapp' => 20, 'referido' => 18, 'volante' => 10, 'portal' => 8, 'web' => 6, 'llamada' => 3]),
                'status' => $status, 'nearest_repeater_id' => $nearest->id, 'distance_km' => round($dist, 2), 'has_coverage' => $dist <= (float) $nearest->coverage_radius_km,
                'assigned_to' => mt_rand(0, 1) ? $supervisor->id : null, 'contacted_at' => $status !== 'nuevo' ? $created->copy()->addDays(1) : null,
                'notes' => null, 'created_at' => $created, 'updated_at' => $created,
            ];
        }
        DB::table('prospects')->insert($prospectRows);

        // Órdenes de instalación
        $pendingClients = DB::table('clients')->where('tenant_id', $tid)->where('service_status', 'instalacion_pendiente')->get();
        $seqIo = 0;
        foreach ($pendingClients as $i => $c) {
            $seqIo++;
            $status = ['programada', 'programada', 'aceptada', 'en_proceso', 'cotizacion', 'programada'][$i % 6];
            DB::table('installation_orders')->insert([
                'tenant_id' => $tid, 'folio' => 'OI-'.str_pad($seqIo, 5, '0', STR_PAD_LEFT), 'client_id' => $c->id, 'plan_id' => $c->plan_id,
                'repeater_id' => $c->repeater_id, 'technician_id' => $status === 'cotizacion' ? null : $technicians->random()->id, 'status' => $status,
                'quote_amount' => 600, 'quote_items' => json_encode([['concepto' => 'Instalación', 'importe' => 600]]),
                'quote_accepted_at' => $status !== 'cotizacion' ? $now->copy()->subDays(2) : null,
                'scheduled_at' => in_array($status, ['programada', 'en_proceso']) ? $now->copy()->addDays(mt_rand(0, 3))->setTime(mt_rand(9, 16), 0) : null,
                'started_at' => $status === 'en_proceso' ? $now->copy()->subHour() : null,
                'checklist' => json_encode($status === 'en_proceso' ? ['antena' => true, 'cableado' => true, 'poe' => true] : []),
                'created_at' => $now->copy()->subDays(mt_rand(1, 6)), 'updated_at' => $now,
            ]);
        }
        $recent = DB::table('clients')->where('tenant_id', $tid)->where('service_status', 'activo')->where('installation_date', '>=', $now->copy()->subDays(90))->limit($big ? 40 : 4)->get();
        foreach ($recent as $c) {
            $seqIo++;
            $done = Carbon::parse($c->installation_date)->setTime(mt_rand(9, 17), 0);
            DB::table('installation_orders')->insert([
                'tenant_id' => $tid, 'folio' => 'OI-'.str_pad($seqIo, 5, '0', STR_PAD_LEFT), 'client_id' => $c->id, 'plan_id' => $c->plan_id,
                'repeater_id' => $c->repeater_id, 'technician_id' => $c->technician_id, 'status' => 'activada', 'quote_amount' => 600,
                'quote_accepted_at' => $done->copy()->subDays(3), 'scheduled_at' => $done, 'started_at' => $done, 'completed_at' => $done->copy()->addHours(2),
                'activated_at' => $done->copy()->addHours(2), 'checklist' => json_encode(array_fill_keys(array_keys(config('wisp.installation_checklist')), true)),
                'signal_dbm' => $c->signal_dbm, 'speed_down' => mt_rand(18, 52), 'speed_up' => mt_rand(4, 14), 'signed_by_name' => $c->first_name.' '.$c->last_name,
                'created_at' => $done->copy()->subDays(4), 'updated_at' => $done,
            ]);
        }

        // Inventario
        $items = [
            ['ANT-LB5AC', 'LiteBeam 5AC Gen2', 'antena', 'Ubiquiti', 3, 8, 40, 1650, true],
            ['ANT-NS5AC', 'NanoStation 5AC Loco', 'antena', 'Ubiquiti', 22, 10, 50, 1450, true],
            ['CPE-PB5AC', 'PowerBeam 5AC 400', 'cpe', 'Ubiquiti', 9, 5, 25, 2400, true],
            ['RTR-ARCHER', 'Router TP-Link Archer C6', 'router', 'TP-Link', 31, 10, 60, 650, true],
            ['RTR-HAPAC', 'MikroTik hAP ac²', 'router', 'MikroTik', 12, 5, 30, 1300, true],
            ['CBL-UTP5E', 'Cable UTP Cat5e exterior', 'cable', 'Condumex', 1840, 500, 3000, 6.5, false],
            ['CON-RJ45', 'Conector RJ45 blindado', 'conector', 'Genérico', 640, 200, 2000, 4, false],
            ['FTE-24V', 'Fuente PoE 24V 0.5A', 'fuente', 'Ubiquiti', 27, 15, 80, 180, false],
            ['POE-GIGA', 'Inyector PoE Gigabit', 'poe', 'Ubiquiti', 14, 10, 50, 320, false],
            ['TOR-TRAMO', 'Tramo de torre arriostrada 3m', 'torre', 'Genérico', 6, 2, 12, 2800, false],
            ['HER-MASTIL', 'Mástil 1.5m galvanizado', 'herraje', 'Genérico', 38, 15, 80, 240, false],
            ['HER-ABRAZ', 'Abrazadera universal', 'herraje', 'Genérico', 120, 40, 300, 35, false],
            ['REF-SFP', 'Módulo SFP 1.25G', 'refaccion', 'MikroTik', 7, 4, 20, 450, false],
            ['REF-SURGE', 'Protector de descargas Ethernet', 'refaccion', 'Ubiquiti', 18, 10, 60, 420, false],
        ];
        foreach ($big ? $items : array_slice($items, 0, 8) as [$sku, $name, $cat, $brand, $stock, $min, $max, $cost, $serial]) {
            $itemId = DB::table('inventory_items')->insertGetId([
                'tenant_id' => $tid, 'sku' => $sku, 'name' => $name, 'category' => $cat, 'brand' => $brand, 'unit' => in_array($cat, ['cable']) ? 'm' : 'pza',
                'stock' => $stock, 'min_stock' => $min, 'max_stock' => $max, 'unit_cost' => $cost, 'serialized' => $serial, 'location' => 'Almacén central',
                'active' => true, 'created_at' => $now->copy()->subMonths(12), 'updated_at' => $now,
            ]);
            $running = $stock;
            $movs = [];
            foreach (range(1, mt_rand(4, 9)) as $k) {
                $type = $this->pick(['instalacion' => 50, 'entrada' => 25, 'salida' => 15, 'devolucion' => 10]);
                $qty = $type === 'entrada' ? mt_rand(10, 40) * ($cat === 'cable' ? 10 : 1) : ($cat === 'cable' ? mt_rand(15, 40) : mt_rand(1, 3));
                $signed = in_array($type, ['entrada', 'devolucion']) ? $qty : -$qty;
                $movs[] = [
                    'tenant_id' => $tid, 'inventory_item_id' => $itemId, 'type' => $type, 'quantity' => $signed, 'unit_cost' => $cost, 'stock_after' => $running,
                    'reference' => $type === 'entrada' ? 'Factura '.mt_rand(1000, 9999) : null, 'user_id' => $technicians->random()->id,
                    'notes' => null, 'created_at' => $now->copy()->subDays(mt_rand(1, 120)), 'updated_at' => $now,
                ];
                $running -= $signed;
            }
            DB::table('inventory_movements')->insert($movs);
        }

        // Rutas de hoy
        foreach ($technicians as $t) {
            $routeId = DB::table('field_routes')->insertGetId(['tenant_id' => $tid, 'user_id' => $t->id, 'type' => 'tecnico', 'date' => $now->toDateString(), 'status' => 'in_progress', 'created_at' => $now, 'updated_at' => $now]);
            $tickets = DB::table('tickets')->where('tenant_id', $tid)->where('technician_id', $t->id)->whereIn('status', ['asignado', 'en_proceso', 'en_espera'])->get();
            $seqStop = 0;
            foreach ($tickets as $tk) {
                $cl = $tk->client_id ? DB::table('clients')->find($tk->client_id) : null;
                $rp = $tk->repeater_id ? DB::table('repeaters')->find($tk->repeater_id) : null;
                $seqStop++;
                DB::table('route_stops')->insert(['field_route_id' => $routeId, 'stoppable_type' => 'ticket', 'stoppable_id' => $tk->id, 'sequence' => $seqStop,
                    'lat' => $cl->lat ?? $rp->lat ?? null, 'lng' => $cl->lng ?? $rp->lng ?? null, 'status' => $tk->status === 'en_proceso' ? 'arrived' : 'pending', 'created_at' => $now, 'updated_at' => $now]);
            }
            foreach (DB::table('installation_orders')->where('tenant_id', $tid)->where('technician_id', $t->id)->whereIn('status', ['programada', 'en_proceso'])->get() as $io) {
                $cl = DB::table('clients')->find($io->client_id);
                $seqStop++;
                DB::table('route_stops')->insert(['field_route_id' => $routeId, 'stoppable_type' => 'installation_order', 'stoppable_id' => $io->id, 'sequence' => $seqStop,
                    'lat' => $cl->lat, 'lng' => $cl->lng, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        foreach ($collectors as $col) {
            $routeId = DB::table('field_routes')->insertGetId(['tenant_id' => $tid, 'user_id' => $col->id, 'type' => 'cobranza', 'date' => $now->toDateString(), 'status' => 'planned', 'created_at' => $now, 'updated_at' => $now]);
            $debtors = DB::table('clients')->where('tenant_id', $tid)->where('collector_id', $col->id)->whereIn('service_status', ['moroso', 'suspendido'])->limit(10)->get();
            foreach ($debtors as $k => $cl) {
                DB::table('route_stops')->insert(['field_route_id' => $routeId, 'stoppable_type' => 'client', 'stoppable_id' => $cl->id, 'sequence' => $k + 1, 'lat' => $cl->lat, 'lng' => $cl->lng, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        // Mantenimiento programado
        if ($big) {
            $r = $repeaters[10];
            DB::table('maintenances')->insert([
                'tenant_id' => $tid, 'title' => "Actualización de firmware {$r->code}", 'description' => 'Actualización de firmware y reemplazo de conectores. Corte estimado de 30 minutos.',
                'starts_at' => $now->copy()->addDays(2)->setTime(2, 0), 'ends_at' => $now->copy()->addDays(2)->setTime(3, 0), 'zone_id' => $r->zone_id, 'repeater_id' => $r->id,
                'affected_clients' => $r->clientLoad(), 'notify_hours_before' => 24, 'status' => 'scheduled', 'created_by' => $supervisor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Historial de mensajes
        $rows = [];
        $sample = DB::table('clients')->where('tenant_id', $tid)->inRandomOrder()->limit($big ? 220 : 20)->get();
        foreach ($sample as $c) {
            $event = ['reminder', 'payment_received', 'suspended', 'reconnected', 'ticket_assigned', 'pending'][mt_rand(0, 5)];
            $at = $now->copy()->subDays(mt_rand(0, 30))->setTime(mt_rand(8, 20), mt_rand(0, 59));
            $rows[] = ['tenant_id' => $tid, 'client_id' => $c->id, 'channel' => mt_rand(1, 10) <= 8 ? 'whatsapp' : 'sms', 'event' => $event, 'to' => '+52'.$c->whatsapp,
                'body' => str_replace('{nombre}', $c->first_name, \App\Services\Messaging\DefaultTemplates::body($event)), 'status' => 'simulated', 'sent_at' => $at, 'created_at' => $at, 'updated_at' => $at];
        }
        DB::table('messages')->insert($rows);
    }

    protected function seedAlerts(Tenant $tenant, $repeaters, bool $big): void
    {
        $now = $this->now;
        $alerts = app(\App\Services\AlertService::class);
        if ($big) {
            $r = $repeaters[3];
            $alerts->raise('repeater_down', 'critical', "Repetidor {$r->code} fuera de servicio", $r->clientLoad().' clientes afectados · sin comunicación desde hace 42 min', $r);
            $sat = $repeaters[0];
            $alerts->raise('repeater_saturated', 'warning', "Repetidor {$sat->code} saturado", "Utilización {$sat->utilization()}% de su capacidad", $sat);
            $alerts->raise('zone_growth', 'info', 'Zona Norte con crecimiento acelerado', '47 interesados · 18 solicitudes en los últimos 30 días', \App\Models\Zone::query()->first());
        }
        foreach (Client::query()->where('signal_dbm', '<', -79)->where('online', true)->get() as $c) {
            $alerts->raise('abnormal_signal', 'warning', 'Cliente con señal anormal', "{$c->full_name} ({$c->code}) · {$c->signal_dbm} dBm", $c);
        }
        foreach (Client::query()->where('risk_level', 'alto')->billable()->orderByDesc('risk_score')->limit($big ? 12 : 2)->get() as $c) {
            $alerts->raise('recurrent_delay', 'warning', 'Cliente con retrasos recurrentes', "{$c->full_name} ({$c->code}) · riesgo {$c->risk_score}/100", $c);
        }
        foreach (\App\Models\Ticket::query()->open()->whereNotNull('sla_due_at')->get() as $t) {
            if ($t->slaState() === 'risk') {
                $alerts->raise('sla_risk', 'warning', "Ticket {$t->folio} próximo a vencer", 'Vence '.$t->sla_due_at->diffForHumans(), $t);
            } elseif ($t->slaState() === 'breached') {
                $alerts->raise('sla_breached', 'critical', "Ticket {$t->folio} con SLA vencido", 'Venció '.$t->sla_due_at->diffForHumans(), $t);
            }
        }
        foreach (\App\Models\InventoryItem::query()->lowStock()->get() as $item) {
            $alerts->raise('low_stock', 'warning', "Inventario bajo: {$item->name}", "Existencia {$item->stock} (mínimo {$item->min_stock})", $item);
        }
        \App\Models\Alert::query()->update(['created_at' => DB::raw("DATE_SUB(NOW(), INTERVAL FLOOR(RAND()*180) MINUTE)")]);
    }

    protected function seedGrowth(Tenant $tenant, $plans, $zones, bool $big): void
    {
        $now = $this->now;
        $tid = $tenant->id;

        DB::table('promotions')->insert([
            ['tenant_id' => $tid, 'name' => 'Buen Fin: instalación gratis', 'description' => 'Contrata en noviembre y no pagas instalación.', 'type' => 'instalacion_gratis', 'value' => 600, 'months' => 1, 'plan_ids' => null, 'code' => 'BUENFIN', 'starts_at' => $now->copy()->startOfMonth(), 'ends_at' => $now->copy()->addMonth()->endOfMonth(), 'show_in_portal' => 1, 'uses' => 14, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $tid, 'name' => 'Sube a Premium -20%', 'description' => '20% de descuento los primeros 3 meses al cambiar a Premium.', 'type' => 'descuento_pct', 'value' => 20, 'months' => 3, 'plan_ids' => json_encode([$plans[3]->id]), 'code' => 'PREMIUM20', 'starts_at' => $now->copy()->subDays(20), 'ends_at' => $now->copy()->addDays(40), 'show_in_portal' => 1, 'uses' => 9, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['tenant_id' => $tid, 'name' => 'Pago anual: 1 mes gratis', 'description' => 'Paga 12 meses y recibe uno gratis.', 'type' => 'mes_gratis', 'value' => 0, 'months' => 1, 'plan_ids' => null, 'code' => 'ANUAL', 'starts_at' => null, 'ends_at' => null, 'show_in_portal' => 1, 'uses' => 6, 'active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // Referidos
        $clients = Client::query()->billable()->inRandomOrder()->limit($big ? 60 : 6)->get();
        foreach ($clients->take($big ? 40 : 4) as $c) {
            app(\App\Services\ReferralService::class)->ensureCode($c);
        }
        $referrers = $clients->take($big ? 18 : 2);
        $referred = Client::query()->billable()->where('installation_date', '>=', $now->copy()->subMonths(6))->whereNotIn('id', $referrers->pluck('id'))->inRandomOrder()->limit($big ? 24 : 2)->get();
        foreach ($referred as $i => $c) {
            $ref = $referrers[$i % $referrers->count()];
            $status = $i % 4 === 3 ? 'pendiente' : 'recompensado';
            $c->forceFill(['referred_by_client_id' => $ref->id])->saveQuietly();
            DB::table('referrals')->insert([
                'tenant_id' => $tid, 'referrer_client_id' => $ref->id, 'referred_client_id' => $c->id, 'code' => $ref->referral_code, 'status' => $status,
                'reward_type' => 'mes_gratis', 'reward_value' => $status === 'recompensado' ? (float) $ref->plan?->price : 0,
                'validated_at' => $status !== 'pendiente' ? $c->installation_date->copy()->addMonth() : null,
                'rewarded_at' => $status === 'recompensado' ? $c->installation_date->copy()->addMonth() : null,
                'created_at' => $c->installation_date, 'updated_at' => $now,
            ]);
        }

        // Portal cautivo: anuncios, estadísticas, vouchers, sesiones
        $ads = [
            ['Farmacia San Pablo', 'farmacia', '2x1 en vitaminas esta semana'], ['Tacos El Güero', 'restaurante', 'Martes de tacos al pastor a $12'],
            ['Papelería La Estrella', 'comercio', 'Útiles escolares con 15% de descuento'], ['Colegio Valle Verde', 'escuela', 'Inscripciones abiertas ciclo 2027'],
            ['Taller Mecánico Ruiz', 'servicio', 'Afinación completa desde $899'], ['Abarrotes Doña Mary', 'comercio', 'Recargas y pago de servicios'],
        ];
        foreach ($big ? $ads : array_slice($ads, 0, 2) as $k => [$adv, $type, $title]) {
            $adId = DB::table('captive_ads')->insertGetId([
                'tenant_id' => $tid, 'advertiser' => $adv, 'business_type' => $type, 'campaign' => 'Campaña '.$now->translatedFormat('F'), 'title' => $title,
                'body' => 'Presenta este anuncio en tienda.', 'url' => 'https://maps.google.com', 'starts_at' => $now->copy()->subDays(30), 'ends_at' => $now->copy()->addDays(30 + $k * 5),
                'impressions' => 0, 'clicks' => 0, 'price' => [300, 500, 800][mt_rand(0, 2)], 'weight' => mt_rand(1, 3), 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $imp = 0;
            $clk = 0;
            for ($d = 29; $d >= 0; $d--) {
                $i = mt_rand(80, 260);
                $c = (int) round($i * $this->rand(0.01, 0.06));
                $imp += $i;
                $clk += $c;
                DB::table('captive_ad_stats')->insert(['captive_ad_id' => $adId, 'date' => $now->copy()->subDays($d)->toDateString(), 'impressions' => $i, 'clicks' => $c]);
            }
            DB::table('captive_ads')->where('id', $adId)->update(['impressions' => $imp, 'clicks' => $clk]);
        }
        $rows = [];
        foreach (range(1, $big ? 60 : 10) as $i) {
            $used = $i <= 25;
            $rows[] = ['tenant_id' => $tid, 'code' => strtoupper(Str::random(8)), 'batch' => 'LOTE-'.$now->format('Ym'), 'duration_minutes' => [60, 180, 1440][$i % 3],
                'price' => [10, 25, 50][$i % 3], 'status' => $used ? 'used' : 'available', 'mac' => $used ? $this->mac() : null,
                'used_at' => $used ? $now->copy()->subDays(mt_rand(0, 20)) : null, 'expires_at' => $now->copy()->addMonths(3), 'created_at' => $now, 'updated_at' => $now];
        }
        DB::table('captive_vouchers')->insert($rows);
        $rows = [];
        foreach (range(1, $big ? 300 : 30) as $i) {
            $at = $now->copy()->subDays(mt_rand(0, 29))->setTime(mt_rand(7, 23), mt_rand(0, 59));
            $rows[] = ['tenant_id' => $tid, 'method' => $this->pick(['usuario' => 60, 'voucher' => 25, 'codigo' => 15]), 'identifier' => 'c'.mt_rand(1001, 1900), 'mac' => $this->mac(), 'ip' => '10.100.0.'.mt_rand(2, 250), 'success' => mt_rand(1, 100) <= 94, 'created_at' => $at, 'updated_at' => $at];
        }
        DB::table('captive_sessions')->insert($rows);

        // Campañas enviadas
        DB::table('campaigns')->insert([
            ['tenant_id' => $tid, 'name' => 'Aviso Buen Fin', 'channel' => 'whatsapp', 'segment' => 'activos', 'segment_value' => null, 'subject' => null, 'body' => '¡Hola {nombre}! Este Buen Fin sube de plan con 20% de descuento.', 'scheduled_at' => null, 'status' => 'sent', 'recipients_count' => 640, 'sent_count' => 631, 'failed_count' => 9, 'created_by' => null, 'sent_at' => $now->copy()->subDays(6), 'created_at' => $now->copy()->subDays(6), 'updated_at' => $now],
            ['tenant_id' => $tid, 'name' => 'Recordatorio morosos', 'channel' => 'whatsapp', 'segment' => 'morosos', 'segment_value' => null, 'subject' => null, 'body' => 'Hola {nombre}, tienes un saldo pendiente de {saldo}. Evita la suspensión.', 'scheduled_at' => null, 'status' => 'sent', 'recipients_count' => 52, 'sent_count' => 52, 'failed_count' => 0, 'created_by' => null, 'sent_at' => $now->copy()->subDays(2), 'created_at' => $now->copy()->subDays(2), 'updated_at' => $now],
        ]);

        // Ejecuciones de automatizaciones (historial)
        $rules = \App\Models\AutomationRule::query()->get()->keyBy('event');
        $susp = DB::table('service_events')->where('tenant_id', $tid)->where('source', 'automation')->where('created_at', '>=', $now->copy()->subDays(30))->limit(200)->get();
        $rows = [];
        foreach ($susp as $e) {
            $rule = $e->type === 'suspension' ? $rules['payment_overdue'] ?? null : $rules['payment_received'] ?? null;
            if ($rule) {
                $rows[] = ['tenant_id' => $tid, 'automation_rule_id' => $rule->id, 'subject_type' => 'client', 'subject_id' => $e->client_id, 'status' => 'success',
                    'log' => json_encode([$e->network_result, 'Mensajes en cola: 1']), 'created_at' => $e->created_at, 'updated_at' => $e->created_at];
            }
        }
        if ($rows) {
            DB::table('automation_runs')->insert($rows);
        }
        foreach ($rules as $rule) {
            $count = DB::table('automation_runs')->where('automation_rule_id', $rule->id)->count();
            $rule->forceFill(['run_count' => $count, 'last_run_at' => $count ? $now->copy()->subHours(mt_rand(1, 20)) : null])->saveQuietly();
        }
    }

    protected function seedSaasInvoices(Tenant $tenant, SaasPlan $plan): void
    {
        for ($m = 6; $m >= 0; $m--) {
            $start = $this->now->copy()->subMonths($m)->startOfMonth();
            $commissions = (float) DB::table('payments')->where('tenant_id', $tenant->id)->whereBetween('paid_at', [$start, $start->copy()->endOfMonth()])->sum('platform_fee');
            DB::table('saas_invoices')->insert([
                'tenant_id' => $tenant->id, 'saas_plan_id' => $plan->id, 'folio' => 'SAAS-'.$tenant->id.'-'.$start->format('Ym'),
                'period_start' => $start, 'period_end' => $start->copy()->endOfMonth(), 'amount' => $plan->price_monthly, 'commissions' => $commissions,
                'total' => $plan->price_monthly + $commissions, 'status' => $m === 0 ? 'pending' : 'paid', 'paid_at' => $m === 0 ? null : $start->copy()->addDays(4),
                'created_at' => $start, 'updated_at' => $start,
            ]);
        }
    }
}
