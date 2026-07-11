<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'invoice_number',
        'customer_id',
        'sale_id',
        'estimate_id',
        'purchase_order_id',
        'buyer_business_id',
        'issue_date',
        'due_date',
        'status',
        'subtotal',
        'tax_total',
        'total_amount',
        'amount_paid',
        'notes',
        'email_sent_count',
        'last_emailed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'email_sent_count' => 'integer',
            'last_emailed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function buyerBusiness(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'buyer_business_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** True when this business is the invoice issuer (seller). */
    public function isIssuedBy(int $businessId): bool
    {
        return (int) $this->business_id === $businessId;
    }

    /** True when this business is the B2B buyer on a PO-linked invoice. */
    public function isReceivedBy(int $businessId): bool
    {
        return $this->buyer_business_id !== null
            && (int) $this->buyer_business_id === $businessId;
    }
}
