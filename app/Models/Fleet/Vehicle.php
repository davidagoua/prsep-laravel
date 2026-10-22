<?php

namespace App\Models\Fleet;

use App\Enums\Fleet\VehicleStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    /** Seuils d'alerte avant le prochain entretien. */
    public const ALERT_KM = 500;

    public const ALERT_DAYS = 15;

    protected $fillable = ['plate', 'brand', 'model', 'year', 'chassis', 'status', 'mileage', 'next_service_km', 'next_service_on', 'notes'];

    protected $attributes = ['status' => 'disponible', 'mileage' => 0];

    protected function casts(): array
    {
        return [
            'status' => VehicleStatus::class,
            'mileage' => 'integer',
            'next_service_km' => 'integer',
            'next_service_on' => 'date',
        ];
    }

    public function trips(): HasMany
    {
        return $this->hasMany(VehicleTrip::class)->latest('departed_at');
    }

    public function currentTrip(): HasOne
    {
        return $this->hasOne(VehicleTrip::class)->whereNull('returned_at')->latestOfMany('departed_at');
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class)->latest('done_on')->latest('id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(VehicleIncident::class)->latest('occurred_on')->latest('id');
    }

    public function name(): string
    {
        return trim("{$this->brand} {$this->model}").($this->year ? " ({$this->year})" : '');
    }

    /** Entretien dû bientôt (kilométrage ou date), hors véhicules hors service. */
    public function scopeServiceDue(Builder $query): void
    {
        $query->where('status', '!=', VehicleStatus::HorsService)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNotNull('next_service_km')->whereRaw('next_service_km - mileage <= ?', [self::ALERT_KM]))
                ->orWhere(fn ($q) => $q->whereNotNull('next_service_on')->whereDate('next_service_on', '<=', today()->addDays(self::ALERT_DAYS))));
    }

    /** Message d'alerte d'entretien, ou null. */
    public function serviceAlert(): ?string
    {
        if ($this->status === VehicleStatus::HorsService) {
            return null;
        }

        $alerts = [];
        if ($this->next_service_km !== null && $this->next_service_km - $this->mileage <= self::ALERT_KM) {
            $remaining = $this->next_service_km - $this->mileage;
            $alerts[] = $remaining <= 0 ? 'kilométrage d\'entretien dépassé de '.number_format(-$remaining, 0, ',', ' ').' km' : 'entretien dans '.number_format($remaining, 0, ',', ' ').' km';
        }
        if ($this->next_service_on && $this->next_service_on->lte(today()->addDays(self::ALERT_DAYS))) {
            $alerts[] = $this->next_service_on->isPast() ? 'date d\'entretien dépassée ('.$this->next_service_on->format('d/m/Y').')' : 'entretien prévu le '.$this->next_service_on->format('d/m/Y');
        }

        return $alerts ? ucfirst(implode(', ', $alerts)) : null;
    }
}
