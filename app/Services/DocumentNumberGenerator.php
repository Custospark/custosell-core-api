<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;

class DocumentNumberGenerator
{
    /** First 4 alphanumeric chars from slug/name — unique per business, uppercase. */
    public static function businessCode(Business $business): string
    {
        $raw = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($business->slug ?: $business->name ?: ''));
        $code = strtoupper(substr($raw, 0, 4));

        if ($code === '') {
            return 'BIZX';
        }

        return str_pad($code, 4, 'X');
    }

    /** {BIZ4}-SAL-{YYMMDD}-{RANDOM7} */
    public static function saleReceiptNumber(Business $business): string
    {
        $code = self::businessCode($business);
        $date = now()->format('ymd');
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));

        return sprintf('%s-SAL-%s-%s', $code, $date, $rand);
    }

    /** {BIZ4}-INV-{YYYYMM}-{00001} */
    public static function invoiceNumber(Business $business, string $modelClass, string $column): string
    {
        return self::nextMonthlySequence($business, 'INV', $modelClass, $column);
    }

    /** {BIZ4}-RCP-{YYYYMM}-{00001} */
    public static function paymentReceiptNumber(Business $business, string $modelClass, string $column): string
    {
        return self::nextMonthlySequence($business, 'RCP', $modelClass, $column);
    }

    /** {BIZ4}-EST-{YYMMDD}-{RANDOM7} */
    public static function estimateNumber(Business $business, string $modelClass, string $column): string
    {
        $code = self::businessCode($business);
        $date = now()->format('ymd');
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));

        return sprintf('%s-EST-%s-%s', $code, $date, $rand);
    }

    /** {BIZ4}-PO-{YYYYMM}-{00001} — scoped by buyer_business_id */
    public static function purchaseOrderNumber(Business $buyer, string $modelClass, string $column): string
    {
        $code = self::businessCode($buyer);
        $ym = now()->format('Ym');
        $prefix = sprintf('%s-PO-%s', $code, $ym);

        $last = $modelClass::query()
            ->where('buyer_business_id', $buyer->id)
            ->where($column, 'like', $prefix . '-%')
            ->orderByDesc($column)
            ->lockForUpdate()
            ->first();

        $seq = 1;
        if ($last) {
            $parts = explode('-', (string) $last->{$column});
            $seq = ((int) end($parts)) + 1;
        }

        return sprintf('%s-%05d', $prefix, $seq);
    }

    /** {BIZ4}-PRJ-{YYMMDD}-{RANDOM7} */
    public static function projectNumber(Business $business, string $modelClass, string $column): string
    {
        $code = self::businessCode($business);
        $date = now()->format('ymd');
        $rand = strtoupper(substr(bin2hex(random_bytes(4)), 0, 7));

        return sprintf('%s-PRJ-%s-%s', $code, $date, $rand);
    }

    protected static function nextMonthlySequence(
        Business $business,
        string $docType,
        string $modelClass,
        string $column,
    ): string {
        $code = self::businessCode($business);
        $ym = now()->format('Ym');
        $prefix = sprintf('%s-%s-%s', $code, $docType, $ym);

        /** @var Model|null $last */
        $last = $modelClass::query()
            ->where('business_id', $business->id)
            ->where($column, 'like', $prefix . '-%')
            ->orderByDesc($column)
            ->lockForUpdate()
            ->first();

        $seq = 1;
        if ($last) {
            $parts = explode('-', (string) $last->{$column});
            $seq = ((int) end($parts)) + 1;
        }

        return sprintf('%s-%05d', $prefix, $seq);
    }
}
