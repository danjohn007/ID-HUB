<?php

namespace App\Console\Commands\Billing;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Base de los comandos de cobranza: itera los WISP activos (o --tenant=ID).
 */
abstract class BillingCommand extends Command
{
    protected function tenants(): Collection
    {
        return Tenant::query()->whereIn('status', ['active', 'trial'])
            ->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();
    }

    protected function line_stats(Tenant $tenant, array $stats): void
    {
        $parts = collect($stats)->map(fn ($v, $k) => "{$k}=".(is_scalar($v) ? $v : json_encode($v)))->implode('  ');
        $this->line("  <info>#{$tenant->id} {$tenant->name}</info>  {$parts}");
    }
}
