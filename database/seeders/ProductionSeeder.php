<?php

namespace Database\Seeders;

use App\Models\SaasPlan;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos mínimos para producción: planes SaaS y el superadministrador.
 * Credenciales iniciales desde .env (SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD).
 */
class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (static::plans() as $plan) {
            SaasPlan::updateOrCreate(['code' => $plan['code']], $plan);
        }

        User::firstOrCreate(
            ['email' => env('SUPERADMIN_EMAIL', 'superadmin@wispcontrol.com')],
            ['name' => 'Superadministrador', 'role' => 'superadmin', 'password' => env('SUPERADMIN_PASSWORD', 'CambiaEsta2026!'), 'active' => true]
        );
    }

    public static function plans(): array
    {
        return [
            [
                'code' => 'STARTER', 'name' => 'Starter', 'description' => 'Para WISP que inician', 'price_monthly' => 799,
                'max_clients' => 100, 'max_devices' => 5, 'max_users' => 3, 'commission_percent' => 3, 'commission_fixed' => 0,
                'features' => ['dashboard', 'cobranza'], 'sort' => 1, 'active' => true,
            ],
            [
                'code' => 'PRO', 'name' => 'Pro', 'description' => 'Operación completa con campo y WhatsApp', 'price_monthly' => 1999,
                'max_clients' => 500, 'max_devices' => 25, 'max_users' => 15, 'commission_percent' => 2.5, 'commission_fixed' => 0,
                'features' => ['dashboard', 'cobranza', 'app_tecnico', 'app_cliente', 'whatsapp', 'mapas', 'portal_cautivo', 'automatizaciones', 'multiusuario'],
                'sort' => 2, 'active' => true,
            ],
            [
                'code' => 'ENTERPRISE', 'name' => 'Enterprise', 'description' => 'Hasta 5,000+ clientes, BI, API y marca blanca', 'price_monthly' => 4999,
                'max_clients' => 5000, 'max_devices' => null, 'max_users' => null, 'commission_percent' => 2, 'commission_fixed' => 0,
                'features' => ['*'], 'sort' => 3, 'active' => true,
            ],
        ];
    }
}
