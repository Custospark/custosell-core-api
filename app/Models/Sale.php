<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'user_id',
        'customer_id',
        'shift_id',
        'order_id',
        'receipt_number',
        'subtotal',
        'tax_total',
        'discount_amount',
        'total_amount',
        'amount_paid',
        'amount_tendered',
        'change_given',
        'payment_method',
        'payment_status',
        'notes',
        'sale_date',
        'email_sent_count',
        'last_emailed_at',
        'fiscal_status',
        'fiscal_fdn',
        'fiscal_qr',
        'fiscal_verification_code',
        'fiscal_payload',
        'fiscal_response',
        'fiscalized_at',
        'fiscal_last_error',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_tendered' => 'decimal:2',
            'change_given' => 'decimal:2',
            'sale_date' => 'datetime',
            'email_sent_count' => 'integer',
            'last_emailed_at' => 'datetime',
            'fiscal_payload' => 'array',
            'fiscal_response' => 'array',
            'fiscalized_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function linkedInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class, 'sale_id');
    }
}
