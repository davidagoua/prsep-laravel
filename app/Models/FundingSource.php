<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use Illuminate\Database\Eloquent\Model;

class FundingSource extends Model
{
    use IsReferential;

    protected $table = 'funding_sources';
}
