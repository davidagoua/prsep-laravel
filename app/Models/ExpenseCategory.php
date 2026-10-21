<?php

namespace App\Models;

use App\Models\Concerns\IsReferential;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    use IsReferential;

    protected $table = 'expense_categories';
}
