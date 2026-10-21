<?php

namespace App\Finance;

use App\Enums\DrfStatus;
use App\Models\Activity;
use App\Models\Drf;
use App\Models\Engagement;
use App\Models\Exercice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Règles de l'exécution financière :
 * - on ne paie pas plus que ce qui a été engagé (globalement pour l'activité, et par engagement) ;
 * - un engagement déjà payé ne se supprime pas ;
 * - les paiements d'une DRF soumise ou remboursée sont figés.
 */
class FinanceService
{
    /** @param  array{amount: int, engaged_on: string, reference?: ?string, supplier?: ?string, label?: ?string}  $data */
    public function saveEngagement(Activity $activity, User $user, array $data, ?Engagement $engagement = null): Engagement
    {
        Gate::forUser($user)->authorize('manage-finance');
        $this->ensurePositive($data['amount']);

        if ($engagement) {
            $paid = (int) $engagement->payments()->sum('amount');
            if ($data['amount'] < $paid) {
                throw new FinanceException('Le montant engagé ne peut pas être inférieur aux paiements déjà effectués ('.$this->fcfa($paid).').');
            }
            $engagement->update($data);

            return $engagement;
        }

        return $activity->engagements()->create($data + [
            'exercice_id' => $activity->exercice_id,
            'user_id' => $user->id,
        ]);
    }

    public function deleteEngagement(Engagement $engagement, User $user): void
    {
        Gate::forUser($user)->authorize('manage-finance');

        if ($engagement->payments()->exists()) {
            throw new FinanceException('Cet engagement a des paiements : supprimez-les d\'abord.');
        }

        $engagement->delete();
    }

    /** @param  array{amount: int, paid_on: string, engagement_id?: ?int, reference?: ?string, label?: ?string}  $data */
    public function savePayment(Activity $activity, User $user, array $data, ?Payment $payment = null): Payment
    {
        Gate::forUser($user)->authorize('manage-finance');
        $this->ensurePositive($data['amount']);

        if ($payment?->isLocked()) {
            throw new FinanceException('Ce paiement fait partie d\'une DRF soumise : il ne peut plus être modifié.');
        }

        return DB::transaction(function () use ($activity, $user, $data, $payment) {
            $engaged = (int) $activity->engagements()->where('exercice_id', $activity->exercice_id)->sum('amount');
            $paid = (int) $activity->payments()->where('exercice_id', $activity->exercice_id)
                ->when($payment, fn ($q) => $q->whereKeyNot($payment->id))->sum('amount');

            if ($paid + $data['amount'] > $engaged) {
                throw new FinanceException('Paiement supérieur au reste à payer de l\'activité ('.$this->fcfa(max(0, $engaged - $paid)).'). Enregistrez d\'abord l\'engagement correspondant.');
            }

            if ($data['engagement_id'] ?? null) {
                $engagement = $activity->engagements()->findOrFail($data['engagement_id']);
                if ($data['amount'] > $engagement->remaining($payment?->id)) {
                    throw new FinanceException('Paiement supérieur au reste à payer de cet engagement ('.$this->fcfa($engagement->remaining($payment?->id)).').');
                }
            }

            if ($payment) {
                $payment->update($data);

                return $payment;
            }

            return $activity->payments()->create($data + [
                'exercice_id' => $activity->exercice_id,
                'user_id' => $user->id,
            ]);
        });
    }

    public function deletePayment(Payment $payment, User $user): void
    {
        Gate::forUser($user)->authorize('manage-finance');

        if ($payment->isLocked()) {
            throw new FinanceException('Ce paiement fait partie d\'une DRF soumise : il ne peut plus être supprimé.');
        }

        $payment->delete();
    }

    public function createDrf(Exercice $exercice, User $user, string $number, string $date, ?string $label): Drf
    {
        Gate::forUser($user)->authorize('manage-finance');

        if (Drf::where('exercice_id', $exercice->id)->where('number', $number)->exists()) {
            throw new FinanceException("Le numéro {$number} existe déjà pour cet exercice.");
        }

        return Drf::create([
            'exercice_id' => $exercice->id,
            'number' => $number,
            'date' => $date,
            'label' => $label,
            'user_id' => $user->id,
        ]);
    }

    /** @param  list<int>  $paymentIds */
    public function attachPayments(Drf $drf, User $user, array $paymentIds): int
    {
        Gate::forUser($user)->authorize('manage-finance');
        $this->ensureEditable($drf);

        return Payment::whereIn('id', $paymentIds)
            ->where('exercice_id', $drf->exercice_id)
            ->whereNull('drf_id')
            ->update(['drf_id' => $drf->id]);
    }

    public function detachPayment(Drf $drf, User $user, Payment $payment): void
    {
        Gate::forUser($user)->authorize('manage-finance');
        $this->ensureEditable($drf);

        Payment::whereKey($payment->id)->where('drf_id', $drf->id)->update(['drf_id' => null]);
    }

    public function submitDrf(Drf $drf, User $user): Drf
    {
        Gate::forUser($user)->authorize('manage-finance');
        $this->ensureEditable($drf);

        if (! $drf->payments()->exists()) {
            throw new FinanceException('Ajoutez au moins un paiement avant de soumettre la DRF.');
        }

        $drf->update(['status' => DrfStatus::Soumise, 'submitted_on' => now()]);

        return $drf;
    }

    public function reimburseDrf(Drf $drf, User $user, string $date): Drf
    {
        Gate::forUser($user)->authorize('manage-finance');

        if ($drf->status !== DrfStatus::Soumise) {
            throw new FinanceException('Seule une DRF soumise peut être marquée comme remboursée.');
        }

        $drf->update(['status' => DrfStatus::Remboursee, 'reimbursed_on' => $date]);

        return $drf;
    }

    /** Rejet par le bailleur : la DRF redevient modifiable pour être corrigée et resoumise. */
    public function rejectDrf(Drf $drf, User $user, string $notes): Drf
    {
        Gate::forUser($user)->authorize('manage-finance');

        if ($drf->status !== DrfStatus::Soumise) {
            throw new FinanceException('Seule une DRF soumise peut être rejetée.');
        }

        $drf->update(['status' => DrfStatus::Rejetee, 'notes' => trim($notes)]);

        return $drf;
    }

    public function deleteDrf(Drf $drf, User $user): void
    {
        Gate::forUser($user)->authorize('manage-finance');
        $this->ensureEditable($drf);

        DB::transaction(function () use ($drf) {
            $drf->payments()->update(['drf_id' => null]);
            $drf->delete();
        });
    }

    private function ensureEditable(Drf $drf): void
    {
        if (! $drf->isEditable()) {
            throw new FinanceException('Cette DRF est '.mb_strtolower($drf->status->label()).' : elle ne peut plus être modifiée.');
        }
    }

    private function ensurePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new FinanceException('Le montant doit être supérieur à zéro.');
        }
    }

    private function fcfa(int $amount): string
    {
        return number_format($amount, 0, ',', ' ').' FCFA';
    }
}
