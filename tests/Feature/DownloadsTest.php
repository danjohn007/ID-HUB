<?php

namespace Tests\Feature;

use App\Http\Resources\PaymentResource;
use App\Livewire;
use App\Models\Client;
use App\Models\InstallationOrder;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire as LivewireTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Exportaciones (Excel, CSV, PDF) de los listados y documentos PDF individuales.
 */
class DownloadsTest extends TestCase
{
    use RefreshDatabase;

    /** Los datos de demostración se cargan una sola vez (RefreshDatabase + transacción por prueba). */
    protected bool $seed = true;

    protected string $seeder = DemoSeeder::class;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('email', 'admin@wispcontrol.com')->firstOrFail();
        $this->actingAs($this->admin);
        app(TenantContext::class)->set($this->admin->tenant);
    }

    public static function exports(): array
    {
        $cases = [];
        $components = [
            'clientes' => [Livewire\Clients\Index::class, [], []],
            'cobranza' => [Livewire\Billing\Index::class, [], []],
            'cobranza-cargos' => [Livewire\Billing\Index::class, ['tab' => 'cargos'], []],
            'pagos' => [Livewire\Billing\Payments::class, [], []],
            'caja' => [Livewire\Billing\Cash::class, [], []],
            'tickets' => [Livewire\Tickets\Index::class, [], []],
            'inventario' => [Livewire\Inventory\Index::class, [], []],
            'inventario-movimientos' => [Livewire\Inventory\Index::class, ['tab' => 'movimientos'], []],
            'equipos' => [Livewire\Network\Devices\Index::class, [], []],
            'repetidores' => [Livewire\Network\Repeaters\Index::class, [], []],
            'auditoria' => [Livewire\Audit\Index::class, [], []],
            'reportes' => [Livewire\Reports\Index::class, [], []],
            'bi-zonas' => [Livewire\Bi\Index::class, [], ['zonas']],
            'bi-repetidores' => [Livewire\Bi\Index::class, [], ['repetidores']],
            'bi-crecimiento' => [Livewire\Bi\Index::class, [], ['crecimiento']],
            'bi-expansion' => [Livewire\Bi\Index::class, [], ['expansion']],
        ];
        foreach ($components as $name => [$class, $set, $args]) {
            foreach (['xlsx', 'csv', 'pdf'] as $format) {
                $cases["$name $format"] = [$class, $set, $args, $format];
            }
        }

        return $cases;
    }

    #[DataProvider('exports')]
    public function test_listing_exports(string $class, array $set, array $args, string $format): void
    {
        $component = LivewireTest::test($class);
        foreach ($set as $prop => $value) {
            $component->set($prop, $value);
        }
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $component->call('export', ...[...$args, $format])->assertHasNoErrors();
        if (getenv('REPORT_MEMORY')) {
            fwrite(STDERR, sprintf("\n%s %s: %.0f MB", $class, $format, (memory_get_peak_usage() - $base) / 1048576));
        }

        $download = data_get($component->effects, 'download');
        $this->assertNotEmpty($download, 'No se generó la descarga');
        $this->assertStringEndsWith('.'.$format, $download['name']);
        $content = base64_decode($download['content']);
        $this->assertNotEmpty($content, 'Archivo vacío');

        match ($format) {
            'pdf' => $this->assertStringStartsWith('%PDF', $content),
            'xlsx' => $this->assertStringStartsWith('PK', $content),
            default => $this->assertStringContainsString(',', $content),
        };
    }

    public function test_pdf_documents(): void
    {
        $client = Client::first();
        $urls = [
            route('documents.contract', $client),
            route('documents.statement', $client),
            route('documents.receipt', Payment::first()),
            route('documents.ticket', Ticket::first()),
        ];
        if ($order = InstallationOrder::first()) {
            $urls[] = route('documents.installation', $order);
        }

        foreach ($urls as $url) {
            $this->assertPdf($url);
        }
    }

    public function test_signed_and_public_pdfs(): void
    {
        $payment = Payment::where('status', 'completed')->firstOrFail();
        app(TenantContext::class)->set(null);
        auth()->logout();

        $this->assertPdf(PaymentResource::receiptUrl($payment));
        $this->assertPdf(URL::temporarySignedRoute('api.v1.statement', now()->addDay(), ['client' => $payment->client_id]));

        $linked = Payment::withoutGlobalScopes()->whereNotNull('payment_link_id')->with(['paymentLink' => fn ($q) => $q->withoutGlobalScopes()])->first();
        if ($linked?->paymentLink) {
            $this->assertPdf(route('pay.receipt', [$linked->paymentLink->token, $linked->id]));
        }
    }

    protected function assertPdf(string $url): void
    {
        $response = $this->get($url);
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent(), $url);
    }
}
