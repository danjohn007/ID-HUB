<div>
    <x-ui.page-header title="Sistema" subtitle="Actualización de la base de datos y respaldos de la plataforma" />

    {{-- Estado de la base de datos --}}
    @if ($pendingCount)
        <div class="mb-5 flex flex-col gap-4 rounded-xl bg-warning-50 p-5 ring-1 ring-warning-200/60 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-6 w-6 shrink-0 text-warning-600" />
                <div>
                    <div class="font-semibold text-warning-800">{{ $pendingCount }} {{ $pendingCount === 1 ? 'migración pendiente' : 'migraciones pendientes' }}</div>
                    <p class="mt-0.5 text-sm text-warning-700">Se subió código nuevo que necesita cambios en la base de datos. Antes de aplicarlos se crea un respaldo automático.</p>
                </div>
            </div>
            <x-ui.button icon="arrow-path" wire:click="update" wire:confirm="Se creará un respaldo y se aplicarán {{ $pendingCount }} migraciones. ¿Continuar?"
                         wire:loading.attr="disabled" wire:target="update" class="shrink-0">
                <span wire:loading.remove wire:target="update">Respaldar y actualizar</span>
                <span wire:loading wire:target="update">Actualizando…</span>
            </x-ui.button>
        </div>
    @else
        <div class="mb-5 flex items-center gap-3 rounded-xl bg-success-50 p-5 ring-1 ring-success-200/60">
            <x-heroicon-o-check-circle class="h-6 w-6 shrink-0 text-success-600" />
            <div>
                <div class="font-semibold text-success-800">Base de datos al día</div>
                <p class="mt-0.5 text-sm text-success-700">Las {{ $totalCount }} migraciones del código ya están aplicadas.</p>
            </div>
        </div>
    @endif

    @if ($result)
        <x-ui.card :title="$result['ok'] ? 'Resultado de la actualización' : 'La actualización falló'" class="mb-5">
            <pre @class(['max-h-80 overflow-auto whitespace-pre-wrap rounded-lg p-4 font-mono text-xs', 'bg-slate-50 text-slate-700' => $result['ok'], 'bg-danger-50 text-danger-800' => ! $result['ok']])>{{ implode("\n\n", $result['log']) }}</pre>
        </x-ui.card>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Migraciones" :subtitle="$showAll ? 'Todas, de la más reciente a la más antigua' : 'Pendientes de aplicar'" :padding="false">
                <x-slot:actions>
                    <x-ui.toggle wire:model.live="showAll" label="Ver todas" />
                </x-slot:actions>
                <x-ui.table>
                    <x-slot:head>
                        <th>Migración</th>
                        <th>Estado</th>
                    </x-slot:head>
                    @forelse ($migrations as $m)
                        <tr wire:key="m-{{ $m->name }}">
                            <td class="break-all font-mono text-xs text-slate-700">{{ $m->name }}</td>
                            <td class="whitespace-nowrap">
                                @if ($m->ran)
                                    <x-ui.badge color="success" dot>Aplicada · lote {{ $m->batch }}</x-ui.badge>
                                @else
                                    <x-ui.badge color="warning" dot>Pendiente</x-ui.badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="2" icon="check-circle" title="Sin migraciones pendientes" description="Activa «Ver todas» para revisar el historial." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>

            <x-ui.card title="Respaldos de la base de datos" :subtitle="'Se conservan los últimos '.$keep.' · diario a las 03:00 y antes de cada actualización'" :padding="false">
                <x-slot:actions>
                    <x-ui.button variant="secondary" size="sm" icon="circle-stack" wire:click="backup" wire:loading.attr="disabled" wire:target="backup">
                        <span wire:loading.remove wire:target="backup">Respaldar ahora</span>
                        <span wire:loading wire:target="backup">Respaldando…</span>
                    </x-ui.button>
                </x-slot:actions>
                <x-ui.table>
                    <x-slot:head>
                        <th>Archivo</th>
                        <th>Fecha</th>
                        <th class="text-right">Tamaño</th>
                        <th></th>
                    </x-slot:head>
                    @forelse ($backups as $b)
                        <tr wire:key="b-{{ $b->name }}">
                            <td class="break-all font-mono text-xs text-slate-700">{{ $b->name }}</td>
                            <td class="whitespace-nowrap text-sm text-slate-600">{{ $b->date->format('d/m/Y H:i') }}</td>
                            <td class="whitespace-nowrap text-right text-sm text-slate-600">{{ \Illuminate\Support\Number::fileSize($b->size, 1) }}</td>
                            <td class="whitespace-nowrap text-right">
                                <x-ui.button variant="ghost" size="sm" icon="arrow-down-tray" wire:click="download('{{ $b->name }}')" title="Descargar" />
                                <x-ui.button variant="ghost" size="sm" icon="trash" class="text-danger-600" wire:click="deleteBackup('{{ $b->name }}')" wire:confirm="¿Eliminar el respaldo {{ $b->name }}?" title="Eliminar" />
                            </td>
                        </tr>
                    @empty
                        <x-ui.empty :colspan="4" icon="circle-stack" title="Sin respaldos" description="Crea el primero con «Respaldar ahora»." />
                    @endforelse
                </x-ui.table>
            </x-ui.card>
        </div>

        <div class="space-y-6">
            <x-ui.card title="Entorno">
                <dl class="grid grid-cols-1 gap-4">
                    @foreach ($env as $label => $value)
                        <x-ui.detail :label="$label">{{ $value }}</x-ui.detail>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="Cómo actualizar">
                <ol class="list-decimal space-y-2 pl-4 text-sm text-slate-600">
                    <li>Sube el paquete nuevo y reemplaza los archivos (conserva <code class="text-xs">.env</code> y <code class="text-xs">storage/</code>).</li>
                    <li>Entra a esta pantalla: si hay migraciones pendientes, pulsa <span class="font-semibold">Respaldar y actualizar</span>.</li>
                    <li>Si algo falla, descarga el respaldo previo y restáuralo en phpMyAdmin → Importar.</li>
                </ol>
                <p class="mt-4 text-xs text-slate-500">Los respaldos quedan en el mismo servidor. Descarga uno periódicamente o activa las copias de seguridad de cPanel para tener una copia externa.</p>
            </x-ui.card>
        </div>
    </div>
</div>
