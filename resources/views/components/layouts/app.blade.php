<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ tenant()?->name ?? config('wisp.brand.name') }}</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @if (tenant() && tenant()->primary_color && tenant()->hasFeature('white_label'))
        <style>:root { --color-brand-600: {{ tenant()->primary_color }}; --color-brand-500: {{ tenant()->primary_color }}; }</style>
    @endif
    @stack('head')
</head>
<body class="min-h-screen font-sans" x-data="{ sidebar: false }">
    @php
        $user = auth()->user();
        $isSaas = $user->isSuperadmin() && ! tenant();
        $sections = $isSaas ? config('navigation.saas') : config('navigation.tenant');
        if ($user->isSuperadmin() && tenant()) {
            $sections = array_merge(config('navigation.saas'), $sections);
        }
        // Contadores del menú (6.º elemento del ítem en config/navigation.php); se calculan solo si el ítem es visible
        $badges = ['demo_requests' => fn () => \App\Models\DemoRequest::pendingCount(), 'demos_today' => fn () => \App\Services\DemoScheduler::todayCount()];
    @endphp

    {{-- Overlay móvil --}}
    <div x-cloak x-show="sidebar" x-transition.opacity class="fixed inset-0 z-30 bg-navy-950/60 lg:hidden" @click="sidebar = false"></div>

    {{-- Barra lateral --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-navy-900 transition-transform lg:translate-x-0"
           :class="sidebar && 'translate-x-0'">
        <div class="flex h-16 items-center px-5">
            <a href="{{ route($user->homeRoute()) }}"><x-brand.logo /></a>
        </div>
        <nav class="flex-1 space-y-2 overflow-y-auto px-3 pt-2 pb-6 [scrollbar-width:thin] [scrollbar-color:theme(colors.navy.700)_transparent]">
            @foreach ($sections as $section)
                @php
                    $items = collect($section['items'])->filter(fn ($i) => Route::has($i[1]) && $user->can($i[3]))
                        ->map(fn ($i) => [...$i, 'badge' => isset($i[5], $badges[$i[5]]) ? $badges[$i[5]]() : 0]);
                    // Los grupos se pliegan; el de la página actual (o con avisos pendientes) siempre se abre.
                    $forceOpen = $items->contains(fn ($i) => request()->routeIs($i[4]) || $i['badge'] > 0);
                    $groupKey = 'nav-'.Str::slug((string) $section['group']);
                @endphp
                @if ($items->isNotEmpty())
                    <div @if ($section['group']) x-data="{ open: $persist(false).as('{{ $groupKey }}') }" @if ($forceOpen) x-init="open = true" @endif @endif>
                        @if ($section['group'])
                            <button type="button" @click="open = !open" class="flex w-full items-center justify-between rounded-md px-3 py-1.5 text-2xs font-semibold tracking-wider text-navy-400 uppercase transition hover:text-navy-100">
                                {{ $section['group'] }}
                                <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform" x-bind:class="open ? '' : '-rotate-90'" />
                            </button>
                        @endif
                        <div class="space-y-0.5 {{ $section['group'] ? 'pb-2' : 'pb-1' }}" @if ($section['group']) x-show="open" x-collapse @unless ($forceOpen) x-cloak @endunless @endif>
                            @foreach ($items as $item)
                                @php
                                    [$label, $route, $icon, $ability, $pattern] = $item;
                                    $badge = $item['badge'];
                                @endphp
                                <a href="{{ route($route) }}" wire:navigate class="nav-link {{ request()->routeIs($pattern) ? 'active' : '' }}">
                                    <x-dynamic-component :component="'heroicon-o-'.$icon" class="h-5 w-5 shrink-0" />
                                    <span class="truncate">{{ $label }}</span>
                                    @isset($item[5])
                                        {{-- Los componentes lo actualizan sin recargar con $this->dispatch('nav-badge', key: ..., count: ...) --}}
                                        <span x-data="{ n: {{ (int) $badge }} }" x-on:nav-badge.window="if ($event.detail.key === '{{ $item[5] }}') n = $event.detail.count"
                                              x-show="n > 0" x-text="n > 99 ? '99+' : n" @if (! $badge) style="display: none" @endif
                                              class="ml-auto rounded-full bg-brand-500 px-1.5 py-px text-[11px] font-bold text-white">{{ $badge > 99 ? '99+' : $badge }}</span>
                                    @endisset
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>
        <div class="m-3 rounded-xl border border-white/10 bg-white/5 p-3 text-xs text-navy-200">
            <div class="flex items-center gap-2 font-semibold text-white">
                <span class="h-2.5 w-2.5 rounded-full bg-success-500 shadow-[0_0_0_3px_rgba(43,181,122,0.25)]"></span>
                Sistema en línea
            </div>
            <div class="mt-1">Última actualización:<br>{{ now()->format('d/m/Y H:i:s') }}</div>
        </div>
    </aside>

    <div class="lg:pl-64">
        {{-- Barra superior --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200/70 bg-white/90 px-4 backdrop-blur lg:px-6">
            <button class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden" @click="sidebar = true" aria-label="Menú">
                <x-heroicon-o-bars-3 class="h-6 w-6" />
            </button>

            <livewire:shell.tenant-switcher />

            <div class="hidden flex-1 md:block">
                @if (tenant())
                    <livewire:shell.global-search />
                @endif
            </div>
            <div class="flex-1 md:hidden"></div>

            <div class="flex items-center gap-1">
                @if (tenant())
                    <livewire:shell.alerts-bell />
                    @if (Route::has('communications.messages') && $user->can('communications.manage'))
                        <a href="{{ route('communications.messages') }}" wire:navigate class="rounded-lg p-2 text-success-600 hover:bg-success-50" title="Mensajes WhatsApp">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="currentColor"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.27-.1-.47-.15-.67.15-.2.3-.77.97-.94 1.17-.17.2-.35.22-.64.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.6.13-.13.3-.35.45-.52.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.67-1.61-.92-2.2-.24-.58-.49-.5-.67-.51h-.57c-.2 0-.52.07-.79.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.21 3.08c.15.2 2.1 3.2 5.08 4.49.71.31 1.26.49 1.69.63.71.23 1.36.2 1.87.12.57-.09 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.27-.2-.57-.35zM12.04 21.5h-.01a9.45 9.45 0 01-4.82-1.32l-.35-.21-3.58.94.96-3.49-.23-.36a9.43 9.43 0 01-1.45-5.04c0-5.22 4.25-9.46 9.48-9.46 2.53 0 4.9.99 6.69 2.78a9.4 9.4 0 012.77 6.69c0 5.22-4.25 9.47-9.46 9.47zm8.05-17.52A11.32 11.32 0 0012.04.65C5.77.65.66 5.75.66 12.02c0 2 .52 3.96 1.52 5.69L.57 23.6l6.03-1.58a11.36 11.36 0 005.43 1.38h.01c6.27 0 11.38-5.1 11.38-11.37 0-3.04-1.18-5.9-3.33-8.05z"/></svg>
                        </a>
                    @endif
                @endif
            </div>

            {{-- Usuario --}}
            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button class="flex items-center gap-2.5 rounded-lg p-1.5 hover:bg-slate-100" @click="open = !open">
                    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">{{ initials($user->name) }}</span>
                    <span class="hidden text-left leading-tight sm:block">
                        <span class="block text-sm font-semibold text-slate-800">{{ $user->name }}</span>
                        <span class="block text-xs text-slate-500">{{ $user->roleLabel() }}</span>
                    </span>
                    <x-heroicon-m-chevron-down class="hidden h-4 w-4 text-slate-400 sm:block" />
                </button>
                <div x-cloak x-show="open" x-transition class="absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                    <div class="border-b border-slate-100 px-3 py-2 text-xs text-slate-500">{{ $user->email }}</div>
                    @if (Route::has('profile'))
                        <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-50"><x-heroicon-o-user-circle class="h-4 w-4" /> Mi perfil</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-danger-600 hover:bg-danger-50"><x-heroicon-o-arrow-right-on-rectangle class="h-4 w-4" /> Cerrar sesión</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[1600px] p-4 lg:p-6">
            @php
                // Aviso de vigencia al administrador del WISP: prueba o suscripción por vencer / vencida.
                $subTenant = auth()->user()?->can('settings.manage') ? tenant() : null;
                $subEnds = $subTenant ? ($subTenant->status === 'trial' ? $subTenant->trial_ends_at : $subTenant->subscription_ends_at) : null;
                $subDays = $subEnds ? (int) today()->diffInDays($subEnds, false) : null;
            @endphp
            @if ($subDays !== null && $subDays <= 7)
                <div @class(['mb-4 flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm', 'bg-warning-50 text-warning-800' => $subDays >= 0, 'bg-danger-50 text-danger-700' => $subDays < 0])>
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0" />
                    <span>
                        @if ($subDays >= 0)
                            {{ $subTenant->status === 'trial' ? 'Tu periodo de prueba' : 'Tu suscripción a '.config('wisp.brand.name') }} vence {{ $subDays === 0 ? 'hoy' : 'en '.$subDays.' '.($subDays === 1 ? 'día' : 'días') }} ({{ $subEnds->format('d/m/Y') }}).
                        @else
                            Tu suscripción a {{ config('wisp.brand.name') }} venció el {{ $subEnds->format('d/m/Y') }}. Se suspenderá en {{ max(0, config('wisp.saas_grace_days') + $subDays) }} días si no se renueva.
                        @endif
                        Contacta a {{ config('wisp.brand.name') }} para renovarla.
                    </span>
                </div>
            @endif
            {{ $slot }}
        </main>
    </div>

    {{-- Toasts --}}
    <div x-data="toasts" @toast.window="add($event.detail)" class="pointer-events-none fixed right-4 bottom-4 z-50 flex w-80 flex-col gap-2"
         x-init="@if (session('success')) add({message: @js(session('success')), type: 'success'}) @endif
                 @if (session('error')) add({message: @js(session('error')), type: 'error'}) @endif
                 @if (session('warning')) add({message: @js(session('warning')), type: 'warning'}) @endif">
        <template x-for="t in items" :key="t.id">
            <div x-transition class="pointer-events-auto flex items-start gap-3 rounded-xl border bg-white p-3.5 text-sm shadow-lg"
                 :class="{ 'border-success-100': t.type === 'success', 'border-danger-100': t.type === 'error', 'border-warning-100': t.type === 'warning', 'border-slate-200': t.type === 'info' }">
                <span class="mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full"
                      :class="{ 'bg-success-600': t.type === 'success', 'bg-danger-600': t.type === 'error', 'bg-warning-600': t.type === 'warning', 'bg-brand-600': t.type === 'info' }"></span>
                <span class="flex-1 text-slate-700" x-text="t.message"></span>
                <button @click="remove(t.id)" class="text-slate-400 hover:text-slate-600">&times;</button>
            </div>
        </template>
    </div>

    @livewireScripts
    @stack('scripts')
</body>
</html>
