<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan migrate:fresh --seed            -> plataforma + 2 WISP de demostración
     * php artisan db:seed --class=ProductionSeeder -> solo planes SaaS y superadmin
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
