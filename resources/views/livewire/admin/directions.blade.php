<?php

use App\Models\Direction;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Directions')] class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    public ?int $editingId = null;
    public string $code = '';
    public string $name = '';
    public ?string $parent_id = null;
    public bool $is_active = true;

    #[Computed]
    public function directions()
    {
        return Direction::query()
            ->with('parent')
            ->withCount(['users' => fn ($q) => $q->where('is_active', true)])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('code', "%{$this->search}%")
                ->orWhereLike('name', "%{$this->search}%")))
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function parentOptions()
    {
        return Direction::active()->whereKeyNot($this->editingId)->orderBy('code')->get(['id', 'code']);
    }

    public function create(): void
    {
        $this->resetForm();
        Flux::modal('direction-form')->show();
    }

    public function edit(Direction $direction): void
    {
        $this->resetForm();
        $this->editingId = $direction->id;
        $this->code = $direction->code;
        // Tant que l'intitulé n'est que le sigle, on présente un champ vide à compléter.
        $this->name = $direction->name === $direction->code ? '' : $direction->name;
        $this->parent_id = $direction->parent_id ? (string) $direction->parent_id : null;
        $this->is_active = $direction->is_active;

        Flux::modal('direction-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('directions', 'code')->ignore($this->editingId)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:directions,id', Rule::notIn(array_filter([$this->editingId]))],
            'is_active' => ['boolean'],
        ], attributes: ['code' => 'sigle', 'name' => 'intitulé', 'parent_id' => 'direction de rattachement']);

        $validated['code'] = strtoupper(trim($validated['code']));

        Direction::updateOrCreate(['id' => $this->editingId], $validated);

        Flux::modal('direction-form')->close();
        Flux::toast($this->editingId ? 'Direction mise à jour.' : 'Direction créée.', variant: 'success');
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'code', 'name', 'parent_id', 'is_active');
        $this->resetValidation();
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Directions</flux:heading>
            <flux:text class="mt-1">Structures responsables des activités. Les points focaux et directeurs centraux ne voient que leur direction.</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">Nouvelle direction</flux:button>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Rechercher un sigle ou un intitulé" class="max-w-xs" clearable />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Sigle</flux:table.column>
            <flux:table.column>Intitulé</flux:table.column>
            <flux:table.column>Rattachement</flux:table.column>
            <flux:table.column align="end">Comptes actifs</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->directions as $direction)
                <flux:table.row :key="$direction->id" @class(['opacity-60' => ! $direction->is_active])>
                    <flux:table.cell class="font-medium text-zinc-800 dark:text-white">{{ $direction->code }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($direction->name === $direction->code)
                            <flux:badge size="sm" color="amber">Intitulé à compléter</flux:badge>
                        @else
                            {{ $direction->name }}
                        @endif
                        @unless ($direction->is_active)
                            <flux:badge size="sm" color="red" class="ms-1">Inactive</flux:badge>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell>{{ $direction->parent?->code ?? '—' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ $direction->users_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $direction->id }})" aria-label="Modifier {{ $direction->code }}" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="py-8 text-center">Aucune direction.</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="direction-form" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Modifier la direction' : 'Nouvelle direction' }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="code" label="Sigle" required class="sm:col-span-1" />
                <div class="sm:col-span-2">
                    <flux:input wire:model="name" label="Intitulé complet" required placeholder="Direction des…" />
                </div>
            </div>

            <flux:select wire:model="parent_id" label="Direction de rattachement" description="Facultatif.">
                <flux:select.option value="">Aucune</flux:select.option>
                @foreach ($this->parentOptions as $option)
                    <flux:select.option :value="$option->id">{{ $option->code }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($editingId)
                <flux:switch wire:model="is_active" label="Direction active" description="Une direction inactive n'est plus proposée dans les formulaires." />
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Annuler</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
