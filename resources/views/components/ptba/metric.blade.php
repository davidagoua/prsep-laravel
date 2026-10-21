@props(['node', 'column'])

{{-- Cellule chiffrée de l'arborescence : montant, reste à payer ou taux d'exécution. --}}
@php
    $m = $node['metrics'];
    $value = match ($column) {
        'remaining' => ($m['engaged'] ?? 0) - ($m['paid'] ?? 0),
        'rate' => ($m['planned'] ?? 0) > 0 ? ($m['paid'] ?? 0) / $m['planned'] * 100 : null,
        default => $m[$column] ?? 0,
    };
@endphp

@if ($column === 'rate')
    <div class="flex items-center justify-end gap-2" title="Payé / planifié">
        @if ($value === null)
            <span class="text-zinc-400">—</span>
        @else
            <div class="h-1.5 w-12 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div @class(['h-full rounded-full', 'bg-prseb-green-600' => $value >= 50, 'bg-prseb-orange-500' => $value < 50]) style="width: {{ min(100, $value) }}%"></div>
            </div>
            <span class="w-10 text-end tabular-nums">{{ number_format($value, 0) }} %</span>
        @endif
    </div>
@else
    <div @class(['text-end tabular-nums', 'text-zinc-400' => ! $value])>{{ $value ? number_format($value, 0, ',', ' ') : '—' }}</div>
@endif
