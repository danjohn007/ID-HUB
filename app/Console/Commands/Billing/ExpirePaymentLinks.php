<?php

namespace App\Console\Commands\Billing;

use App\Services\Billing\CollectionAutomation;
use Illuminate\Console\Command;

class ExpirePaymentLinks extends Command
{
    protected $signature = 'payments:expire-links {--dry-run : Solo cuenta}';

    protected $description = 'Marca como expirados los enlaces "Pagar ahora" vencidos';

    public function handle(CollectionAutomation $automation): int
    {
        $n = $automation->expireLinks((bool) $this->option('dry-run'));
        $this->info(($this->option('dry-run') ? 'Por expirar: ' : 'Enlaces expirados: ').$n);

        return self::SUCCESS;
    }
}
