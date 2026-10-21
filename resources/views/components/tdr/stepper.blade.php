@props(['tdr'])

{{-- Circuit de validation : dépôt, étapes du circuit, approbation. --}}
@php
    $steps = $tdr->stepper();
    $submitted = $tdr->submitted_at !== null;
    $approved = in_array($tdr->state, [\App\Enums\TdrState::Approved, \App\Enums\TdrState::InProgress, \App\Enums\TdrState::Closed], true);
@endphp

<ol class="flex flex-wrap items-center gap-y-3 text-sm" aria-label="Circuit de validation">
    <li class="flex items-center gap-2">
        <span @class([
            'flex size-6 items-center justify-center rounded-full text-xs font-semibold',
            'bg-prseb-green-600 text-white' => $submitted,
            'bg-prseb-navy-600 text-white ring-4 ring-prseb-navy-100 dark:ring-prseb-navy-800' => ! $submitted,
        ])>
            @if ($submitted) <flux:icon.check variant="micro" /> @else 1 @endif
        </span>
        <span class="font-medium">Dépôt</span>
    </li>

    @foreach ($steps as $i => $entry)
        <li class="flex items-center gap-2">
            <span class="mx-2 h-px w-6 bg-zinc-300 sm:w-10 dark:bg-zinc-600"></span>
            <span @class([
                'flex size-6 items-center justify-center rounded-full text-xs font-semibold',
                'bg-prseb-green-600 text-white' => $entry['status'] === 'done',
                'bg-prseb-navy-600 text-white ring-4 ring-prseb-navy-100 dark:ring-prseb-navy-800' => $entry['status'] === 'current',
                'bg-prseb-orange-500 text-white ring-4 ring-prseb-orange-50 dark:ring-prseb-orange-600/30' => $entry['status'] === 'returned',
                'border border-zinc-300 text-zinc-500 dark:border-zinc-600' => $entry['status'] === 'upcoming',
            ])>
                @if ($entry['status'] === 'done') <flux:icon.check variant="micro" />
                @elseif ($entry['status'] === 'returned') <flux:icon.arrow-uturn-left variant="micro" />
                @else {{ $i + 2 }} @endif
            </span>
            <span @class(['font-medium', 'text-zinc-500 dark:text-zinc-400' => $entry['status'] === 'upcoming'])>
                {{ $entry['step']->label }}
            </span>
        </li>
    @endforeach

    <li class="flex items-center gap-2">
        <span class="mx-2 h-px w-6 bg-zinc-300 sm:w-10 dark:bg-zinc-600"></span>
        <span @class([
            'flex size-6 items-center justify-center rounded-full',
            'bg-prseb-green-600 text-white' => $approved,
            'border border-zinc-300 text-zinc-400 dark:border-zinc-600' => ! $approved,
        ])>
            <flux:icon.flag variant="micro" />
        </span>
        <span @class(['font-medium', 'text-zinc-500 dark:text-zinc-400' => ! $approved])>Approuvé</span>
    </li>
</ol>
