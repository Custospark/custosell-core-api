<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardBookingSetting extends Model
{
    protected $table = 'board_booking_settings';

    protected $appends = ['booking_url'];

    protected $fillable = [
        'board_id',
        'enabled',
        'token',
        'available_days',
        'start_time',
        'end_time',
        'slot_duration',
        'break_duration',
        'max_slots_per_day',
        'meeting_title_prefix',
        'meeting_link',
        'target_stage_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'available_days' => 'array',
            'slot_duration' => 'integer',
            'break_duration' => 'integer',
            'max_slots_per_day' => 'integer',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(PipelineBoard::class, 'board_id');
    }

    public function targetStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'target_stage_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected function bookingUrl(): Attribute
    {
        return Attribute::get(function () {
            if (!$this->token) {
                return null;
            }
            $frontend = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
            return $frontend . '/book/' . $this->token;
        });
    }
}
