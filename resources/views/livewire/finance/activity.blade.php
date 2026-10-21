<?php

use App\Finance\FinanceException;
use App\Finance\FinanceService;
use App\Models\Activity;
use App\Models\Engagement;
use App\Models\Payment;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component {
    #[Locked]
    public int $activityId;

    public ?int $engagementId = null;
    public $e_amount = '';
    public string $e_date = '';
    public string $e_reference = '';
    public string $e_supplier = '';
    public string $e_label = '';

    public ?int $paymentId = null;
    public $p_amount = '';
    public string $p_date = '';
    public ?string $p_engagement = null;
    public string $p_reference = '';
    public string $p_label = '';

    #[Computed]
    public function activity(): Activity
    {
        return Activity::withFinancials(Activity::whereKey($this->activityId)->value('exercice_id'))->findOrFail($this->activityId);
    }

    #[Computed]
    public function engagements()
    {
        return $this->activity->engagements()->where('exercice_id', $this->activity->exercice_id)->withSum('payments', 'amount')->get();
    }

    #[Computed]
    public function payments()
    {
        return $this->activity->payments()->where('exercice_id', $this->activity->exercice_id)->with(['engagement', 'drf'])->get();
    }

    #[Computed]
    public function figures(): array
    {
        $a = $this->activity;
        $planned = (int) $a->planned;
        $engaged = (int) $a->engaged;
        $paid = (int) $a->paid;

        return compact('planned', 'engaged', 'paid') + [
            'remaining' => $engaged - $paid,
            'rate' => $planned > 0 ? round($paid / $planned * 100) : null,
            'over' => $engaged > $planned && $planned > 0,
        ];
    }

    #[On('budget-updated')]
    public function refreshFigures(): void
    {
        unset($this->activity, $this->figures);
    }

    public function newEngagement(): void
    {
        $this->reset('engagementId', 'e_amount', 'e_reference', 'e_supplier', 'e_label');
        $this->e_date = today()->toDateString();
        $this->resetValidation();
        Flux::modal('finance-engagement')->show();
    }

    public function editEngagement(int $id): void
    {
        $e = $this->engagements->firstWhere('id', $id);
        [$this->engagementId, $this->e_amount, $this->e_date, $this->e_reference, $this->e_supplier, $this->e_label]
            = [$e->id, $e->amount, $e->engaged_on->toDateString(), (string) $e->reference, (string) $e->supplier, (string) $e->label];
        $this->resetValidation();
        Flux::modal('finance-engagement')->show();
    }

    public function saveEngagement(FinanceService $finance): void
    {
        $data = $this->validate([
            'e_amount' => ['required', 'integer', 'min:1'],
            'e_date' => ['required', 'date'],
            'e_reference' => ['nullable', 'string', 'max:100'],
            'e_supplier' => ['nullable', 'string', 'max:255'],
            'e_label' => ['nullable', 'string', 'max:255'],
        ], attributes: ['e_amount' => 'montant', 'e_date' => 'date']);

        $this->run(fn () => $finance->saveEngagement($this->activity, auth()->user(), [
            'amount' => (int) $data['e_amount'],
            'engaged_on' => $data['e_date'],
            'reference' => $data['e_reference'] ?: null,
            'supplier' => $data['e_supplier'] ?: null,
            'label' => $data['e_label'] ?: null,
        ], $this->engagementId ? Engagement::find($this->engagementId) : null), 'Engagement enregistré.', 'finance-engagement');
    }

    public function deleteEngagement(int $id, FinanceService $finance): void
    {
        $this->run(fn () => $finance->deleteEngagement($this->activity->engagements()->findOrFail($id), auth()->user()), 'Engagement supprimé.');
    }

    public function newPayment(): void
    {
        $this->reset('paymentId', 'p_amount', 'p_engagement', 'p_reference', 'p_label');
        $this->p_date = today()->toDateString();
        // Un seul engagement non soldé : proposé par défaut.
        $open = $this->engagements->filter(fn ($e) => $e->amount > (int) $e->payments_sum_amount);
        $this->p_engagement = $open->count() === 1 ? (string) $open->first()->id : null;
        $this->resetValidation();
        Flux::modal('finance-payment')->show();
    }

    public function editPayment(int $id): void
    {
        $p = $this->payments->firstWhere('id', $id);
        [$this->paymentId, $this->p_amount, $this->p_date, $this->p_engagement, $this->p_reference, $this->p_label]
            = [$p->id, $p->amount, $p->paid_on->toDateString(), $p->engagement_id ? (string) $p->engagement_id : null, (string) $p->reference, (string) $p->label];
        $this->resetValidation();
        Flux::modal('finance-payment')->show();
    }

    public function savePayment(FinanceService $finance): void
    {
        $data = $this->validate([
            'p_amount' => ['required', 'integer', 'min:1'],
            'p_date' => ['required', 'date'],
            'p_engagement' => ['nullable', 'integer'],
            'p_reference' => ['nullable', 'string', 'max:100'],
            'p_label' => ['nullable', 'string', 'max:255'],
        ], attributes: ['p_amount' => 'montant', 'p_date' => 'date']);

        $this->run(fn () => $finance->savePayment($this->activity, auth()->user(), [
            'amount' => (int) $data['p_amount'],
            'paid_on' => $data['p_date'],
            'engagement_id' => $data['p_engagement'] ? (int) $data['p_engagement'] : null,
            'reference' => $data['p_reference'] ?: null,
            'label' => $data['p_label'] ?: null,
        ], $this->paymentId ? Payment::find($this->paymentId) : null), 'Paiement enregistré.', 'finance-payment');
    }

    public function deletePayment(int $id, FinanceService $finance): void
    {
        $this->run(fn () => $finance->deletePayment($this->activity->payments()->findOrFail($id), auth()->user()), 'Paiement supprimé.');
    }

    private function run(callable $action, string $success, ?string $modal = null): void
    {
        try {
            $action();
        } catch (FinanceException $e) {
            $this->addError($modal === 'finance-payment' ? 'p_amount' : ($modal ? 'e_amount' : 'general'), $e->getMessage());
            if (! $modal) {
                Flux::toast($e->getMessage(), variant: 'danger');
            }

            return;
        } catch (AuthorizationException) {
            Flux::toast('Vous n\'avez pas le droit d\'effectuer cette opération.', variant: 'danger');

            return;
        }

        if ($modal) {
            Flux::modal($modal)->close();
        }
        unset($this->activity, $this->engagements, $this->payments, $this->figures);
        Flux::toast($success, variant: 'success');
        $this->dispatch('finance-updated', activityId: $this->activityId);
    }
}; ?>

