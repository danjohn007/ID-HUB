<?php

namespace App\Livewire\Saas\Sales;

use App\Livewire\Concerns\Toasts;
use App\Livewire\Saas\Sales\Concerns\SalesContext;
use App\Models\DemoMeeting;
use App\Services\DemoScheduler;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ventas → Agenda de demos (superadmin): calendario mensual, semanal y lista de próximas demos,
 * con registro del resultado (realizada, no se presentó, cancelada).
 */
#[Title('Agenda de demos')]
class Agenda extends Component
{
    use SalesContext, Toasts;

    public const VIEWS = ['mes' => ['Mes', 'calendar'], 'semana' => ['Semana', 'view-columns'], 'lista' => ['Lista', 'list-bullet']];

    #[Url(as: 'vista', except: 'mes')]
    public string $view = 'mes';

    #[Url(as: 'fecha', except: '')]
    public string $date = '';

    #[Url(as: 'responsable', except: '')]
    public string $filterAssignee = '';

    public ?int $selectedId = null;

    public bool $showMeeting = false;

    public function mount(): void
    {
        abort_unless(auth()->user()->isSuperadmin(), 403);
        if (! array_key_exists($this->view, self::VIEWS)) {
            $this->view = 'mes';
        }
    }

    protected function anchor(): Carbon
    {
        try {
            return $this->date ? Carbon::createFromFormat('Y-m-d', $this->date)->startOfDay() : now()->startOfDay();
        } catch (\Throwable) {
            return now()->startOfDay();
        }
    }

    public function shift(int $dir): void
    {
        $a = $this->anchor();
        $this->date = ($this->view === 'semana' ? $a->addWeeks($dir) : $a->startOfMonth()->addMonthsNoOverflow($dir))->toDateString();
    }

    public function showWeek(string $date): void
    {
        $this->view = 'semana';
        $this->date = $date;
    }

    public function goToday(): void
    {
        $this->date = '';
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        $this->showMeeting = true;
    }

    public function markMeeting(int $id, string $status): void
    {
        $m = DemoMeeting::findOrFail($id);
        $m->markAs($status);
        $this->refreshBadge();
        $this->toast('Demo: '.mb_strtolower(DemoMeeting::STATUSES[$status]));
    }

    #[On('meeting-saved')]
    public function refreshAfterSave(?int $meetingId = null): void
    {
        $this->showMeeting = false;
        $this->refreshBadge();
    }

    public function render()
    {
        $a = $this->anchor();
        [$from, $to] = match ($this->view) {
            'semana' => [$a->copy()->startOfWeek(Carbon::MONDAY), $a->copy()->endOfWeek(Carbon::SUNDAY)],
            'lista' => [now()->subDays(14)->startOfDay(), now()->addDays(60)->endOfDay()],
            default => [$a->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY), $a->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY)],
        };

        $meetings = DemoMeeting::with(['lead', 'assignee'])
            ->whereBetween('starts_at', [$from, $to])
            ->when($this->filterAssignee, fn ($q) => $q->where('assigned_to', $this->filterAssignee))
            ->orderBy('starts_at')->get();

        $days = [];
        for ($d = $from->copy(); $d <= $to && $this->view !== 'lista'; $d->addDay()) {
            $days[] = $d->copy();
        }

        $weekdays = ['', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];

        return view('livewire.saas.sales.agenda', [
            'anchor' => $a,
            'days' => $days,
            'byDay' => $meetings->groupBy(fn ($m) => $m->starts_at->toDateString()),
            'meetings' => $meetings,
            'selected' => $this->showMeeting && $this->selectedId ? DemoMeeting::with(['lead', 'assignee'])->find($this->selectedId) : null,
            'users' => $this->salesUsers(),
            'heading' => $this->view === 'semana'
                ? $from->translatedFormat('j M').' – '.$to->translatedFormat('j M Y')
                : ($this->view === 'lista' ? 'Próximas demos' : Str::ucfirst($a->translatedFormat('F Y'))),
            'selfBooking' => (bool) DemoScheduler::config('self_booking'),
            'bookingHours' => implode(', ', (array) DemoScheduler::config('hours')),
            'bookingDays' => collect((array) DemoScheduler::config('weekdays'))->map(fn ($d) => $weekdays[$d] ?? $d)->implode(', '),
        ]);
    }
}
