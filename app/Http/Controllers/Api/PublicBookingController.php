<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineLeadResource;
use App\Models\BoardBookingSetting;
use App\Models\PipelineLead;
use App\Models\PipelineLeadMeeting;
use App\Models\PipelineStage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBookingController extends Controller
{
    public function info(string $token): JsonResponse
    {
        $settings = BoardBookingSetting::query()
            ->where('token', $token)
            ->where('enabled', true)
            ->with([
                'board' => fn($q) => $q->select('id', 'name', 'business_id'),
                'board.business' => fn($q) => $q->select('id', 'name', 'logo_path'),
                'targetStage' => fn($q) => $q->select('id', 'name'),
            ])
            ->first();

        if (!$settings) {
            return response()->json(['message' => 'Booking not found or disabled.'], 404);
        }

        return response()->json([
            'data' => [
                'board_name' => $settings->board?->name,
                'business_name' => $settings->board?->business?->name,
                'logo_path' => $settings->board?->business?->logo_path,
                'available_days' => $settings->available_days ?? [1, 2, 3, 4, 5],
                'meeting_link' => $settings->meeting_link,
                'start_time' => $settings->start_time,
                'end_time' => $settings->end_time,
                'slot_duration' => $settings->slot_duration,
                'max_slots_per_day' => $settings->max_slots_per_day,
                'meeting_title_prefix' => $settings->meeting_title_prefix,
            ],
        ]);
    }

    public function slots(Request $request, string $token): JsonResponse
    {
        $settings = BoardBookingSetting::query()
            ->where('token', $token)
            ->where('enabled', true)
            ->first();

        if (!$settings) {
            return response()->json(['message' => 'Booking not found or disabled.'], 404);
        }

        $request->validate(['date' => ['required', 'date_format:Y-m-d']]);
        $date = $request->input('date');
        $dayOfWeek = Carbon::parse($date)->dayOfWeekIso;
        $availableDays = $settings->available_days ?? [1, 2, 3, 4, 5];

        if (!in_array($dayOfWeek, $availableDays)) {
            return response()->json(['data' => []]);
        }

        $start = Carbon::parse($date . ' ' . $settings->start_time);
        $end = Carbon::parse($date . ' ' . $settings->end_time);
        $duration = (int) $settings->slot_duration;

        $bookedTimes = PipelineLead::query()
            ->where('board_id', $settings->board_id)
            ->whereDate('start_date', $date)
            ->whereNotNull('start_date')
            ->whereNotIn('booking_status', ['rejected', 'completed'])
            ->pluck('start_date')
            ->map(fn ($d) => Carbon::parse($d)->format('H:i'))
            ->toArray();

        $slots = [];
        $takenSlots = [];
        $current = clone $start;
        $maxSlots = (int) $settings->max_slots_per_day;

        while ($current < $end && count($slots) + count($takenSlots) < $maxSlots) {
            $timeStr = $current->format('H:i');
            $endTime = (clone $current)->addMinutes($duration)->format('H:i');
            $entry = ['time' => $timeStr, 'end_time' => $endTime];
            if (in_array($timeStr, $bookedTimes)) {
                $entry['available'] = false;
                $takenSlots[] = $entry;
            } else {
                $entry['available'] = true;
                $slots[] = $entry;
            }
            $current->addMinutes($duration);
        }

        return response()->json(['data' => ['slots' => array_merge($slots, $takenSlots)]]);
    }

    public function book(Request $request, string $token): JsonResponse
    {
        $settings = BoardBookingSetting::query()
            ->where('token', $token)
            ->where('enabled', true)
            ->with(['board' => fn($q) => $q->with('business')])
            ->first();

        if (!$settings) {
            return response()->json(['message' => 'Booking not found or disabled.'], 404);
        }

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'meeting_link' => ['nullable', 'url', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $dateTimeStr = $validated['date'] . ' ' . $validated['time'] . ':00';
        $dateTime = Carbon::parse($dateTimeStr);

        $dayOfWeek = $dateTime->dayOfWeekIso;
        $availableDays = $settings->available_days ?? [1, 2, 3, 4, 5];
        if (!in_array($dayOfWeek, $availableDays)) {
            return response()->json(['message' => 'Selected day is not available.'], 422);
        }

        $dayStart = Carbon::parse($validated['date'] . ' ' . $settings->start_time);
        $dayEnd = Carbon::parse($validated['date'] . ' ' . $settings->end_time);
        if ($dateTime < $dayStart || $dateTime >= $dayEnd) {
            return response()->json(['message' => 'Selected time is outside available hours.'], 422);
        }

        $existing = PipelineLead::query()
            ->where('board_id', $settings->board_id)
            ->where('start_date', $dateTime)
            ->whereNotIn('booking_status', ['rejected', 'completed'])
            ->exists();

        if ($existing) {
            return response()->json(['message' => 'This time slot is already booked.'], 409);
        }

        $stageId = $settings->target_stage_id;
        if (!$stageId) {
            $firstStage = PipelineStage::query()
                ->where('board_id', $settings->board_id)
                ->orderBy('sort_order')
                ->first();
            $stageId = $firstStage?->id;
        }

        $title = ($settings->meeting_title_prefix ?? 'Booking: ') . $validated['name'];
        $contactEmail = $validated['email'] ?? null;
        $contactPhone = $validated['phone'] ?? null;
        $userMeetingLink = $validated['meeting_link'] ?? null;
        $description = $validated['notes'] ?? null;

        $duration = (int) $settings->slot_duration;
        $dueDateTime = (clone $dateTime)->addMinutes($duration);

        $lead = PipelineLead::create([
            'business_id' => $settings->board->business_id,
            'board_id' => $settings->board_id,
            'stage_id' => $stageId ?? 1,
            'created_by' => $settings->created_by,
            'title' => $title,
            'card_type' => 'lead',
            'contact_name' => $validated['name'],
            'contact_email' => $contactEmail,
            'contact_phone' => $contactPhone,
            'description' => $description,
            'status' => 'open',
            'booking_status' => 'pending',
            'meeting_link' => $userMeetingLink ?: $settings->meeting_link,
            'start_date' => $dateTime,
            'due_date' => $dueDateTime,
            'currency' => $settings->board->business->currency ?? 'UGX',
        ]);

        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');

        return response()->json([
            'message' => 'Booked successfully',
            'data' => new PipelineLeadResource($lead),
            'reference_code' => $lead->reference_code,
            'check_url' => rtrim($frontendUrl, '/') . '/book/' . $token . '/check/' . $lead->reference_code,
        ], 201);
    }

    public function check(Request $request, string $token, string $reference): JsonResponse
    {
        $settings = BoardBookingSetting::query()
            ->where('token', $token)
            ->where('enabled', true)
            ->with([
                'board' => fn($q) => $q->select('id', 'name', 'business_id'),
                'board.business' => fn($q) => $q->select('id', 'name', 'email', 'phone', 'address', 'city', 'state', 'postal_code', 'country'),
            ])
            ->first();

        if (!$settings) {
            return response()->json(['message' => 'Booking link not found or disabled.'], 404);
        }

        $boardId = $settings->board_id;
        $business = $settings->board?->business;

        $lead = PipelineLead::query()
            ->where('board_id', $boardId)
            ->where('reference_code', $reference)
            ->first();

        if ($lead) {
            return response()->json([
                'data' => [
                    'business_name' => $business?->name,
                    'board_name' => $settings->board?->name,
                    'business_email' => $business?->email,
                    'business_phone' => $business?->phone,
                    'business_address' => $business?->address,
                    'business_city' => $business?->city,
                    'business_state' => $business?->state,
                    'business_postal_code' => $business?->postal_code,
                    'business_country' => $business?->country,
                    'reference_code' => $lead->reference_code,
                    'booking_status' => $lead->booking_status,
                    'rejection_reason' => $lead->rejection_reason,
                    'name' => $lead->contact_name,
                    'email' => $lead->contact_email,
                    'phone' => $lead->contact_phone,
                    'start_date' => $lead->start_date?->toISOString(),
                    'end_date' => $lead->due_date?->toISOString(),
                    'meeting_link' => $lead->meeting_link,
                    'notes' => $lead->description,
                    'approved_at' => $lead->approved_at?->toISOString(),
                    'rejected_at' => $lead->rejected_at?->toISOString(),
                ],
            ]);
        }

        $meeting = PipelineLeadMeeting::query()
            ->whereHas('lead', fn($q) => $q->where('board_id', $boardId))
            ->where('reference_code', $reference)
            ->first();

        if (!$meeting) {
            return response()->json(['message' => 'Booking not found.'], 404);
        }

        return response()->json([
            'data' => [
                'business_name' => $business?->name,
                'board_name' => $settings->board?->name,
                'business_email' => $business?->email,
                'business_phone' => $business?->phone,
                'business_address' => $business?->address,
                'business_city' => $business?->city,
                'business_state' => $business?->state,
                'business_postal_code' => $business?->postal_code,
                'business_country' => $business?->country,
                'reference_code' => $meeting->reference_code,
                'booking_status' => $meeting->status === 'scheduled' ? 'approved' : $meeting->status,
                'rejection_reason' => $meeting->rejection_reason,
                'name' => $meeting->lead->contact_name,
                'email' => $meeting->lead->contact_email,
                'phone' => $meeting->lead->contact_phone,
                'start_date' => $meeting->start_date?->toISOString(),
                'end_date' => $meeting->end_date?->toISOString(),
                'meeting_link' => $meeting->meeting_link,
                'notes' => $meeting->notes,
                'approved_at' => $meeting->created_at?->toISOString(),
                'rejected_at' => null,
            ],
        ]);
    }
}
