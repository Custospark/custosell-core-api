<?php

namespace App\Http\Controllers\Api\Pipeline;

use App\Http\Controllers\Controller;
use App\Http\Resources\PipelineLeadMeetingResource;
use App\Http\Resources\PipelineLeadResource;
use App\Models\BoardBookingSetting;
use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelineLeadMeeting;
use App\Services\PipelineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PipelineBookingController extends Controller
{
    public function __construct(
        protected PipelineService $pipelineService,
    ) {}

    public function getBookingSettings(Request $request, int $boardId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $board = PipelineBoard::query()->where('business_id', $businessId)->where('id', $boardId)->firstOrFail();
        $this->pipelineService->assertCanEditBoard($request->user(), $board);

        $settings = BoardBookingSetting::firstOrCreate(
            ['board_id' => $boardId],
            ['token' => Str::random(32), 'created_by' => $request->user()->id],
        );

        return response()->json(['data' => $settings]);
    }

    public function updateBookingSettings(Request $request, int $boardId): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'available_days' => ['nullable', 'array'],
            'available_days.*' => ['integer', 'min:1', 'max:7'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'slot_duration' => ['nullable', 'integer', 'min:15', 'max:240'],
            'break_duration' => ['nullable', 'integer', 'min:0', 'max:60'],
            'max_slots_per_day' => ['nullable', 'integer', 'min:1', 'max:50'],
            'meeting_title_prefix' => ['nullable', 'string', 'max:120'],
            'target_stage_id' => ['nullable', 'integer'],
        ]);

        $businessId = (int) $request->user()->business_id;
        $board = PipelineBoard::query()->where('business_id', $businessId)->where('id', $boardId)->firstOrFail();
        $this->pipelineService->assertCanEditBoard($request->user(), $board);

        $settings = BoardBookingSetting::firstOrCreate(
            ['board_id' => $boardId],
            ['token' => Str::random(32), 'created_by' => $request->user()->id],
        );

        $data = [];
        if (isset($validated['enabled'])) $data['enabled'] = $validated['enabled'];
        if (isset($validated['available_days'])) $data['available_days'] = $validated['available_days'];
        if (isset($validated['start_time'])) $data['start_time'] = $validated['start_time'];
        if (isset($validated['end_time'])) $data['end_time'] = $validated['end_time'];
        if (isset($validated['slot_duration'])) $data['slot_duration'] = $validated['slot_duration'];
        if (array_key_exists('break_duration', $validated)) $data['break_duration'] = $validated['break_duration'];
        if (isset($validated['max_slots_per_day'])) $data['max_slots_per_day'] = $validated['max_slots_per_day'];
        if (array_key_exists('meeting_title_prefix', $validated)) $data['meeting_title_prefix'] = $validated['meeting_title_prefix'];
        if (isset($validated['target_stage_id'])) $data['target_stage_id'] = $validated['target_stage_id'];

        $settings->update($data);
        return response()->json(['data' => $settings->fresh()]);
    }

    public function regenerateBookingToken(Request $request, int $boardId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $board = PipelineBoard::query()->where('business_id', $businessId)->where('id', $boardId)->firstOrFail();
        $this->pipelineService->assertCanEditBoard($request->user(), $board);

        $settings = BoardBookingSetting::where('board_id', $boardId)->firstOrFail();
        $settings->update(['token' => Str::random(32)]);

        return response()->json(['data' => $settings->fresh()]);
    }

    public function approveBooking(Request $request, int $leadId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $lead = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->firstOrFail();

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        if ($lead->booking_status !== 'pending') {
            return response()->json(['message' => 'Booking is not in pending state.'], 422);
        }

        $validated = $request->validate([
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        if (empty($validated['meeting_link'] ?? null) && empty($validated['notes'] ?? null)) {
            return response()->json([
                'message' => 'A meeting link or meeting notes is required to approve this booking.',
            ], 422);
        }

        $updateData = [
            'booking_status' => 'approved',
            'approved_at' => now(),
        ];

        if (array_key_exists('meeting_link', $validated)) {
            $updateData['meeting_link'] = $validated['meeting_link'];
        }

        if (array_key_exists('notes', $validated) && $validated['notes'] !== null) {
            $existingNotes = $lead->description;
            $updateData['description'] = $existingNotes
                ? $existingNotes . "\n\n" . $validated['notes']
                : $validated['notes'];
        }

        $lead->update($updateData);
        $lead->loadMissing('board');

        return response()->json([
            'message' => 'Booking approved',
            'data' => new PipelineLeadResource($lead),
        ]);
    }

    public function completeBooking(Request $request, int $leadId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $lead = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->firstOrFail();

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        if ($lead->booking_status !== 'approved') {
            return response()->json(['message' => 'Booking must be approved first.'], 422);
        }

        $lead->update([
            'booking_status' => 'completed',
            'start_date' => null,
            'due_date' => null,
        ]);

        return response()->json([
            'message' => 'Booking completed',
            'data' => new PipelineLeadResource($lead),
        ]);
    }

    public function rejectBooking(Request $request, int $leadId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $lead = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->firstOrFail();

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        if ($lead->booking_status !== 'pending') {
            return response()->json(['message' => 'Booking is not in pending state.'], 422);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $lead->update([
            'booking_status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'],
            'start_date' => null,
            'due_date' => null,
        ]);

        return response()->json([
            'message' => 'Booking rejected',
            'data' => new PipelineLeadResource($lead),
        ]);
    }

    public function clearBooking(Request $request, int $leadId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $lead = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->firstOrFail();

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        $lead->update([
            'booking_status' => null,
            'meeting_link' => null,
            'start_date' => null,
            'due_date' => null,
            'rejection_reason' => null,
            'rejected_at' => null,
            'approved_at' => null,
        ]);

        return response()->json([
            'message' => 'Booking cleared',
            'data' => new PipelineLeadResource($lead),
        ]);
    }

    public function scheduleMeeting(Request $request, int $leadId): JsonResponse
    {
        $businessId = (int) $request->user()->business_id;
        $lead = PipelineLead::query()
            ->where('business_id', $businessId)
            ->where('id', $leadId)
            ->firstOrFail();

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (empty($validated['meeting_link']) && empty($validated['notes'])) {
            return response()->json([
                'message' => 'Please provide a meeting link or meeting notes.',
                'errors' => [
                    'meeting_link' => ['Either a meeting link or meeting notes is required.'],
                    'notes' => ['Either a meeting link or meeting notes is required.'],
                ],
            ], 422);
        }

        if (!empty($validated['start_date'])) {
            $conflictBooking = PipelineLead::query()
                ->where('board_id', $lead->board_id)
                ->whereDate('start_date', $validated['start_date'])
                ->whereNotNull('start_date')
                ->whereNotIn('booking_status', ['rejected', 'completed'])
                ->exists();

            if ($conflictBooking) {
                return response()->json([
                    'message' => 'A booking already exists at this time on this board.',
                ], 409);
            }

            $conflictMeeting = PipelineLeadMeeting::query()
                ->whereHas('lead', fn ($q) => $q->where('board_id', $lead->board_id))
                ->whereDate('start_date', $validated['start_date'])
                ->whereNotNull('start_date')
                ->where('status', '!=', 'cancelled')
                ->exists();

            if ($conflictMeeting) {
                return response()->json([
                    'message' => 'A meeting already exists at this time on this board.',
                ], 409);
            }
        }

        $meeting = PipelineLeadMeeting::create([
            'lead_id' => $lead->id,
            'status' => 'scheduled',
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['due_date'] ?? null,
            'meeting_link' => $validated['meeting_link'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'created_by' => (int) $request->user()->id,
        ]);

        $settings = BoardBookingSetting::where('board_id', $lead->board_id)->first();
        $token = $settings?->token;
        $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
        $checkUrl = $token && $meeting->reference_code
            ? rtrim($frontendUrl, '/') . '/book/' . $token . '/check/' . $meeting->reference_code
            : null;

        return response()->json([
            'message' => 'Meeting scheduled',
            'data' => new PipelineLeadMeetingResource($meeting),
            'check_url' => $checkUrl,
            'reference_code' => $meeting->reference_code,
        ]);
    }

    public function updateMeeting(Request $request, int $meetingId): JsonResponse
    {
        $meeting = PipelineLeadMeeting::query()->findOrFail($meetingId);
        $lead = $meeting->lead;

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'meeting_link' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:scheduled,completed,cancelled'],
        ]);

        if (!empty($validated['start_date']) && $validated['start_date'] !== $meeting->start_date?->format('Y-m-d H:i:s')) {
            $conflictBooking = PipelineLead::query()
                ->where('board_id', $lead->board_id)
                ->whereDate('start_date', $validated['start_date'])
                ->whereNotNull('start_date')
                ->whereNotIn('booking_status', ['rejected', 'completed'])
                ->exists();

            if ($conflictBooking) {
                return response()->json([
                    'message' => 'A booking already exists at this time on this board.',
                ], 409);
            }

            $conflictMeeting = PipelineLeadMeeting::query()
                ->whereHas('lead', fn ($q) => $q->where('board_id', $lead->board_id))
                ->whereDate('start_date', $validated['start_date'])
                ->whereNotNull('start_date')
                ->where('id', '!=', $meeting->id)
                ->where('status', '!=', 'cancelled')
                ->exists();

            if ($conflictMeeting) {
                return response()->json([
                    'message' => 'A meeting already exists at this time on this board.',
                ], 409);
            }
        }

        $meeting->update($validated);

        return response()->json([
            'message' => 'Meeting updated',
            'data' => new PipelineLeadMeetingResource($meeting->fresh()),
        ]);
    }

    public function deleteMeeting(Request $request, int $meetingId): JsonResponse
    {
        $meeting = PipelineLeadMeeting::query()->findOrFail($meetingId);
        $lead = $meeting->lead;

        $this->pipelineService->assertCanEditBoard($request->user(), $lead->board);

        $meeting->delete();

        return response()->json(['message' => 'Meeting deleted']);
    }
}
