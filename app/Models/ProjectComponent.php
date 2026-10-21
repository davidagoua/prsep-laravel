<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectComponent extends Model
{
    protected $fillable = ['code', 'label', 'position'];

    public function subcomponents(): HasMany
    {
        return $this->hasMany(ProjectSubcomponent::class, 'component_id')->orderBy('position');
    }
}
