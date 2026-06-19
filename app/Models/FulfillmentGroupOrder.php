<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FulfillmentGroupOrder extends Model
{
    protected $fillable = ['fulfillment_group_id', 'sales_order_id'];
}
