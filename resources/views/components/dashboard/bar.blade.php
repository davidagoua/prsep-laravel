@props(['label', 'code' => null, 'planned', 'engaged', 'paid'])

{{-- Exécution d'un niveau : engagé et payé rapportés au planifié (deux barres fines, valeurs en clair). --}}
@php
    $pct = fn (int $v) => $planned > 0 ? min(100, $v / $planned * 100) : 0;
    $fmt = fn (int $v) => number_format($v, 0, ',', ' ');
@endphp

<div class="group py-2" title="{{ $label }} — planifié {{ $fmt($planned) }} · engagé {{ $fmt($engaged) }} · payé {{ $fmt($paid) }} FCFA">
    <div class="mb-1 flex items-baseline justify-between gap-3 text-sm">
        <span class="min-w-0 truncate">
            @if ($code)<span class="me-1 font-mono text-xs text-zinc-500">{{ $code }}</span>@endif{{ $label }}
        </span>
        <span class="shrink-0 tabular-nums text-zinc-500 dark:text-zinc-400">{{ $fmt($planned) }}</span>
    </div>
    <div class="space-y-0.5">
        @foreach ([['engagé', $engaged, 'var(--series-2)'], ['payé', $paid, 'var(--series-1)']] as [$name, $value, $color])
            <div class="flex items-center gap-2">
                <div class="h-2 flex-1 rounded-full" style="background: var(--chart-track)">
                    <div class="h-2 rounded-full transition-all group-hover:opacity-90" style="width: {{ $pct($value) }}%; background: {{ $color }}"></div>
                </div>
                <span class="w-24 text-end text-xs tabular-nums text-zinc-600 dark:text-zinc-300">{{ $planned > 0 ? round($value / $planned * 100) : 0 }} % {{ $name }}</span>
            </div>
        @endforeach
    </div>
</div>
