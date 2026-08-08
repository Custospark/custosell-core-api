<?php

declare(strict_types=1);

namespace App\Services\Hr;

use Carbon\Carbon;

/**
 * Verdict resolution and AI-free suggestion text helpers for work
 * performance reviews seeded from Pipeline/Projects activity.
 */
class HrPerformanceInsights
{
    /**
     * @param  array<string, mixed>  $goals
     * @param  array<string, int|float>  $leads
     * @param  array<string, int|float>  $tasks
     */
    public function resolveVerdict(array $goals, array $leads, array $tasks): string
    {
        $hasGoals = ($goals['total'] ?? 0) > 0;
        $hasWork = ($leads['total'] ?? 0) > 0 || ($tasks['total'] ?? 0) > 0;

        if (! $hasGoals && ! $hasWork) {
            return 'no_data';
        }

        if ($hasGoals) {
            if (($goals['behind_count'] ?? 0) > 0) {
                return 'behind';
            }
            if (($goals['at_risk_count'] ?? 0) > 0) {
                return 'at_risk';
            }
            if (($goals['on_track_count'] ?? 0) > 0) {
                return 'on_track';
            }
        }

        // Fallback when no member goals: use overdue + completion signals from work items.
        $overdue = (int) ($leads['overdue'] ?? 0) + (int) ($tasks['overdue'] ?? 0);
        if ($overdue >= 3) {
            return 'behind';
        }
        if ($overdue >= 1) {
            return 'at_risk';
        }

        $completion = (float) ($tasks['completion_rate'] ?? 0);
        $winRate = (float) ($leads['win_rate'] ?? 0);
        if ($completion >= 70 || $winRate >= 40 || ((int) ($tasks['done'] ?? 0) + (int) ($leads['won'] ?? 0)) > 0) {
            return 'on_track';
        }

        return $hasWork ? 'at_risk' : 'no_data';
    }

    public function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            'on_track' => 'Meeting goals',
            'at_risk' => 'At risk',
            'behind' => 'Behind goals',
            'unlinked' => 'No app login linked',
            default => 'No work data yet',
        };
    }

    public function suggestedRating(string $verdict): ?float
    {
        return match ($verdict) {
            'on_track' => 4.0,
            'at_risk' => 3.0,
            'behind' => 2.0,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function suggestedStrengths(array $snapshot): ?string
    {
        $parts = [];
        $goalsOnTrack = (int) ($snapshot['goals']['on_track_count'] ?? 0);
        $won = (int) ($snapshot['leads']['won'] ?? 0);
        $done = (int) ($snapshot['project_tasks']['done'] ?? 0);

        if ($goalsOnTrack > 0) {
            $parts[] = sprintf('%d board goal(s) on track or achieved.', $goalsOnTrack);
        }
        if ($won > 0) {
            $parts[] = sprintf('%d won pipeline card(s).', $won);
        }
        if ($done > 0) {
            $parts[] = sprintf('%d completed project task(s).', $done);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function suggestedImprovements(array $snapshot): ?string
    {
        $parts = [];
        $behind = (int) ($snapshot['goals']['behind_count'] ?? 0);
        $atRisk = (int) ($snapshot['goals']['at_risk_count'] ?? 0);
        $leadOverdue = (int) ($snapshot['leads']['overdue'] ?? 0);
        $taskOverdue = (int) ($snapshot['project_tasks']['overdue'] ?? 0);

        if ($behind > 0) {
            $parts[] = sprintf('%d goal(s) behind pace — review targets and blockers.', $behind);
        }
        if ($atRisk > 0) {
            $parts[] = sprintf('%d goal(s) at risk — tighten weekly follow-up.', $atRisk);
        }
        if ($leadOverdue > 0) {
            $parts[] = sprintf('%d overdue pipeline card(s).', $leadOverdue);
        }
        if ($taskOverdue > 0) {
            $parts[] = sprintf('%d overdue project task(s).', $taskOverdue);
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function suggestedNotes(array $snapshot): string
    {
        return sprintf(
            "Auto-seeded from Pipeline/Projects work on %s.\nVerdict: %s.\nGoals avg progress: %s%%.\nLeads open/won/overdue: %d/%d/%d.\nProject tasks open/done/overdue: %d/%d/%d.",
            Carbon::parse($snapshot['evaluated_at'])->toDateString(),
            $snapshot['verdict_label'],
            (string) ($snapshot['goals']['average_progress_percent'] ?? 0),
            (int) ($snapshot['leads']['open'] ?? 0),
            (int) ($snapshot['leads']['won'] ?? 0),
            (int) ($snapshot['leads']['overdue'] ?? 0),
            (int) ($snapshot['project_tasks']['open'] ?? 0),
            (int) ($snapshot['project_tasks']['done'] ?? 0),
            (int) ($snapshot['project_tasks']['overdue'] ?? 0),
        );
    }
}