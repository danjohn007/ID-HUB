<?php

namespace App\Services\Network;

use App\Models\Maintenance;
use App\Models\Repeater;
use App\Services\Messaging\MessageService;
use App\Support\Audit;

/**
 * Mantenimiento programado (sección 37): clientes afectados, aviso anticipado y
 * transición automática de estados según la ventana programada.
 */
class MaintenanceService
{
    /** Texto de zona/alcance para los avisos. */
    public function scopeLabel(Maintenance $m): string
    {
        $parts = array_filter([$m->zone?->name, $m->repeater ? 'repetidor '.$m->repeater->code : null]);

        return $parts ? implode(' · ', $parts) : 'tu zona';
    }

    /** Envía el aviso 'maintenance' a los clientes potencialmente afectados. Devuelve cuántos. */
    public function notify(Maintenance $m, ?array $channels = null): int
    {
        $m->loadMissing('zone', 'repeater');
        $vars = [
            'fecha' => $m->starts_at->translatedFormat('d \d\e F \a \l\a\s H:i').($m->ends_at ? ' a '.$m->ends_at->format('H:i') : ''),
            'zona' => $this->scopeLabel($m),
            'descripcion' => (string) $m->description,
        ];
        $messages = app(MessageService::class);
        $n = 0;
        $m->affectedClientsQuery()->with('plan:id,name,download_mbps,upload_mbps')->chunkById(200, function ($clients) use ($messages, $vars, $channels, &$n) {
            foreach ($clients as $c) {
                $messages->notify($c, 'maintenance', $vars, $channels);
                $n++;
            }
        });
        $m->forceFill(['notified_at' => now(), 'affected_clients' => $n])->save();
        Audit::log('notify', "notificó mantenimiento «{$m->title}» a {$n} clientes", $m);

        return $n;
    }

    /** Avisos automáticos: mantenimientos que entran en su ventana de aviso (notify_hours_before). */
    public function notifyDue(): int
    {
        $sent = 0;
        foreach (Maintenance::query()->where('status', 'scheduled')->whereNull('notified_at')->where('starts_at', '>', now())->get() as $m) {
            if ($m->starts_at->copy()->subHours((int) $m->notify_hours_before)->lte(now())) {
                $sent += $this->notify($m);
            }
        }

        return $sent;
    }

    /** scheduled → in_progress al iniciar la ventana; in_progress → completed al terminar. */
    public function syncStatuses(): void
    {
        Maintenance::query()->where('status', 'scheduled')->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get()->each(fn ($m) => $m->update(['status' => 'in_progress']));
        Maintenance::query()->where('status', 'in_progress')->whereNotNull('ends_at')->where('ends_at', '<=', now())
            ->get()->each(fn ($m) => $m->update(['status' => 'completed']));
    }

    /** Repetidores dentro de una ventana de mantenimiento en curso (no generan falla). */
    public function repeatersInMaintenance(): array
    {
        $ids = [];
        $active = Maintenance::query()->where('status', 'in_progress')->get();
        foreach ($active as $m) {
            if ($m->repeater_id) {
                $ids[] = $m->repeater_id;
            }
            if ($m->zone_id) {
                $ids = array_merge($ids, Repeater::query()->where('zone_id', $m->zone_id)->pluck('id')->all());
            }
            if ($m->device_ids) {
                $ids = array_merge($ids, Repeater::query()->where(fn ($q) => $q->whereIn('router_device_id', $m->device_ids)->orWhereIn('ap_device_id', $m->device_ids))->pluck('id')->all());
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
