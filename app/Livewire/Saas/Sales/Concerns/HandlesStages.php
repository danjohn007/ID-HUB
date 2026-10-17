<?php

namespace App\Livewire\Saas\Sales\Concerns;

use App\Models\DemoRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio de etapa de un prospecto con sus reglas: "Perdido" pide motivo, "En prueba" lleva a crear
 * el WISP de prueba y "Demo agendada" abre la agenda si aún no tiene demo. Lo usan el tablero y la ficha.
 * Requiere SalesContext y Toasts; la vista incluye el recuadro de motivo (partials/lost-modal).
 */
trait HandlesStages
{
    public bool $showLost = false;

    public ?int $lostId = null;

    public array $lost = ['reason' => '', 'note' => ''];

    public function move(int $id, string $stage)
    {
        abort_unless(array_key_exists($stage, DemoRequest::STAGES), 422);
        $lead = DemoRequest::findOrFail($id);
        if ($lead->status === $stage) {
            return null;
        }
        if ($stage === 'lost') {
            $this->lostId = $lead->id;
            $this->lost = ['reason' => '', 'note' => ''];
            $this->resetValidation();
            $this->showLost = true;

            return null;
        }
        if ($stage === 'trial' && ! $lead->tenant_id) {
            return $this->redirectRoute('saas.dashboard', ['prospecto' => $lead->id], navigate: true); // crear su WISP de prueba
        }
        if ($stage === 'demo_scheduled' && ! $lead->meetings()->where('status', 'scheduled')->where('starts_at', '>=', now())->exists()) {
            $this->dispatch('schedule-demo', leadId: $lead->id); // la etapa cambia al agendar

            return null;
        }
        $lead->changeStage($stage);
        $this->refreshBadge();
        $this->toast(($lead->company ?: $lead->name).': '.DemoRequest::STAGES[$stage]);

        return null;
    }

    public function confirmLost(): void
    {
        $data = $this->validate([
            'lost.reason' => ['required', Rule::in(array_keys(DemoRequest::LOST_REASONS))],
            'lost.note' => 'nullable|string|max:300',
        ], [], ['lost.reason' => 'motivo', 'lost.note' => 'comentario'])['lost'];
        $lead = DemoRequest::findOrFail($this->lostId);
        $lead->changeStage('lost', ['lost_reason' => $data['reason']], $data['note'] ?: null);
        $this->showLost = false;
        $this->refreshBadge();
        $this->toast(($lead->company ?: $lead->name).' marcado como perdido', 'warning');
    }
}
