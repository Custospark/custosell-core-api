<?php

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineLead;
use App\Models\PipelinePoll;
use App\Models\User;
use App\Services\Notification\NotificationService;

class PipelineNotificationService
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /** @param  list<User>  $recipients */
    public function notifyAssignees(
        PipelineLead $lead,
        PipelineBoard $board,
        User $actor,
        array $recipients,
        bool $isNew = false,
    ): void {
        foreach ($recipients as $recipient) {
            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            $title = $isNew
                ? "You've been assigned: {$lead->title}"
                : "Assignment updated: {$lead->title}";

            $body = $this->wrapBody(
                '<p><strong>'.e($actor->name).'</strong> '
                .($isNew ? 'assigned you to' : 'updated your assignment on')
                .' <strong>'.e($lead->title).'</strong> on board <em>'.e($board->name).'</em>.</p>'
                .$this->metaLine('Board', $board->name)
                .$this->metaLine('Card', $lead->title),
            );

            $this->dispatch(
                $recipient,
                $title,
                $body,
                'pipeline.assignment',
                (int) $lead->business_id,
                [
                    'lead_id' => $lead->id,
                    'board_id' => $board->id,
                ],
                $this->boardCta($board, $lead),
                'Open card',
            );
        }
    }

    /** @param  list<User>  $recipients */
    public function notifyComment(
        PipelineLead $lead,
        PipelineBoard $board,
        User $actor,
        string $commentPreview,
        array $recipients,
        bool $isReply = false,
    ): void {
        foreach ($recipients as $recipient) {
            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            $title = $isReply
                ? "New reply on {$lead->title}"
                : "New comment on {$lead->title}";

            $body = $this->wrapBody(
                '<p><strong>'.e($actor->name).'</strong> '
                .($isReply ? 'replied on' : 'commented on')
                .' <strong>'.e($lead->title).'</strong>.</p>'
                .'<blockquote style="margin:12px 0;padding:12px 16px;border-left:3px solid #2563eb;background:#f8fafc;color:#334155;">'
                .e($this->truncate($commentPreview, 280))
                .'</blockquote>',
            );

            $this->dispatch(
                $recipient,
                $title,
                $body,
                'pipeline.comment',
                (int) $lead->business_id,
                [
                    'lead_id' => $lead->id,
                    'board_id' => $board->id,
                ],
                $this->boardCta($board, $lead),
                'View discussion',
            );
        }
    }

    /** @param  list<User>  $recipients */
    public function notifyAnnouncement(
        PipelineBoard $board,
        User $actor,
        string $announcementTitle,
        string $announcementBody,
        array $recipients,
    ): void {
        foreach ($recipients as $recipient) {
            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            $title = "Board notice: {$announcementTitle}";
            $body = $this->wrapBody(
                '<p><strong>'.e($actor->name).'</strong> posted an announcement on <em>'.e($board->name).'</em>.</p>'
                .'<h3 style="margin:16px 0 8px;font-size:16px;color:#0f172a;">'.e($announcementTitle).'</h3>'
                .'<p style="color:#334155;line-height:1.6;">'.nl2br(e($announcementBody)).'</p>',
            );

            $this->dispatch(
                $recipient,
                $title,
                $body,
                'pipeline.announcement',
                (int) $board->business_id,
                ['board_id' => $board->id],
                $this->boardCta($board),
                'Open board',
            );
        }
    }

    /** @param  list<User>  $recipients */
    public function notifyPoll(
        PipelinePoll $poll,
        PipelineBoard $board,
        User $actor,
        array $recipients,
    ): void {
        foreach ($recipients as $recipient) {
            if ((int) $recipient->id === (int) $actor->id) {
                continue;
            }

            $title = "New poll: {$poll->question}";
            $body = $this->wrapBody(
                '<p><strong>'.e($actor->name).'</strong> started a poll on <em>'.e($board->name).'</em>.</p>'
                .'<p style="font-size:15px;font-weight:600;color:#0f172a;">'.e($poll->question).'</p>'
                .'<p style="color:#64748b;">Your vote helps the team decide — open the board to participate.</p>',
            );

            $lead = $poll->lead_id ? $poll->lead : null;

            $this->dispatch(
                $recipient,
                $title,
                $body,
                'pipeline.poll',
                (int) $board->business_id,
                [
                    'board_id' => $board->id,
                    'poll_id' => $poll->id,
                    'lead_id' => $poll->lead_id,
                ],
                $lead ? $this->boardCta($board, $lead) : $this->boardCta($board),
                $lead ? 'View card poll' : 'Open board',
            );
        }
    }

    public function notifyReminder(User $recipient, PipelineLead $lead, PipelineBoard $board, ?string $message): void
    {
        $title = "Reminder: {$lead->title}";
        $body = $this->wrapBody(
            '<p>This is your scheduled reminder for <strong>'.e($lead->title).'</strong> on <em>'.e($board->name).'</em>.</p>'
            .($message ? '<p style="color:#334155;">'.nl2br(e($message)).'</p>' : '')
            .$this->metaLine('Due', $lead->due_date?->toDateString() ?? 'Not set'),
        );

        $this->dispatch(
            $recipient,
            $title,
            $body,
            'pipeline.reminder',
            (int) $lead->business_id,
            [
                'lead_id' => $lead->id,
                'board_id' => $board->id,
            ],
            $this->boardCta($board, $lead),
            'Open card',
        );
    }

    protected function dispatch(
        User $recipient,
        string $title,
        string $htmlBody,
        string $type,
        int $businessId,
        array $metadata,
        ?string $ctaUrl,
        string $ctaLabel,
    ): void {
        $this->notifications->sendToUser(
            $recipient,
            $title,
            $htmlBody,
            $type,
            'both',
            $businessId,
            $type,
            $metadata,
            $ctaUrl,
            $ctaLabel,
            'You are receiving this because you are on the board team in Custosell.',
        );
    }

    protected function wrapBody(string $inner): string
    {
        return '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;color:#334155;">'
            .$inner
            .'</div>';
    }

    protected function metaLine(string $label, string $value): string
    {
        return '<p style="margin:4px 0;color:#64748b;"><span style="font-weight:600;color:#475569;">'
            .e($label).':</span> '.e($value).'</p>';
    }

    protected function truncate(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1).'…';
    }

    protected function boardCta(PipelineBoard $board, ?PipelineLead $lead = null): ?string
    {
        $base = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        if ($lead) {
            return "{$base}/pipeline/boards/{$board->id}?lead={$lead->id}";
        }

        return "{$base}/pipeline/boards/{$board->id}";
    }
}
