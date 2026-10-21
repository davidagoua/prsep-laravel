<x-layouts.app.sidebar :title="$title ?? null">
    <flux:main>
        @if (session('status'))
            <flux:callout variant="success" icon="check-circle" class="mb-6" :heading="session('status')" />
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts.app.sidebar>
