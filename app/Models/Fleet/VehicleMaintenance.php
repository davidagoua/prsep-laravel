<?php

namespace App\Models\Fleet;

use App\Enums\Fleet\MaintenanceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenance extends Model
{
    protected $fillable = ['vehicle_id', 'types', 'done_on', 'mileage', 'cost', 'garage', 'notes', 'user_id'];

    protected function casts(): array
    {
        return ['types' => 'array', 'done_on' => 'date', 'mileage' => 'integer', 'cost' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public function typeLabels(): array
    {
        return array_map(fn ($t) => MaintenanceType::tryFrom($t)?->label() ?? $t, $this->types ?? []);
    }
}
