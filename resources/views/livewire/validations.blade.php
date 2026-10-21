<?php

use App\Enums\TdrState;
use App\Models\Tdr;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Validations')] class extends Component {
    #[Url(as: 'tdr')]
    public ?int $selectedId = null;

    /** mine : ma file de décisions · all : tous les dossiers en validation (supervision). */
    #[Url]
    public string $vue = 'mine';

    public function mount(): void
    {
        if ($this->vue === 'all' && ! auth()->user()->seesAllDirections()) {
            $this->vue = 'mine';
        }

        // Lien direct vers un dossier qui n'est pas dans ma file : on bascule en supervision s'il est visible.
        if ($this->selectedId && ! $this->queue->contains('id', $this->selectedId) && auth()->user()->seesAllDirections()) {
            $this->vue = 'all';
            unset($this->queue);
        }

        $this->selectedId ??= $this->queue->first()?->id;
    }

    #[Computed]
    public function queue()
    {
        $user = auth()->user();

        return Tdr::query()
            ->when($this->vue === 'mine',
                fn ($q) => $q->awaitingDecisionBy($user),
                fn ($q) => $q->where('state', TdrState::InReview)->visibleTo($user))
            ->with(['activity.direction', 'currentStep'])
            ->orderBy('step_entered_at')
            ->get();
    }

    #[Computed]
    public function selected(): ?Tdr
    {
        return $this->selectedId ? Tdr::visibleTo(auth()->user())->with('activity')->find($this->selectedId) : null;
    }

    public function updatedVue(): void
    {
        unset($this->queue);
        $this->selectedId = $this->queue->first()?->id;
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
    }

    /** Après une décision, on enchaîne sur le dossier suivant de la file. */
    #[On('tdr-decided')]
    public function next(): void
    {
        $ids = $this->queue->pluck('id')->values();
        $position = $ids->search($this->selectedId);
        unset($this->queue);

        $remaining = $this->queue->pluck('id')->values();
        $this->selectedId = $remaining->get($position === false ? 0 : min($position, max(0, $remaining->count() - 1)));
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Validations</flux:heading>
            <flux:text class="mt-1">Examinez les TDR, validez ou renvoyez-les avec un commentaire. Le dossier suivant s'ouvre automatiquement.</flux:text>
        </div>
        @if (auth()->user()->seesAllDirections())
            <flux:radio.group wire:model.live="vue" variant="segmented" size="sm">
                <flux:radio value="mine" label="Ma file" />
                <flux:radio value="all" label="Tous en validation" />
            </flux:radio.group>
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-[340px_1fr]">
        {{-- File --}}
        <div class="space-y-2">
            @forelse ($this->queue as $item)
                <button type="button" wire:key="queue-{{ $item->id }}" wire:click="select({{ $item->id }})"
                    @class([
                        'w-full rounded-lg border px-3 py-2.5 text-start transition',
                        'border-prseb-navy-600 bg-prseb-navy-50 ring-1 ring-prseb-navy-600 dark:bg-prseb-navy-900/50' => $item->id === $selectedId,
                        'border-zinc-200 bg-white hover:border-prseb-navy-300 dark:border-zinc-700 dark:bg-zinc-800' => $item->id !== $selectedId,
                    ])>
                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="font-mono">{{ $item->activity->code }}</span>
                        <span>{{ $item->activity->direction?->code }}</span>
                        <span class="ms-auto">{{ $item->step_entered_at?->diffForHumans(short: true) }}</span>
                    </div>
                    <div class="mt-0.5 line-clamp-2 text-sm font-medium">{{ $item->activity->label }}</div>
                    <div class="mt-1 flex items-center gap-1">
                        <flux:badge size="sm" :color="$item->activity->volet->color()" variant="outline">{{ $item->activity->volet->label() }}</flux:badge>
                        <flux:badge size="sm" color="blue">{{ $item->currentStep?->label }}</flux:badge>
                        @if ($item->step_entered_at?->lt(now()->subDays(5)))
                            <flux:badge size="sm" color="red">En retard</flux:badge>
                        @endif
                    </div>
                </button>
            @empty
                <div class="rounded-lg border border-dashed border-zinc-300 px-4 py-10 text-center dark:border-zinc-600">
                    <flux:icon.check-badge class="mx-auto size-10 text-prseb-green-600" />
                    <flux:text class="mt-2">{{ $vue === 'mine' ? 'Aucun TDR n\'attend votre décision.' : 'Aucun TDR en validation.' }}</flux:text>
                </div>
            @endforelse
        </div>

        {{-- Revue --}}
        <div class="min-w-0">
            @if ($this->selected)
                <div class="mb-3">
                    <a href="{{ route('activities.show', $this->selected->activity) }}" wire:navigate class="text-sm text-prseb-navy-600 hover:underline dark:text-prseb-navy-300">
                        {{ $this->selected->activity->code }} — {{ \Illuminate\Support\Str::limit($this->selected->activity->label, 90) }} ↗
                    </a>
                </div>
                <livewire:tdr.panel :activity-id="$this->selected->activity_id" :review-mode="true" :key="'review-'.$this->selected->id" />
            @elseif ($this->queue->isNotEmpty())
                <flux:text>Sélectionnez un dossier dans la liste.</flux:text>
            @endif
        </div>
    </div>
</div>
