<?php

namespace App\Livewire\Shell;

use App\Models\Client;
use App\Models\NetworkDevice;
use App\Models\Repeater;
use App\Models\Ticket;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Búsqueda global: clientes, tickets, equipos y repetidores.
 */
class GlobalSearch extends Component
{
    public string $q = '';

    public function render()
    {
        $results = [];
        $term = trim($this->q);
        $user = auth()->user();

        if (mb_strlen($term) >= 2) {
            if ($user->can('clients.view') && Route::has('clients.show')) {
                foreach (Client::search($term)->limit(6)->get() as $c) {
                    $results[] = ['group' => 'Clientes', 'title' => $c->full_name, 'sub' => $c->code.' · '.($c->phone ?: $c->address), 'url' => route('clients.show', $c), 'icon' => 'user'];
                }
            }
            if ($user->can('tickets.view') && Route::has('tickets.show')) {
                $tickets = Ticket::query()->where(fn ($w) => $w->where('folio', 'like', "%$term%")->orWhere('subject', 'like', "%$term%"))->latest()->limit(4)->get();
                foreach ($tickets as $t) {
                    $results[] = ['group' => 'Tickets', 'title' => $t->folio.' · '.$t->subject, 'sub' => config("wisp.ticket_status.{$t->status}.0"), 'url' => route('tickets.show', $t), 'icon' => 'ticket'];
                }
            }
            if ($user->can('network.view')) {
                if (Route::has('network.repeaters.show')) {
                    foreach (Repeater::query()->where(fn ($w) => $w->where('code', 'like', "%$term%")->orWhere('name', 'like', "%$term%"))->limit(3)->get() as $r) {
                        $results[] = ['group' => 'Repetidores', 'title' => $r->code.' · '.$r->name, 'sub' => $r->location, 'url' => route('network.repeaters.show', $r), 'icon' => 'wifi'];
                    }
                }
                if (Route::has('network.devices.show')) {
                    $devices = NetworkDevice::query()->where(fn ($w) => $w->where('name', 'like', "%$term%")->orWhere('ip', 'like', "%$term%")->orWhere('mac', 'like', "%$term%"))->limit(4)->get();
                    foreach ($devices as $d) {
                        $results[] = ['group' => 'Equipos', 'title' => $d->name, 'sub' => trim(config("wisp.device_types.{$d->type}").' · '.$d->ip, ' ·'), 'url' => route('network.devices.show', $d), 'icon' => 'server-stack'];
                    }
                }
            }
        }

        return view('livewire.shell.global-search', ['results' => collect($results)->groupBy('group')]);
    }
}
