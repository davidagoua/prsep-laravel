@props(['label', 'metrics', 'columns'])

{{-- Ligne de total sous l'arborescence. --}}
<div class="grid items-center gap-3 py-2.5 pe-3 ps-2 text-sm font-semibold" style="grid-template-columns: minmax(0,1fr) repeat({{ count($columns) }}, {{ count($columns) > 1 ? '7.5rem' : '9rem' }})">
    <span>{{ $label }}</span>
    @foreach ($columns as $column)
        <x-ptba.metric :node="['metrics' => $metrics]" :column="$column" />
    @endforeach
</div>
