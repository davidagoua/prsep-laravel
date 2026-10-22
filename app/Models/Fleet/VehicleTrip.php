<?php

namespace App\Models\Fleet;

use App\Enums\Fleet\TripType;
use App\Models\Direction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleTrip extends Model
{
    protected $fillable = [
        'vehicle_id', 'type', 'purpose', 'destination', 'manager_name', 'driver_name', 'direction_id',
        'departed_at', 'expected_return_at', 'returned_at', 'km_out', 'km_in', 'return_notes', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TripType::class,
            'departed_at' => 'datetime',
            'expected_return_at' => 'datetime',
            'returned_at' => 'datetime',
            'km_out' => 'integer',
            'km_in' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(Direction::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(VehicleIncident::class, 'trip_id');
    }

    public function scopeOngoing(Builder $query): void
    {
        $query->whereNull('returned_at');
    }

    public function scopeOverdue(Builder $query): void
    {
        $query->whereNull('returned_at')->whereNotNull('expected_return_at')->where('expected_return_at', '<', now());
    }

    public function isOverdue(): bool
    {
        return ! $this->returned_at && $this->expected_return_at?->isPast();
    }

    public function distance(): ?int
    {
        return $this->km_in !== null ? $this->km_in - $this->km_out : null;
    }
}
