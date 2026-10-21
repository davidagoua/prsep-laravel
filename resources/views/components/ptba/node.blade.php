@props(['node', 'depth' => 0, 'columns' => ['planned'], 'open' => 1, 'select' => null])

{{-- Ligne de l'arborescence du PTBA (niveau ou activité), repliable. --}}
@php
    $isActivity = $node['type'] === 'activity';
    $hasChildren = ! empty($node['children']);
    $levels = [
        'component' => 'Composante', 'subcomponent' => 'Sous-composante', 'ild' => 'ILD', 'hors_ild' => 'Hors ILD',
        'domain' => 'Domaine', 'subdomain' => 'Sous-domaine', 'action' => 'Action',
    ];
@endphp

<div x-data="{ open: {{ $depth < $open ? 'true' : 'false' }} }" x-on:ptba-toggle-all.window="open = $event.detail" role="treeitem" :aria-expanded="open">
    <div @class([
        'grid items-center gap-3 border-b border-zinc-100 py-2 pe-3 text-sm dark:border-zinc-700/60',
        'bg-prseb-navy-50/60 font-semibold dark:bg-prseb-navy-900/30' => $depth === 0,
        'hover:bg-zinc-50 dark:hover:bg-zinc-700/30' => $depth > 0,
    ]) style="grid-template-columns: minmax(0,1fr) repeat({{ count($columns) }}, {{ count($columns) > 1 ? '7.5rem' : '9rem' }}); padding-inline-start: {{ 0.5 + $depth * 1.25 }}rem">
        <div class="flex min-w-0 items-center gap-2">
            @if ($hasChildren)
                <button type="button" x-on:click="open = ! open" class="rounded p-0.5 text-zinc-500 hover:bg-zinc-200 dark:hover:bg-zinc-600" :aria-label="open ? 'Replier' : 'Déplier'">
                    <flux:icon.chevron-right variant="micro" class="transition" x-bind:class="open && 'rotate-90'" />
                </button>
            @else
                <span class="w-5"></span>
            @endif

            @if ($isActivity)
                @if ($select)
                    {{-- Ouvre le détail dans un panneau latéral (méthode Livewire de la page). --}}
                    <button type="button" wire:click="{{ $select }}({{ $node['activity']->id }})" class="flex min-w-0 flex-1 items-center gap-2 text-start hover:text-prseb-navy-600 dark:hover:text-prseb-navy-300">
                        @if ($node['code']) <span class="shrink-0 font-mono text-xs text-zinc-500">{{ $node['code'] }}</span> @endif
                        <span class="truncate" title="{{ $node['label'] }}">{{ $node['label'] }}</span>
                    </button>
                @else
                    <a href="{{ route('activities.show', $node['activity']) }}" wire:navigate class="flex min-w-0 flex-1 items-center gap-2 hover:text-prseb-navy-600 dark:hover:text-prseb-navy-300">
                        @if ($node['code']) <span class="shrink-0 font-mono text-xs text-zinc-500">{{ $node['code'] }}</span> @endif
                        <span class="truncate" title="{{ $node['label'] }}">{{ $node['label'] }}</span>
                    </a>
                @endif
                <span class="hidden shrink-0 text-xs text-zinc-500 sm:inline">{{ $node['activity']->direction?->code }}</span>
                @unless ($select)
                    <x-tdr.state :tdr="$node['activity']->tdr" class="hidden shrink-0 lg:inline-flex" />
                @endunless
            @else
                <flux:badge size="sm" :color="in_array($node['type'], ['ild']) ? 'blue' : 'zinc'" class="shrink-0">{{ $levels[$node['type']] ?? '' }} {{ $node['code'] }}</flux:badge>
                <span class="truncate" title="{{ $node['label'] }}">{{ $node['label'] }}</span>
                <span class="hidden shrink-0 text-xs font-normal text-zinc-500 2xl:inline">{{ $node['count'] }} act.</span>
            @endif
        </div>

        @foreach ($columns as $column)
            <x-ptba.metric :node="$node" :column="$column" />
        @endforeach
    </div>

    @if ($hasChildren)
        <div x-show="open" x-cloak role="group">
            @foreach ($node['children'] as $child)
                <x-ptba.node :node="$child" :depth="$depth + 1" :columns="$columns" :open="$open" :select="$select" />
            @endforeach
        </div>
    @endif
</div>
