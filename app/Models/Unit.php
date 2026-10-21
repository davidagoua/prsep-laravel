<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use IsReferential;

    protected $table = 'units';
}
