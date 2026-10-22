<?php

namespace App\Models\Fleet;

use App\Enums\Fleet\IncidentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleIncident extends Model
{
    protected $fillable = ['vehicle_id', 'trip_id', 'type', 'occurred_on', 'note'];

    protected function casts(): array
    {
        return ['type' => IncidentType::class, 'occurred_on' => 'date'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(VehicleTrip::class, 'trip_id');
    }
}
