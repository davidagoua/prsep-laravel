<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use Illuminate\Database\Eloquent\Model;

class Ugp extends Model
{
    use IsReferential;

    protected $table = 'ugps';
}