<flux:card class="space-y-5">
    @php($f = $this->figures)
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="lg">Exécution financière</flux:heading>
            <flux:text size="sm">Engagements et paiements de l'exercice.</flux:text>
        </div>
        @can('manage-finance')
            <div class="flex gap-2">
                <flux:button size="sm" icon="plus" wire:click="newEngagement">Engagement</flux:button>
                <flux:button size="sm" icon="plus" wire:click="newPayment" :disabled="$f['remaining'] <= 0">Paiement</flux:button>
            </div>
        @endcan
    </div>

    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([['Planifié', $f['planned'], ''], ['Engagé', $f['engaged'], $f['over'] ? 'text-prseb-orange-600' : ''], ['Payé', $f['paid'], 'text-prseb-green-600'], ['Reste à payer', $f['remaining'], '']] as [$label, $value, $class])
            <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-700/40">
                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                <dd class="font-semibold tabular-nums {{ $class }}">{{ number_format($value, 0, ',', ' ') }}</dd>
            </div>
        @endforeach
    </dl>
    @if ($f['rate'] !== null)
        <div>
            <div class="flex justify-between text-xs text-zinc-500"><span>Taux de décaissement (payé / planifié)</span><span>{{ $f['rate'] }} %</span></div>
            <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                <div class="h-full rounded-full bg-prseb-green-600" style="width: {{ min(100, $f['rate']) }}%"></div>
            </div>
        </div>
    @endif
    @if ($f['over'])
        <flux:callout color="orange" icon="exclamation-triangle" heading="Les engagements dépassent le budget planifié de {{ number_format($f['engaged'] - $f['planned'], 0, ',', ' ') }} FCFA." />
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        <div>
            <flux:subheading class="mb-2">Engagements ({{ $this->engagements->count() }})</flux:subheading>
            <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->engagements as $e)
                    <li class="flex items-center gap-3 px-3 py-2" wire:key="eng-{{ $e->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $e->label ?: ($e->supplier ?: 'Engagement') }}</div>
                            <div class="text-xs text-zinc-500">{{ $e->engaged_on->format('d/m/Y') }}{{ $e->reference ? ' · '.$e->reference : '' }}{{ $e->supplier && $e->label ? ' · '.$e->supplier : '' }}</div>
                        </div>
                        <div class="text-end">
                            <div class="font-medium tabular-nums">{{ number_format($e->amount, 0, ',', ' ') }}</div>
                            <div class="text-xs text-zinc-500 tabular-nums">payé {{ number_format((int) $e->payments_sum_amount, 0, ',', ' ') }}</div>
                        </div>
                        @can('manage-finance')
                            <flux:dropdown align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions" />
                                <flux:menu>
                                    <flux:menu.item icon="pencil-square" wire:click="editEngagement({{ $e->id }})">Modifier</flux:menu.item>
                                    <flux:menu.item icon="trash" variant="danger" wire:click="deleteEngagement({{ $e->id }})" wire:confirm="Supprimer cet engagement ?">Supprimer</flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @endcan
                    </li>
                @empty
                    <li class="px-3 py-4 text-center text-zinc-500">Aucun engagement.</li>
                @endforelse
            </ul>
        </div>

        <div>
            <flux:subheading class="mb-2">Paiements ({{ $this->payments->count() }})</flux:subheading>
            <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($this->payments as $p)
                    <li class="flex items-center gap-3 px-3 py-2" wire:key="pay-{{ $p->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">{{ $p->label ?: ($p->engagement?->label ?: 'Paiement') }}</div>
                            <div class="flex flex-wrap items-center gap-1 text-xs text-zinc-500">
                                {{ $p->paid_on->format('d/m/Y') }}{{ $p->reference ? ' · '.$p->reference : '' }}
                                @if ($p->drf)
                                    <flux:badge size="sm" :color="$p->drf->status->color()">{{ $p->drf->number }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc" variant="outline">Hors DRF</flux:badge>
                                @endif
                            </div>
                        </div>
                        <div class="font-medium tabular-nums">{{ number_format($p->amount, 0, ',', ' ') }}</div>
                        @can('manage-finance')
                            @unless ($p->isLocked())
                                <flux:dropdown align="end">
                                    <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" aria-label="Actions" />
                                    <flux:menu>
                                        <flux:menu.item icon="pencil-square" wire:click="editPayment({{ $p->id }})">Modifier</flux:menu.item>
                                        <flux:menu.item icon="trash" variant="danger" wire:click="deletePayment({{ $p->id }})" wire:confirm="Supprimer ce paiement ?">Supprimer</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            @else
                                <flux:icon.lock-closed variant="micro" class="text-zinc-400" title="Inclus dans une DRF soumise" />
                            @endunless
                        @endcan
                    </li>
                @empty
                    <li class="px-3 py-4 text-center text-zinc-500">Aucun paiement.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <flux:modal name="finance-engagement" class="w-full max-w-md">
        <form wire:submit="saveEngagement" class="space-y-4">
            <flux:heading size="lg">{{ $engagementId ? 'Modifier l\'engagement' : 'Nouvel engagement' }}</flux:heading>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="e_amount" type="number" min="1" label="Montant (FCFA)" required />
                <flux:input wire:model="e_date" type="date" label="Date d'engagement" required />
            </div>
            <flux:input wire:model="e_label" label="Objet" placeholder="Ex. Contrat d'hôtellerie de l'atelier" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="e_reference" label="Référence" placeholder="N° de bon de commande, contrat…" />
                <flux:input wire:model="e_supplier" label="Fournisseur / prestataire" />
            </div>
            <flux:text size="sm">Budget planifié : {{ number_format($f['planned'], 0, ',', ' ') }} FCFA · déjà engagé : {{ number_format($f['engaged'], 0, ',', ' ') }} FCFA.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="finance-payment" class="w-full max-w-md">
        <form wire:submit="savePayment" class="space-y-4">
            <flux:heading size="lg">{{ $paymentId ? 'Modifier le paiement' : 'Nouveau paiement' }}</flux:heading>
            <flux:select wire:model="p_engagement" label="Engagement" placeholder="Sans engagement précis">
                <flux:select.option value="">Sans engagement précis</flux:select.option>
                @foreach ($this->engagements as $e)
                    <flux:select.option :value="$e->id">
                        {{ $e->label ?: ($e->supplier ?: 'Engagement du '.$e->engaged_on->format('d/m/Y')) }} — reste {{ number_format($e->amount - (int) $e->payments_sum_amount, 0, ',', ' ') }}
                    </flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="p_amount" type="number" min="1" label="Montant (FCFA)" required />
                <flux:input wire:model="p_date" type="date" label="Date de paiement" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="p_reference" label="Référence" placeholder="N° de mandat, de chèque…" />
                <flux:input wire:model="p_label" label="Objet" />
            </div>
            <flux:text size="sm">Reste à payer sur l'activité : {{ number_format($f['remaining'], 0, ',', ' ') }} FCFA.</flux:text>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Annuler</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Enregistrer</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:card>
