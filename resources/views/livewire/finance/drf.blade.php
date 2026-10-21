<?php

use App\Enums\DrfStatus;
use App\Finance\FinanceException;
use App\Finance\FinanceService;
use App\Models\Drf;
use App\Models\Payment;
use App\Support\CurrentExercice;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Demandes de remboursement de fonds')] class extends Component {
    #[Url(as: 'drf')]
    public ?int $selectedId = null;

    public string $number = '';
    public string $date = '';
    public string $label = '';

    /** @var list<string> */
    public array $toAttach = [];

    public string $reimbursedOn = '';
    public string $rejectNotes = '';

    public function mount(): void
    {
        $this->selectedId ??= $this->drfs->first()?->id;
    }

    #[Computed]
    public function exercice()
    {
        return app(CurrentExercice::class)->get();
    }

    #[Computed]
    public function drfs()
    {
        return Drf::where('exercice_id', $this->exercice->id)
            ->withSum('payments', 'amount')->withCount('payments')
            ->orderByDesc('date')->orderByDesc('id')->get();
    }

    #[Computed]
    public function selected(): ?Drf
    {
        return $this->selectedId
            ? Drf::where('exercice_id', $this->exercice->id)->with(['payments.activity.direction', 'payments.engagement'])->find($this->selectedId)
            : null;
    }

    #[Computed]
    public function unassigned()
    {
        return Payment::where('exercice_id', $this->exercice->id)->whereNull('drf_id')
            ->with('activity:id,code,label')->orderBy('paid_on')->get();
    }

    public function select(int $id): void
    {
        $this->selectedId = $id;
        unset($this->selected);
    }

    public function newDrf(): void
    {
        $this->number = Drf::nextNumber($this->exercice);
        $this->date = today()->toDateString();
        $this->label = '';
        $this->resetValidation();
        Flux::modal('drf-form')->show();
    }

    public function create(FinanceService $finance): void
    {
        $this->validate([
            'number' => ['required', 'string', 'max:50'],
            'date' => ['required', 'date'],
            'label' => ['nullable', 'string', 'max:255'],
        ], attributes: ['number' => 'numéro']);

        $this->run(function () use ($finance) {
            $drf = $finance->createDrf($this->exercice, auth()->user(), trim($this->number), $this->date, $this->label ?: null);
            $this->selectedId = $drf->id;
        }, 'DRF créée. Ajoutez-y les paiements à faire rembourser.', 'drf-form');
    }

    public function openAttach(): void
    {
        $this->toAttach = [];
        Flux::modal('drf-attach')->show();
    }

    public function attach(FinanceService $finance): void
    {
        $this->run(function () use ($finance) {
            $count = $finance->attachPayments($this->selected, auth()->user(), array_map('intval', $this->toAttach));
            Flux::toast("{$count} paiement(s) ajouté(s).", variant: 'success');
        }, null, 'drf-attach');
    }

    public function detach(int $paymentId, FinanceService $finance): void
    {
        $this->run(fn () => $finance->detachPayment($this->selected, auth()->user(), Payment::findOrFail($paymentId)), 'Paiement retiré de la DRF.');
    }

    public function submit(FinanceService $finance): void
    {
        $this->run(fn () => $finance->submitDrf($this->selected, auth()->user()), 'DRF soumise : ses paiements sont désormais figés.');
    }

    public function openReimburse(): void
    {
        $this->reimbursedOn = today()->toDateString();
        Flux::modal('drf-reimburse')->show();
    }

    public function reimburse(FinanceService $finance): void
    {
        $this->validate(['reimbursedOn' => ['required', 'date']]);
        $this->run(fn () => $finance->reimburseDrf($this->selected, auth()->user(), $this->reimbursedOn), 'DRF marquée comme remboursée.', 'drf-reimburse');
    }

    public function reject(FinanceService $finance): void
    {
        $this->validate(['rejectNotes' => ['required', 'string', 'min:5']], ['rejectNotes.required' => 'Indiquez le motif du rejet.', 'rejectNotes.min' => 'Indiquez le motif du rejet.']);
        $this->run(fn () => $finance->rejectDrf($this->selected, auth()->user(), $this->rejectNotes), 'DRF rejetée : elle peut être corrigée puis resoumise.', 'drf-reject');
        $this->rejectNotes = '';
    }

    public function delete(FinanceService $finance): void
    {
        $this->run(function () use ($finance) {
            $finance->deleteDrf($this->selected, auth()->user());
            $this->selectedId = null;
        }, 'DRF supprimée ; ses paiements sont de nouveau disponibles.');
    }

    private function run(callable $action, ?string $success, ?string $modal = null): void
    {
        try {
            $action();
        } catch (FinanceException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        } catch (AuthorizationException) {
            Flux::toast('Vous n\'avez pas le droit d\'effectuer cette opération.', variant: 'danger');

            return;
        }

        if ($modal) {
            Flux::modal($modal)->close();
        }
        if ($success) {
            Flux::toast($success, variant: 'success');
        }
        unset($this->drfs, $this->selected, $this->unassigned);
        $this->selectedId ??= $this->drfs->first()?->id;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item :href="route('finance.index')" wire:navigate>Suivi financier</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>DRF</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1">Demandes de remboursement de fonds</flux:heading>
            <flux:text class="mt-1">Regroupez les paiements de l'{{ $this->exercice->label }} à faire rembourser, puis suivez la demande jusqu'au remboursement.</flux:text>
        </div>
        @can('manage-finance')
            <flux:button variant="primary" icon="plus" wire:click="newDrf">Nouvelle DRF</flux:button>
        @endcan
    </div>

    @if ($this->unassigned->isNotEmpty())
        <flux:callout icon="information-circle" color="blue">
            <flux:callout.text>
                {{ $this->unassigned->count() }} paiement(s) pour {{ number_format($this->unassigned->sum('amount'), 0, ',', ' ') }} FCFA ne sont dans aucune DRF.
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid gap-6 lg:grid-cols-[320px_1fr]">
        <div class="space-y-2">
            @forelse ($this->drfs as $drf)
                <button type="button" wire:key="drf-{{ $drf->id }}" wire:click="select({{ $drf->id }})" @class([
                    'w-full rounded-lg border px-3 py-2.5 text-start transition',
                    'border-prseb-navy-600 bg-prseb-navy-50 ring-1 ring-prseb-navy-600 dark:bg-prseb-navy-900/50' => $drf->id === $selectedId,
                    'border-zinc-200 bg-white hover:border-prseb-navy-300 dark:border-zinc-700 dark:bg-zinc-800' => $drf->id !== $selectedId,
                ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold">{{ $drf->number }}</span>
                        <flux:badge size="sm" :color="$drf->status->color()">{{ $drf->status->label() }}</flux:badge>
                    </div>
                    <div class="mt-1 flex justify-between text-sm text-zinc-500 dark:text-zinc-400">
                        <span>{{ $drf->date->format('d/m/Y') }} · {{ $drf->payments_count }} paiement(s)</span>
                        <span class="font-medium tabular-nums text-zinc-800 dark:text-zinc-100">{{ number_format((int) $drf->payments_sum_amount, 0, ',', ' ') }}</span>
                    </div>
                </button>
            @empty
                <div class="rounded-lg border border-dashed border-zinc-300 px-4 py-10 text-center dark:border-zinc-600">
                    <flux:text>Aucune DRF pour cet exercice.</flux:text>
                </div>
            @endforelse
        </div>

        <div class="min-w-0">
            @if ($drf = $this->selected)
                <flux:card class="space-y-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg">{{ $drf->number }}</flux:heading>
                                <flux:badge :color="$drf->status->color()">{{ $drf->status->label() }}</flux:badge>
                            </div>
                            <flux:text size="sm">
                                {{ $drf->label ? $drf->label.' · ' : '' }}du {{ $drf->date->translatedFormat('j F Y') }}
                                @if ($drf->submitted_on) · soumise le {{ $drf->submitted_on->format('d/m/Y') }} @endif
                                @if ($drf->reimbursed_on) · remboursée le {{ $drf->reimbursed_on->format('d/m/Y') }} @endif
                            </flux:text>
                        </div>
                        <div class="text-end">
                            <div class="text-2xl font-semibold tabular-nums">{{ number_format($drf->payments->sum('amount'), 0, ',', ' ') }}</div>
                            <flux:text size="sm">FCFA · {{ $drf->payments->count() }} paiement(s)</flux:text>
                        </div>
                    </div>

                    @if ($drf->status === DrfStatus::Rejetee && $drf->notes)
                        <flux:callout color="red" icon="x-circle" heading="Rejetée par le bailleur">
                            <flux:callout.text>« {{ $drf->notes }} »</flux:callout.text>
                        </flux:callout>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="arrow-down-tray" :href="route('finance.drf.export', $drf)">Exporter</flux:button>
                        @can('manage-finance')
                            @if ($drf->isEditable())
                                <flux:button size="sm" icon="plus" wire:click="openAttach" :disabled="$this->unassigned->isEmpty()">Ajouter des paiements</flux:button>
                                <flux:button size="sm" variant="primary" icon="paper-airplane" wire:click="submit"
                                    wire:confirm="Soumettre la DRF {{ $drf->number }} ? Ses paiements ne pourront plus être modifiés.">Soumettre</flux:button>
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete" wire:confirm="Supprimer la DRF {{ $drf->number }} ?">Supprimer</flux:button>
                            @elseif ($drf->status === DrfStatus::Soumise)
                                <flux:button size="sm" variant="primary" icon="check" wire:click="openReimburse">Marquer remboursée</flux:button>
                                <flux:modal.trigger name="drf-reject">
                                    <flux:button size="sm" icon="x-mark">Rejet du bailleur</flux:button>
                                </flux:modal.trigger>
                            @endif
                        @endcan
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700">
                                    <th class="py-2 text-start font-medium">Activité</th>
                                    <th class="py-2 text-start font-medium">Date</th>
                                    <th class="py-2 text-start font-medium">Référence</th>
                                    <th class="py-2 text-end font-medium">Montant</th>
                                    <th class="w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                @forelse ($drf->payments as $payment)
                                    <tr wire:key="dp-{{ $payment->id }}">
                                        <td class="max-w-md py-2 pe-3">
                                            <a href="{{ route('activities.show', $payment->activity) }}" wire:navigate class="hover:underline">
                                                <span class="font-mono text-xs text-zinc-500">{{ $payment->activity->code }}</span>
                                                <span class="line-clamp-1">{{ $payment->activity->label }}</span>
                                            </a>
                                            <div class="text-xs text-zinc-500">{{ $payment->activity->direction?->code }}{{ $payment->label ? ' · '.$payment->label : '' }}</div>
                                        </td>
                                        <td class="py-2 pe-3 whitespace-nowrap">{{ $payment->paid_on->format('d/m/Y') }}</td>
                                        <td class="py-2 pe-3 whitespace-nowrap">{{ $payment->reference ?? '—' }}</td>
                                        <td class="py-2 text-end font-medium whitespace-nowrap tabular-nums">{{ number_format($payment->amount, 0, ',', ' ') }}</td>
                                        <td class="py-2 text-end">
                                            @if ($drf->isEditable())
                                                @can('manage-finance')
                                                    <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="detach({{ $payment->id }})" aria-label="Retirer de la DRF" />
                                                @endcan
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="py-6 text-center text-zinc-500">Aucun paiement dans cette DRF.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </flux:card>
            @endif
        </div>
    </div>

    <flux:modal name="drf-form" class="w-full max-w-md">
        <form wire:submit="create" class="space-y-4">
            <flux:heading size="lg">Nouvelle DRF</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="number" label="Numéro" required />
                <flux:input wire:model="date" type="date" label="Date" required />
            </div>
            <flux:input wire:model="label" label="Objet" placeholder="Ex. DRF n°3 — dépenses du 1er trimestre" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Créer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="drf-attach" class="w-full max-w-2xl">
        <form wire:submit="attach" class="space-y-4">
            <flux:heading size="lg">Ajouter des paiements</flux:heading>
            <flux:text size="sm">Paiements de l'exercice qui ne sont dans aucune DRF.</flux:text>
            <div class="max-h-96 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <flux:checkbox.group wire:model="toAttach">
                    @foreach ($this->unassigned as $payment)
                        <label class="flex cursor-pointer items-center gap-3 border-b border-zinc-100 px-3 py-2 text-sm last:border-0 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40" wire:key="ua-{{ $payment->id }}">
                            <flux:checkbox :value="(string) $payment->id" />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $payment->activity->code }} {{ $payment->activity->label }}</span>
                                <span class="text-xs text-zinc-500">{{ $payment->paid_on->format('d/m/Y') }}{{ $payment->reference ? ' · '.$payment->reference : '' }}</span>
                            </span>
                            <span class="font-medium tabular-nums">{{ number_format($payment->amount, 0, ',', ' ') }}</span>
                        </label>
                    @endforeach
                </flux:checkbox.group>
            </div>
            <div class="flex items-center justify-between gap-2">
                <flux:text size="sm">{{ count($toAttach) }} sélectionné(s)</flux:text>
                <div class="flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" :disabled="! $toAttach">Ajouter</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="drf-reimburse" class="w-full max-w-sm">
        <form wire:submit="reimburse" class="space-y-4">
            <flux:heading size="lg">Remboursement reçu</flux:heading>
            <flux:input wire:model="reimbursedOn" type="date" label="Date du remboursement" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="drf-reject" class="w-full max-w-md">
        <form wire:submit="reject" class="space-y-4">
            <flux:heading size="lg">Rejet du bailleur</flux:heading>
            <flux:textarea wire:model="rejectNotes" label="Motif" rows="3" required />
            <flux:text size="sm">La DRF redevient modifiable pour être corrigée et resoumise.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">Enregistrer le rejet</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
