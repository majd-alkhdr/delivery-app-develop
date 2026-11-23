<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * اختيار المحاسب الصريح لمركز كلفة فائض زبون في كشف.
 */
class FinanceSurplusChoice extends Model
{
    protected $fillable = ['distribution_sheet_id', 'customer_id', 'cost_center', 'chosen_by'];
}
