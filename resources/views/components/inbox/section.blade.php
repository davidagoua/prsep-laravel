@props(['section', 'limit' => null])

@php
    $accent = [
        'navy' => 'bg-prseb-navy-600',
        'orange' => 'bg-prseb-orange-500',
        'green' => 'bg-prseb-green-600',
        'sky' => 'bg-sky-500',
        'zinc' => 'bg-zinc-400',
    ][$section['tone']] ?? 'bg-zinc-400';
    $items = $limit ? $section['items']->take($limit) : $section['items'];
@endphp

<section {{ $attributes->class('overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800') }}>
    <header class="flex items-center gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
        <span class="h-6 w-1 rounded-full {{ $accent }}"></span>
        <div class="flex-1">
            <div class="font-semibold">{{ $section['title'] }} <span class="ms-1 text-sm font-normal text-zinc-500">({{ $section['items']->count() }})</span></div>
            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $section['description'] }}</div>
        </div>
    </header>

    <ul class="divide-y divide-zinc-100 dark:divide-zinc-700">
        @foreach ($items as $item)
            @if ($item instanceof \App\Models\Fleet\Vehicle || $item instanceof \App\Models\Fleet\VehicleTrip)
                {{-- Parc automobile --}}
                @php
                    $vehicle = $item instanceof \App\Models\Fleet\Vehicle ? $item : $item->vehicle;
                @endphp
                <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="font-mono font-medium">{{ $vehicle->plate }} <span class="font-sans text-xs font-normal text-zinc-500">{{ $vehicle->name() }}</span></div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $item instanceof \App\Models\Fleet\VehicleTrip ? $item->driver_name.', retour prévu '.$item->expected_return_at->diffForHumans() : $vehicle->serviceAlert() }}
                        </div>
                    </div>
                    <flux:button size="sm" :href="route('fleet.vehicle', $vehicle)" wire:navigate>Ouvrir</flux:button>
                </li>
                @continue
            @endif
            @if ($item instanceof \App\Models\Report || $item instanceof \App\Models\ReportObligation)
                {{-- Rapports --}}
                @php
                    $isReport = $item instanceof \App\Models\Report;
                    $url = $isReport ? route('reports.show', $item) : route('reports.index', ['vue' => 'remettre']);
                @endphp
                <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $item->direction?->code }} · {{ $item->periode?->label }}</div>
                        <div class="truncate font-medium">{{ $isReport ? $item->title : $item->type->label() }}</div>
                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                            @if ($section['key'] === 'report_decide')
                                Remis {{ $item->submitted_at?->diffForHumans() }}
                            @elseif ($section['key'] === 'report_fix')
                                @if ($item->transitions->first()?->comment) « {{ \Illuminate\Support\Str::limit($item->transitions->first()->comment, 90) }} » @endif
                            @else
                                Échéance le {{ $item->due_on->translatedFormat('j F') }}
                                @if ($item->due_on->lt(today())) <flux:badge size="sm" color="red" class="ms-1">En retard</flux:badge> @endif
                            @endif
                        </div>
                    </div>
                    <flux:button size="sm" :variant="$section['key'] === 'report_decide' ? 'primary' : 'filled'" :href="$url" wire:navigate>
                        {{ ['report_decide' => 'Examiner', 'report_fix' => 'Corriger', 'report_due' => 'Déposer'][$section['key']] }}
                    </flux:button>
                </li>
                @continue
            @endif
            @php
                $isTdr = $item instanceof \App\Models\Tdr;
                $activity = $isTdr ? $item->activity : $item;
                $url = $section['key'] === 'decide' ? route('validations', ['tdr' => $item->id]) : route('activities.show', $activity);
                $cta = [
                    'decide' => 'Examiner', 'fix' => 'Corriger', 'submit' => 'Soumettre',
                    'start' => 'Démarrer', 'close' => 'Ouvrir', 'prepare' => 'Déposer le TDR',
                ][$section['key']];
            @endphp
            <li class="flex flex-wrap items-center gap-3 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($activity->code) <span class="font-mono">{{ $activity->code }}</span> @endif
                        <span>{{ $activity->direction?->code }}</span>
                        <span>· {{ $activity->volet->label() }}</span>
                    </div>
                    <div class="truncate font-medium">{{ $activity->label }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        @switch($section['key'])
                            @case('decide')
                                {{ $item->currentStep?->label }} · en attente depuis {{ $item->step_entered_at?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}
                                @if ($item->step_entered_at?->lt(now()->subDays(5)))
                                    <flux:badge size="sm" color="red" class="ms-1">En retard</flux:badge>
                                @endif
                                @break
                            @case('fix')
                                Renvoyé par {{ $item->returnedFromStep?->label }}
                                @if ($item->transitions->first()?->comment) : « {{ \Illuminate\Support\Str::limit($item->transitions->first()->comment, 90) }} » @endif
                                @break
                            @case('prepare')
                                Démarrage prévu {{ $activity->starts_on ? 'le '.$activity->starts_on->translatedFormat('j F') : 'non daté' }}
                                @break
                            @default
                                {{ $item->title }} · mis à jour {{ $item->updated_at->diffForHumans() }}
                        @endswitch
                    </div>
                </div>
                <flux:button size="sm" :variant="$section['key'] === 'decide' ? 'primary' : 'filled'" :href="$url" wire:navigate>{{ $cta }}</flux:button>
            </li>
        @endforeach
    </ul>

    @if ($limit && $section['items']->count() > $limit)
        <a href="{{ route('inbox') }}" wire:navigate class="block border-t border-zinc-100 px-4 py-2 text-center text-sm text-prseb-navy-600 hover:underline dark:border-zinc-700 dark:text-prseb-navy-300">
            Voir les {{ $section['items']->count() }} éléments
        </a>
    @endif
</section>
