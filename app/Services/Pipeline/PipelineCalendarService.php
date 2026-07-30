<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineLead;
use App\Models\User;
use App\Services\Pipeline\PipelineBoardLookupService;
use App\Services\Pipeline\PipelineBoardService;

class PipelineCalendarService
{
    public function __construct(
        protected PipelineBoardLookupService $lookup,
        protected PipelineBoardService $boardService,
    ) {}

    public function boardCalendar(int $businessId, User $user, int $boardId, int $year, int $month, string $dateField = 'due'): array
    {
        $board = $this->lookup->findBoardForUser($user, $boardId);

        $effectiveBusinessId = (int) $board->business_id;
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $query = PipelineLead::query()
            ->where('business_id', $effectiveBusinessId)
            ->where('board_id', $boardId)
            ->whereIn('status', ['open', 'won', 'lost'])
            ->with(['stage:id,name,color', 'assignee:id,name,avatar']);

        if ($dateField === 'start') {
            $query->whereBetween('start_date', [$start, $end]);
        } elseif ($dateField === 'close') {
            $query->whereBetween('expected_close_date', [$start, $end]);
        } elseif ($dateField === 'all') {
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('due_date', [$start, $end])
                    ->orWhereBetween('expected_close_date', [$start, $end]);
            });
        } else {
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('due_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('due_date')->whereBetween('expected_close_date', [$start, $end]);
                    });
            });
        }

        $leads = $query->get();
        $byDate = [];

        foreach ($leads as $lead) {
            $entries = $this->calendarDateEntriesForLead($lead, $dateField, $start, $end);
            foreach ($entries as $entry) {
                $byDate[$entry['date']][] = $this->formatCalendarLead($lead, $entry['kind'], $entry['time'] ?? null);
            }
        }

        ksort($byDate);

        return collect($byDate)
            ->map(fn ($group, $date) => [
                'date' => $date,
                'leads' => array_values($group),
            ])
            ->values()
            ->all();
    }

    public function allBoardsCalendar(int $businessId, User $user, int $year, int $month, string $dateField = 'due', string $workspace = 'pipeline'): array
    {
        $boards = $this->boardService->listBoards(
            $businessId,
            $user,
            salesOnly: $workspace === 'pipeline',
            estimatesWorkspace: $workspace === 'estimates',
        );

        $boardIds = $boards->pluck('id')->toArray();
        if (empty($boardIds)) {
            return [];
        }

        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $query = PipelineLead::query()
            ->whereIn('board_id', $boardIds)
            ->whereIn('status', ['open', 'won', 'lost'])
            ->with(['stage:id,name,color', 'assignee:id,name,avatar', 'board:id,name']);

        if ($dateField === 'start') {
            $query->whereBetween('start_date', [$start, $end]);
        } elseif ($dateField === 'close') {
            $query->whereBetween('expected_close_date', [$start, $end]);
        } elseif ($dateField === 'all') {
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start, $end])
                    ->orWhereBetween('due_date', [$start, $end])
                    ->orWhereBetween('expected_close_date', [$start, $end]);
            });
        } else {
            $query->where(function ($q) use ($start, $end) {
                $q->whereBetween('due_date', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end) {
                        $q2->whereNull('due_date')->whereBetween('expected_close_date', [$start, $end]);
                    });
            });
        }

        $leads = $query->get();
        $byDate = [];

        foreach ($leads as $lead) {
            $entries = $this->calendarDateEntriesForLead($lead, $dateField, $start, $end);
            foreach ($entries as $entry) {
                $formatted = $this->formatCalendarLead($lead, $entry['kind'], $entry['time'] ?? null);
                $formatted['board'] = $lead->board ? [
                    'id' => $lead->board->id,
                    'name' => $lead->board->name,
                ] : null;
                $byDate[$entry['date']][] = $formatted;
            }
        }

        ksort($byDate);

        return collect($byDate)
            ->map(fn ($group, $date) => [
                'date' => $date,
                'leads' => array_values($group),
            ])
            ->values()
            ->all();
    }

    public function insightsSummary(int $businessId, User $user, ?int $boardId = null): array
    {
        $boards = $boardId
            ? collect([$this->boardService->getBoard($businessId, $user, $boardId)])
            : $this->boardService->listBoards($businessId, $user, salesOnly: true);

        $boards = $boards->filter(fn ($board) => $board->project_id === null)->values();
        $boardIds = $boards->pluck('id');

        if ($boardIds->isEmpty()) {
            return [
                'open_leads' => 0,
                'open_pipeline_value' => 0,
                'won_leads' => 0,
                'lost_leads' => 0,
                'converted_leads' => 0,
                'win_rate_percent' => 0,
                'by_stage' => [],
                'by_source' => [],
            ];
        }

        $leads = PipelineLead::query()
            ->whereIn('board_id', $boardIds)
            ->where('card_type', 'lead')
            ->whereIn('status', ['open', 'won', 'lost', 'converted'])
            ->with(['stage:id,name,is_won,is_lost,color,sort_order', 'source:id,name'])
            ->get();

        $openLeads = $leads->where('status', 'open');
        $wonLeads = $leads->where('status', 'won');
        $lostLeads = $leads->where('status', 'lost');
        $convertedLeads = $leads->where('status', 'converted');

        $byStage = $openLeads->groupBy('stage_id')->map(function ($group, $stageId) {
            $stage = $group->first()->stage;
            return [
                'stage_id' => (int) $stageId,
                'stage_name' => $stage?->name ?? 'Unknown',
                'color' => $stage?->color,
                'sort_order' => $stage?->sort_order ?? 0,
                'count' => $group->count(),
                'value' => round((float) $group->sum('estimated_value'), 2),
            ];
        })->sortBy('sort_order')->values();

        $bySource = $openLeads->groupBy('source_id')->map(function ($group, $sourceId) {
            $source = $group->first()->source;
            return [
                'source_id' => $sourceId ? (int) $sourceId : null,
                'source_name' => $source?->name ?? 'No source',
                'count' => $group->count(),
                'value' => round((float) $group->sum('estimated_value'), 2),
            ];
        })->sortByDesc('count')->values();

        $totalOpen = $openLeads->count();
        $closed = $wonLeads->count() + $lostLeads->count();
        $winRate = $closed > 0 ? round(($wonLeads->count() / $closed) * 100, 1) : 0;

        return [
            'open_leads' => $totalOpen,
            'open_pipeline_value' => round((float) $openLeads->sum('estimated_value'), 2),
            'won_leads' => $wonLeads->count(),
            'lost_leads' => $lostLeads->count(),
            'converted_leads' => $convertedLeads->count(),
            'win_rate_percent' => $winRate,
            'by_stage' => $byStage,
            'by_source' => $bySource,
        ];
    }

    protected function calendarDateEntriesForLead(PipelineLead $lead, string $dateField, string $rangeStart, string $rangeEnd): array
    {
        $entries = [];
        $inRange = static function (?string $date) use ($rangeStart, $rangeEnd): bool {
            if (!$date) return false;
            return $date >= $rangeStart && $date <= $rangeEnd;
        };

        $push = static function (array &$entries, mixed $rawDate, string $kind) use ($inRange): void {
            if ($rawDate === null) return;
            $dateStr = $rawDate instanceof \Carbon\CarbonInterface
                ? $rawDate->toDateString()
                : substr((string) $rawDate, 0, 10);
            if (!$inRange($dateStr)) return;
            $timeStr = $rawDate instanceof \Carbon\CarbonInterface ? $rawDate->toISOString() : null;
            $entries[] = ['date' => $dateStr, 'time' => $timeStr, 'kind' => $kind];
        };

        if ($dateField === 'start') {
            $push($entries, $lead->start_date, 'start');
        } elseif ($dateField === 'close') {
            $push($entries, $lead->expected_close_date, 'close');
        } elseif ($dateField === 'all') {
            $push($entries, $lead->start_date, 'start');
            $push($entries, $lead->due_date, 'due');
            $push($entries, $lead->expected_close_date, 'close');
        } else {
            if ($lead->due_date) {
                $push($entries, $lead->due_date, 'due');
            } else {
                $push($entries, $lead->expected_close_date, 'close');
            }
        }

        return $entries;
    }

    protected function formatCalendarLead(PipelineLead $lead, string $dateKind, ?string $time = null): array
    {
        return [
            'id' => $lead->id,
            'title' => $lead->title,
            'card_type' => $lead->card_type ?? 'lead',
            'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
            'currency' => $lead->currency,
            'status' => $lead->status,
            'priority' => $lead->priority,
            'date_kind' => $dateKind,
            'time' => $time,
            'stage' => $lead->stage ? [
                'id' => $lead->stage->id,
                'name' => $lead->stage->name,
                'color' => $lead->stage->color,
            ] : null,
            'assignee' => $lead->assignee ? [
                'id' => $lead->assignee->id,
                'name' => $lead->assignee->name,
                'avatar' => $lead->assignee->avatar,
            ] : null,
        ];
    }
}
