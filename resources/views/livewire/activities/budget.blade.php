<?php

use App\Models\Activity;
use App\Models\BudgetLine;
use App\Models\Unit;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $activityId;

    public ?int $editingId = null;
    public string $label = '';
    public string $unit = '';
    public $quantity = 1;
    public $frequency = 1;
    public $duration = 1;
    public $unit_cost = 0;
    public string $economic_nature = '';

    #[Computed]
    public function activity(): Activity
    {
        return Activity::with('exercice')->findOrFail($this->activityId);
    }

    #[Computed]
    public function lines()
    {
        return $this->activity->budgetLines()->where('exercice_id', $this->activity->exercice_id)->with('unit')->get();
    }

    #[Computed]
    public function units()
    {
        return Unit::orderBy('label')->pluck('label');
    }

    /** Montant calculé en direct dans le formulaire. */
    public function preview(): int
    {
        return BudgetLine::computeAmount((float) $this->quantity, (float) $this->frequency ?: 1, (float) $this->duration ?: 1, (float) $this->unit_cost);
    }

    public function create(): void
    {
        $this->authorize('editBudget', $this->activity);
        $this->resetForm();
        Flux::modal('budget-line')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('editBudget', $this->activity);
        $line = $this->lines->firstWhere('id', $id);
        $this->editingId = $line->id;
        $this->label = (string) $line->label;
        $this->unit = (string) $line->unit?->label;
        $this->quantity = $line->quantity;
        $this->frequency = $line->frequency;
        $this->duration = $line->duration;
        $this->unit_cost = $line->unit_cost;
        $this->economic_nature = (string) $line->economic_nature;
        Flux::modal('budget-line')->show();
    }

    public function save(): void
    {
        $this->authorize('editBudget', $this->activity);
        $data = $this->validate([
            'label' => ['required', 'string', 'max:500'],
            'unit' => ['nullable', 'string', 'max:100'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'frequency' => ['required', 'numeric', 'min:0'],
            'duration' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['required', 'integer', 'min:0'],
            'economic_nature' => ['nullable', 'string', 'max:150'],
        ], attributes: ['label' => 'libellé', 'quantity' => 'quantité', 'frequency' => 'fréquence', 'duration' => 'durée', 'unit_cost' => 'coût unitaire']);

        $attributes = [
            'label' => $data['label'],
            'unit_id' => Unit::resolve($data['unit'])?->id,
            'quantity' => $data['quantity'],
            'frequency' => $data['frequency'],
            'duration' => $data['duration'],
            'unit_cost' => (int) $data['unit_cost'],
            'economic_nature' => $data['economic_nature'] ?: null,
        ];

        if ($this->editingId) {
            $this->activity->budgetLines()->findOrFail($this->editingId)->update($attributes);
        } else {
            $this->activity->budgetLines()->create($attributes + [
                'exercice_id' => $this->activity->exercice_id,
                'position' => $this->lines->count(),
            ]);
        }

        Flux::modal('budget-line')->close();
        Flux::toast('Budget mis à jour.', variant: 'success');
        $this->resetForm();
        unset($this->lines);
        $this->dispatch('budget-updated');
    }

    public function delete(int $id): void
    {
        $this->authorize('editBudget', $this->activity);
        $this->activity->budgetLines()->findOrFail($id)->delete();
        unset($this->lines);
        $this->dispatch('budget-updated');
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'label', 'unit', 'quantity', 'frequency', 'duration', 'unit_cost', 'economic_nature');
        $this->resetValidation();
    }
}; ?>

<flux:card class="space-y-4">
    <div class="flex items-center justify-between gap-3">
        <div>
            <flux:heading size="lg">Budget planifié</flux:heading>
            <flux:text size="sm">{{ $this->activity->exercice->label }} · {{ $this->lines->count() }} ligne(s)</flux:text>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xl font-semibold tabular-nums">{{ number_format($this->lines->sum('amount'), 0, ',', ' ') }} <span class="text-sm font-normal text-zinc-500">FCFA</span></span>
            @can('editBudget', $this->activity)
                <flux:button size="sm" icon="plus" wire:click="create">Ligne</flux:button>
            @endcan
        </div>
    </div>

    @if ($this->lines->isEmpty())
        <flux:text>Aucune ligne de coût pour cet exercice.</flux:text>
    @else
        <div class="overflow-x-auto">
            <table class="w-full min-w-[36rem] text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-start text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 text-start font-medium">Ligne de coût</th>
                        <th class="py-2 text-end font-medium">Qté × fréq. × durée</th>
                        <th class="py-2 text-end font-medium">Coût unitaire</th>
                        <th class="py-2 text-end font-medium">Montant</th>
                        <th class="w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                    @foreach ($this->lines as $line)
                        <tr wire:key="line-{{ $line->id }}">
                            <td class="py-2 pe-3">
                                {{ $line->label ?: '—' }}
                                @if ($line->unit || $line->economic_nature)
                                    <div class="text-xs text-zinc-500">{{ collect([$line->unit?->label, $line->economic_nature])->filter()->implode(' · ') }}</div>
                                @endif
                            </td>
                            <td class="py-2 text-end tabular-nums text-zinc-600 dark:text-zinc-300">
                                {{ rtrim(rtrim(number_format($line->quantity, 2, ',', ' '), '0'), ',') }} × {{ rtrim(rtrim(number_format($line->frequency, 2, ',', ' '), '0'), ',') }} × {{ rtrim(rtrim(number_format($line->duration, 2, ',', ' '), '0'), ',') }}
                            </td>
                            <td class="py-2 text-end tabular-nums">{{ number_format($line->unit_cost, 0, ',', ' ') }}</td>
                            <td class="py-2 text-end font-medium tabular-nums">{{ number_format($line->amount, 0, ',', ' ') }}</td>
                            <td class="py-2 text-end">
                                @can('editBudget', $this->activity)
                                    <flux:dropdown align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil-square" wire:click="edit({{ $line->id }})">Modifier</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $line->id }})" wire:confirm="Supprimer cette ligne de coût ?">Supprimer</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <flux:modal name="budget-line" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Modifier la ligne' : 'Nouvelle ligne de coût' }}</flux:heading>
            <flux:input wire:model="label" label="Libellé" required placeholder="Ex. Perdiem des participants" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="unit" label="Unité" list="budget-units" placeholder="Ex. Homme/jour" />
                <flux:input wire:model="economic_nature" label="Nature économique" />
            </div>
            <datalist id="budget-units">
                @foreach ($this->units as $unitLabel)
                    <option value="{{ $unitLabel }}"></option>
                @endforeach
            </datalist>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <flux:input wire:model.live.debounce.300ms="quantity" type="number" step="any" min="0" label="Quantité" />
                <flux:input wire:model.live.debounce.300ms="frequency" type="number" step="any" min="0" label="Fréquence" />
                <flux:input wire:model.live.debounce.300ms="duration" type="number" step="any" min="0" label="Durée" />
                <flux:input wire:model.live.debounce.300ms="unit_cost" type="number" min="0" label="Coût unitaire" />
            </div>
            <div class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-700/40">
                Montant : <span class="font-semibold tabular-nums">{{ number_format($this->preview(), 0, ',', ' ') }} FCFA</span>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:card>
