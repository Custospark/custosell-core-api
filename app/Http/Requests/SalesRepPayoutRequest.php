<?php

namespace App\Http\Requests;

use App\Models\SalesRep;
use App\Models\Referral;
use App\Enums\Billing\ReferralStatus;
use Illuminate\Validation\Validator;

class SalesRepPayoutRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_method' => ['sometimes', 'nullable', 'string', 'max:30'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'paid_at' => ['sometimes', 'date'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $repId = $this->route('id');
            if (!$repId) return;

            $rep = SalesRep::with('referralCode.referrals')->find($repId);
            if (!$rep) return;

            $totalCommission = (float) ($rep->referralCode?->referrals->sum('commission_earned') ?? 0);
            $totalPaid = (float) $rep->payouts()->where('status', 'paid')->sum('amount');
            $pending = round(max(0, $totalCommission - $totalPaid), 2);

            $amount = (float) $this->input('amount');
            if ($amount > $pending) {
                $validator->errors()->add('amount', "Payout amount ({$amount}) exceeds pending commission ({$pending}).");
            }
        });
    }
}
