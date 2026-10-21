<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 dark:bg-zinc-900">
        <flux:sidebar sticky stashable class="sidebar-prseb dark border-e text-white">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <div class="flex items-center gap-2">
                <a href="{{ route('dashboard') }}" class="flex min-w-0 flex-1 items-center gap-2" wire:navigate>
                    <x-app-logo />
                </a>
                <livewire:notifications.bell />
            </div>

            {{-- Exercice de travail : filtre toutes les données de l'application --}}
            <form method="POST" action="{{ route('exercice.switch') }}" data-test="exercice-switcher">
                @csrf
                <flux:select name="exercice_id" size="sm" label="Exercice" aria-label="Exercice de travail"
                    x-data x-on:change="$el.closest('form').requestSubmit()">
                    @forelse ($exercices as $exercice)
                        <flux:select.option :value="$exercice->id" :selected="$currentExercice?->is($exercice)">
                            {{ $exercice->label }}{{ $exercice->is_current ? ' (en cours)' : '' }}
                        </flux:select.option>
                    @empty
                        <flux:select.option value="" disabled selected>Aucun exercice</flux:select.option>
                    @endforelse
                </flux:select>
            </form>

            <flux:navlist variant="outline">
                @foreach ($navigation as $group)
                    <flux:navlist.group :heading="$group['heading']" class="grid">
                        @foreach ($group['items'] as $item)
                            @if ($item['lot'])
                                <flux:navlist.item :icon="$item['icon']" :badge="'Lot '.$item['lot']"
                                    class="pointer-events-none opacity-50" aria-disabled="true"
                                    title="Disponible au lot {{ $item['lot'] }}">
                                    {{ $item['label'] }}
                                </flux:navlist.item>
                            @else
                                <flux:navlist.item :icon="$item['icon']" :href="route($item['route'])"
                                    :badge="$item['badge'] ?: null" badge-color="orange"
                                    :current="request()->routeIs($item['active'])" wire:navigate>
                                    {{ $item['label'] }}
                                </flux:navlist.item>
                            @endif
                        @endforeach
                    </flux:navlist.group>
                @endforeach
            </flux:navlist>

            <flux:spacer />

            <!-- Menu utilisateur (bureau) -->
            <flux:dropdown position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevrons-up-down"
                />

                <flux:menu class="w-[240px]">
                    <x-user-menu-content />
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <!-- Menu utilisateur (mobile) -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <x-user-menu-content />
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <flux:toast />

        @fluxScripts
    </body>
</html>
