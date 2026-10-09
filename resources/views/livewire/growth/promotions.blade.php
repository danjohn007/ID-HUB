<div>
    <x-ui.page-header title="Promociones" subtitle="Descuentos, meses gratis e instalación gratis para captar y retener clientes">
        @if (Route::has('captive.index'))<x-ui.button variant="secondary" icon="globe-alt" :href="route('captive.index')">Portal cautivo</x-ui.button>@endif
        <x-ui.button icon="plus" wire:click="create">Nueva promoción</x-ui.button>
    </x-ui.page-header>

    <div class="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-ui.stat variant="soft" label="Vigentes" :value="$kpi['current']" icon="gift" color="success" />
        <x-ui.stat variant="soft" label="En portal cautivo" :value="$kpi['portal']" icon="globe-alt" color="brand" />
        <x-ui.stat variant="soft" label="Usos acumulados" :value="number_format($kpi['uses'])" icon="check-badge" color="purple" />
        <x-ui.stat variant="soft" label="Vencen en 7 días" :value="$kpi['expiring']" icon="clock" :color="$kpi['expiring'] ? 'warning' : 'slate'" />
    </div>

    <div class="mb-4 flex gap-2 overflow-x-auto pb-1">
        <button wire:click="$set('filterState', '')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterState === '', 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterState !== ''])>
            Todas <span class="ml-1 opacity-70">{{ $stateCounts->sum() }}</span>
        </button>
        @foreach ($states as $key => [$label, $color])
            <button wire:click="$set('filterState', '{{ $key }}')" @class(['whitespace-nowrap rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 transition', 'bg-navy-900 text-white ring-navy-900' => $filterState === $key, 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' => $filterState !== $key])>
                {{ $label }} <span class="ml-1 opacity-70">{{ $stateCounts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($promotions as $p)
            @php $st = \App\Livewire\Growth\Promotions::state($p); $planNames = $plans->whereIn('id', (array) ($p->plan_ids ?? []))->pluck('name'); @endphp
            <div wire:key="promo-{{ $p->id }}" @class(['card flex flex-col overflow-hidden', 'opacity-70' => in_array($st, ['vencida', 'inactiva'])])>
                @if ($p->image_path)
                    <img src="{{ Storage::disk('public')->url($p->image_path) }}" alt="" class="h-32 w-full object-cover">
                @else
                    <div class="flex h-24 items-center justify-between bg-gradient-to-br from-purple-600 to-brand-600 px-5 text-white">
                        <div class="text-2xl font-extrabold leading-tight">{{ $p->valueLabel() }}</div>
                        <x-heroicon-o-gift class="h-10 w-10 opacity-60" />
                    </div>
                @endif
                <div class="flex flex-1 flex-col p-5">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <div class="font-bold text-navy-900">{{ $p->name }}</div>
                            <div class="text-xs text-slate-500">{{ config('wisp.promotion_types.'.$p->type) }} · {{ $p->valueLabel() }}@if (in_array($p->type, ['descuento_pct', 'descuento_monto'])) · {{ $p->months }} mes(es)@endif</div>
                        </div>
                        <x-ui.badge :color="$states[$st][1]" size="xs" dot>{{ $states[$st][0] }}</x-ui.badge>
                    </div>
                    @if ($p->description)<p class="mt-2 text-sm text-slate-600">{{ $p->description }}</p>@endif
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
                        <div><dt class="text-slate-500">Código</dt><dd class="font-mono font-semibold text-slate-800">{{ $p->code ?? '—' }}</dd></div>
                        <div><dt class="text-slate-500">Usos</dt><dd class="font-semibold text-slate-800">{{ number_format($p->uses) }}</dd></div>
                        <div class="col-span-2"><dt class="text-slate-500">Vigencia</dt><dd class="font-semibold text-slate-800">{{ $p->starts_at?->format('d/m/Y') ?? 'Siempre' }} — {{ $p->ends_at?->format('d/m/Y') ?? 'Sin fin' }}</dd></div>
                        <div class="col-span-2"><dt class="text-slate-500">Planes</dt><dd class="font-semibold text-slate-800">{{ $planNames->isEmpty() ? 'Todos los planes' : $planNames->implode(', ') }}</dd></div>
                    </dl>
                    <div class="mt-auto flex items-center justify-between gap-2 border-t border-slate-100 pt-3 mt-4">
                        <button type="button" wire:click="toggle({{ $p->id }}, 'show_in_portal')" @class(['inline-flex items-center gap-1 text-xs font-semibold', 'text-brand-600' => $p->show_in_portal, 'text-slate-400' => ! $p->show_in_portal])>
                            <x-dynamic-component :component="$p->show_in_portal ? 'heroicon-m-eye' : 'heroicon-m-eye-slash'" class="h-4 w-4" />{{ $p->show_in_portal ? 'En portal' : 'Oculta en portal' }}
                        </button>
                        <div class="flex gap-1">
                            <x-ui.button size="sm" variant="ghost" :icon="$p->active ? 'pause' : 'play'" wire:click="toggle({{ $p->id }}, 'active')" :title="$p->active ? 'Desactivar' : 'Activar'" />
                            <x-ui.button size="sm" variant="secondary" icon="pencil-square" wire:click="edit({{ $p->id }})">Editar</x-ui.button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="card col-span-full"><x-ui.empty icon="gift" title="Sin promociones" description="Crea una promoción para mostrarla en el portal cautivo y aplicarla en instalaciones." /></div>
        @endforelse
    </div>

    <x-ui.modal wire:model="showForm" :title="$editingId ? 'Editar promoción' : 'Nueva promoción'" size="2xl">
        <form wire:submit="save" id="promo-form" class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <x-ui.input label="Nombre" wire:model="form.name" class="md:col-span-2" required />
            <x-ui.input label="Descripción" wire:model="form.description" class="md:col-span-2" />
            <x-ui.select label="Tipo" wire:model.live="form.type" :options="config('wisp.promotion_types')" required />
            @if (in_array($form['type'], ['descuento_pct', 'descuento_monto']))
                <x-ui.input label="Valor" type="number" step="0.01" wire:model="form.value" :suffix="$form['type'] === 'descuento_pct' ? '%' : null" :prefix="$form['type'] === 'descuento_monto' ? '$' : null" required />
            @else
                <x-ui.input label="Valor de referencia" type="number" step="0.01" wire:model="form.value" prefix="$" hint="Opcional (p. ej. costo de instalación que se condona)" />
            @endif
            <x-ui.input label="Meses que aplica" type="number" wire:model="form.months" required />
            <x-ui.input label="Código" wire:model="form.code" placeholder="BUENFIN" hint="Letras, números, guion" />
            <x-ui.input label="Inicio" type="date" wire:model="form.starts_at" />
            <x-ui.input label="Fin" type="date" wire:model="form.ends_at" />
            <div class="md:col-span-2">
                <label class="form-label">Planes (ninguno = todos)</label>
                <div class="flex flex-wrap gap-2">
                    @foreach ($plans as $pl)
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-1.5 text-sm has-[:checked]:border-brand-400 has-[:checked]:bg-brand-50">
                            <input type="checkbox" value="{{ $pl->id }}" wire:model="form.plan_ids" class="rounded border-slate-300 text-brand-600">
                            <span class="h-2 w-2 rounded-full" style="background: {{ $pl->color }}"></span>{{ $pl->name }}
                        </label>
                    @endforeach
                </div>
            </div>
            <div class="md:col-span-2">
                <label class="form-label">Imagen (portal cautivo)</label>
                <div class="flex items-center gap-3">
                    @if ($image)
                        <img src="{{ $image->temporaryUrl() }}" class="h-16 w-28 rounded-lg object-cover ring-1 ring-slate-200" alt="">
                    @elseif ($editingId && ($cur = \App\Models\Promotion::find($editingId)?->image_path))
                        <img src="{{ Storage::disk('public')->url($cur) }}" class="h-16 w-28 rounded-lg object-cover ring-1 ring-slate-200" alt="">
                        <button type="button" wire:click="removeImage" class="text-xs font-semibold text-danger-600">Quitar</button>
                    @endif
                    <input type="file" accept="image/*" wire:model="image" class="form-input flex-1">
                </div>
                @error('image')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
            </div>
            <x-ui.input label="Usos registrados" type="number" wire:model="form.uses" />
            <div class="flex flex-col justify-end gap-2 pb-1">
                <x-ui.toggle wire:model="form.show_in_portal" label="Mostrar en portal cautivo" />
                <x-ui.toggle wire:model="form.active" label="Activa" />
            </div>
        </form>
        <x-slot:footer>
            @if ($editingId)
                <x-ui.button variant="ghost" class="mr-auto text-danger-600" icon="trash" wire:click="delete({{ $editingId }})" wire:confirm="¿Eliminar la promoción?">Eliminar</x-ui.button>
            @endif
            <x-ui.button variant="secondary" @click="open = false">Cancelar</x-ui.button>
            <x-ui.button type="submit" form="promo-form" icon="check" wire:loading.attr="disabled">Guardar</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
