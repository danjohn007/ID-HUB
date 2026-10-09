{{-- Insignia de SLA de un ticket: @include('livewire.tickets.partials.sla', ['ticket' => $t]) --}}
@php
    $state = $ticket->slaState();
    $due = $ticket->sla_due_at;
    [$slaLabel, $slaColor] = match ($state) {
        'breached' => [$ticket->isOpen() ? 'Vencido '.($due ? $due->diffForHumans(['short' => true]) : '') : 'Fuera de SLA', 'danger'],
        'risk' => ['Vence '.$due->diffForHumans(['short' => true]), 'warning'],
        'done' => ['Cumplido', 'success'],
        default => [$due ? 'Vence '.$due->diffForHumans(['short' => true]) : 'Sin SLA', $due ? 'info' : 'slate'],
    };
@endphp
<x-ui.badge :color="$slaColor" :size="$size ?? 'xs'" dot title="{{ $due ? 'Objetivo: '.$due->format('d/m/Y H:i') : '' }}">{{ $slaLabel }}</x-ui.badge>
