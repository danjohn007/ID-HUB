<?php

namespace App\Livewire\Audit;

use App\Livewire\Concerns\WithTable;
use App\Models\AuditLog;
use App\Services\ReportExporter;
use App\Support\Catalog;
use Carbon\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bitácora de auditoría (sección 43): usuario, acción, fecha/hora, IP,
 * registro afectado y valores anterior → nuevo.
 */
#[Title('Auditoría')]
class Index extends Component
{
    use WithTable;

    #[Url(as: 'usuario', except: '')]
    public string $filterUser = '';

    #[Url(as: 'accion', except: '')]
    public string $filterAction = '';

    #[Url(as: 'tipo', except: '')]
    public string $filterType = '';

    #[Url(as: 'desde', except: '')]
    public string $filterFrom = '';

    #[Url(as: 'hasta', except: '')]
    public string $filterTo = '';


    /** Nombres legibles de los tipos de registro. */
    public const TYPES = [
        'Client' => 'Cliente', 'Payment' => 'Pago', 'Charge' => 'Cargo', 'Plan' => 'Plan', 'Repeater' => 'Repetidor',
        'NetworkDevice' => 'Equipo', 'Tower' => 'Torre', 'Ticket' => 'Ticket', 'User' => 'Usuario', 'Tenant' => 'Empresa',
        'Zone' => 'Zona', 'Prospect' => 'Prospecto', 'InventoryItem' => 'Inventario', 'AutomationRule' => 'Automatización',
        'PaymentGateway' => 'Pasarela', 'InstallationOrder' => 'Instalación', 'Promotion' => 'Promoción', 'Campaign' => 'Campaña',
        'SaasInvoice' => 'Factura SaaS', 'SaasPlan' => 'Plan SaaS', 'Maintenance' => 'Mantenimiento',
    ];

    public function clearFilters(): void
    {
        $this->reset('filterUser', 'filterAction', 'filterType', 'filterFrom', 'filterTo', 'search');
        $this->resetPage();
    }

    protected function date(string $v): ?Carbon
    {
        try {
            return $v ? Carbon::parse($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function query()
    {
        $term = trim($this->search);

        return AuditLog::query()
            ->when($this->filterUser !== '', fn ($q) => $this->filterUser === 'system' ? $q->whereNull('user_id') : $q->where('user_id', $this->filterUser))
            ->when($this->filterAction, fn ($q) => $q->where('action', $this->filterAction))
            ->when($this->filterType, fn ($q) => $q->where('auditable_type', $this->filterType))
            ->when($this->date($this->filterFrom), fn ($q, $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when($this->date($this->filterTo), fn ($q, $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$term}%")
                ->orWhere('user_name', 'like', "%{$term}%")->orWhere('ip', 'like', "%{$term}%")
                ->orWhere('auditable_id', $term)));
    }

    public static function actionLabel(?string $action): string
    {
        return Catalog::label('audit_actions', $action);
    }

    public static function typeLabel(?string $type): string
    {
        return $type ? (self::TYPES[$type] ?? $type) : '—';
    }

    /** Valor legible para el diff. */
    public static function show(mixed $v): string
    {
        return match (true) {
            $v === null || $v === '' => '∅',
            is_bool($v) => $v ? 'Sí' : 'No',
            is_array($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => (string) $v,
        };
    }

    public function export(string $format = 'xlsx')
    {
        $rows = $this->query()->orderByDesc('created_at')->limit(20000)->get();
        $diff = function ($l) {
            $keys = array_unique(array_merge(array_keys($l->old_values ?? []), array_keys($l->new_values ?? [])));

            return collect($keys)->map(fn ($k) => $k.': '.self::show($l->old_values[$k] ?? null).' → '.self::show($l->new_values[$k] ?? null))->implode('; ');
        };

        \App\Support\Audit::log('export', 'exportó la bitácora de auditoría ('.$rows->count().' registros)');

        return app(ReportExporter::class)->download('auditoria', $format, [
            'Fecha' => fn ($l) => $l->created_at?->format('d/m/Y H:i:s'), 'Usuario' => fn ($l) => $l->user_name ?? 'Sistema',
            'Acción' => fn ($l) => self::actionLabel($l->action), 'Descripción' => 'description',
            'Registro' => fn ($l) => $l->auditable_type ? self::typeLabel($l->auditable_type).' #'.$l->auditable_id : '',
            'Cambios' => $diff, 'IP' => 'ip',
        ], $rows, 'Bitácora de auditoría');
    }

    public function render()
    {
        $base = AuditLog::query();

        return view('livewire.audit.index', [
            'logs' => $this->query()->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage),
            'users' => (clone $base)->whereNotNull('user_id')->select('user_id', 'user_name')->groupBy('user_id', 'user_name')->orderBy('user_name')->get()
                ->mapWithKeys(fn ($r) => [$r->user_id => $r->user_name])->put('system', 'Sistema (automático)'),
            'actions' => (clone $base)->select('action')->distinct()->orderBy('action')->pluck('action')->mapWithKeys(fn ($a) => [$a => self::actionLabel($a)]),
            'types' => (clone $base)->whereNotNull('auditable_type')->select('auditable_type')->distinct()->pluck('auditable_type')
                ->mapWithKeys(fn ($t) => [$t => self::typeLabel($t)])->sort(),
            'today' => (clone $base)->where('created_at', '>=', today())->count(),
        ]);
    }
}
