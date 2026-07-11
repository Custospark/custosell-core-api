<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessSupplierListEntry extends Model
{
    protected $table = 'business_supplier_list';

    protected $fillable = [
        'buyer_business_id',
        'seller_business_id',
        'notes',
    ];

    public function buyerBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'buyer_business_id');
    }

    public function sellerBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'seller_business_id');
    }
}
