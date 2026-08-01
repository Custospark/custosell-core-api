<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardConversationRead;
use App\Models\PipelineBoardMessage;
use App\Models\PipelineBoardMessageAttachment;
use App\Models\PipelineBoardMessageMention;
use App\Models\PipelineBoardMessageReaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PipelineBoardConversationPresenter
{
  public function __construct(
    protected PipelineBoardService $boards,
    protected PipelineBoardPermissionService $permission,
    protected PipelineNotificationService $notifier,
  ) {}

  public function findMessageForBusiness(int $businessId, int $messageId): PipelineBoardMessage
  {
    if ($messageId < 1) {
      abort(404, 'Message not found.');
    }

    return PipelineBoardMessage::query()
      ->where('business_id', $businessId)
      ->whereKey($messageId)
      ->firstOrFail();
  }

  /** @return Collection<int, PipelineBoardMessage> */
  public function loadBoardMessages(PipelineBoard $board): Collection
  {
    return PipelineBoardMessage::query()
      ->where('board_id', $board->id)
      ->with([
        'user:id,name,avatar',
        'reactions:id,message_id,user_id,reaction',
        'mentions.user:id,name,avatar',
        'attachments',
        'pinnedByUser:id,name,avatar',
      ])
      ->orderBy('created_at')
      ->get();
  }

  public function reloadMessage(PipelineBoardMessage $message): PipelineBoardMessage
  {
    return $message->fresh([
      'user:id,name,avatar',
      'reactions:id,message_id,user_id,reaction',
      'mentions.user:id,name,avatar',
      'attachments',
      'pinnedByUser:id,name,avatar',
    ]) ?? $message;
  }

  public function assertCanEditMessage(User $user, PipelineBoardMessage $message, PipelineBoard $board): void
  {
    if ($message->is_system) {
      abort(403, 'Automated messages cannot be edited.');
    }

    if ((int) $message->user_id !== (int) $user->id) {
      abort(403, 'You can only edit your own messages.');
    }

    $this->boards->getBoard((int) $board->business_id, $user, (int) $board->id);
  }

  public function assertCanDeleteMessage(User $user, PipelineBoardMessage $message, PipelineBoard $board): void
  {
    if ($message->is_system) {
      abort(403, 'Automated messages cannot be deleted.');
    }

    $isAuthor = (int) $message->user_id === (int) $user->id;
    if ($isAuthor || $this->permission->userCanManageBoard($user, $board)) {
      return;
    }

    abort(403, 'You can only delete your own messages or moderate as a board manager.');
  }

  /** @param  Collection<int, PipelineBoardMessage>  $messages */
  public function unreadCountForUser(PipelineBoard $board, User $user, Collection $messages): int
  {
    $readState = PipelineBoardConversationRead::query()
      ->where('board_id', $board->id)
      ->where('user_id', $user->id)
      ->first();

    $lastReadId = (int) ($readState?->last_read_message_id ?? 0);

    return $messages
      ->filter(fn (PipelineBoardMessage $message) => (int) $message->id > $lastReadId
        && (int) $message->user_id !== (int) $user->id)
      ->count();
  }

  /** @return list<User> */
  public function messageNotificationRecipients(
    PipelineBoard $board,
    User $actor,
    ?int $parentId,
  ): array {
    $recipients = collect($this->notifier->boardRecipientsForNotifications($board, $actor));

    if ($parentId !== null) {
      $parent = PipelineBoardMessage::query()->find($parentId);
      if ($parent && (int) $parent->user_id !== (int) $actor->id) {
        $parentUser = User::query()->find($parent->user_id);
        if ($parentUser) {
          $recipients->push($parentUser);
        }
      }
    }

    return $recipients
      ->unique('id')
      ->reject(fn (User $recipient) => (int) $recipient->id === (int) $actor->id)
      ->values()
      ->all();
  }

  /** @return list<User> */
  public function syncMentions(PipelineBoardMessage $message, PipelineBoard $board, User $actor): array
  {
    $mentionedIds = $this->parseMentionIds((string) $message->body);
    PipelineBoardMessageMention::query()->where('message_id', $message->id)->delete();

    $mentionedUsers = [];
    foreach ($mentionedIds as $userId) {
      $mentioned = User::query()
        ->where('business_id', $board->business_id)
        ->whereKey($userId)
        ->first();

      if (! $mentioned || (int) $mentioned->id === (int) $actor->id) {
        continue;
      }

      PipelineBoardMessageMention::create([
        'message_id' => $message->id,
        'user_id' => $mentioned->id,
      ]);
      $mentionedUsers[] = $mentioned;
    }

    return $mentionedUsers;
  }

  /** @return list<int> */
  public function parseMentionIds(string $body): array
  {
    preg_match_all('/@\[(\d+)\]/', $body, $matches);

    return collect($matches[1] ?? [])
      ->map(fn ($id) => (int) $id)
      ->filter(fn ($id) => $id > 0)
      ->unique()
      ->values()
      ->all();
  }

  public function isValidReaction(string $reaction): bool
  {
    if (in_array($reaction, ['like', 'dislike'], true)) {
      return true;
    }

    return mb_strlen($reaction) <= 8 && preg_match('/^\X$/u', $reaction) === 1;
  }

  /** @return array{likes: int, dislikes: int, user_reaction: string|null, emoji_counts: array<string, int>} */
  public function reactionSummary(PipelineBoardMessage $message, User $viewer): array
  {
    $rows = PipelineBoardMessageReaction::query()
      ->where('message_id', $message->id)
      ->get();

    $emojiCounts = [];
    $likes = 0;
    $dislikes = 0;
    $userReaction = null;

    foreach ($rows as $row) {
      if ((int) $row->user_id === (int) $viewer->id) {
        $userReaction = $row->reaction;
      }
      if ($row->reaction === 'like') {
        $likes++;
      } elseif ($row->reaction === 'dislike') {
        $dislikes++;
      } else {
        $emojiCounts[$row->reaction] = ($emojiCounts[$row->reaction] ?? 0) + 1;
      }
    }

    return [
      'likes' => $likes,
      'dislikes' => $dislikes,
      'user_reaction' => $userReaction,
      'emoji_counts' => $emojiCounts,
    ];
  }

  /** @return array<string, mixed> */
  public function serializeAttachment(PipelineBoardMessageAttachment $attachment): array
  {
    return [
      'id' => $attachment->id,
      'message_id' => $attachment->message_id,
      'file_name' => $attachment->file_name,
      'mime_type' => $attachment->mime_type,
      'file_size' => $attachment->file_size,
      'url' => Storage::disk('public')->url($attachment->file_path),
    ];
  }

  /** @return array<string, mixed> */
  public function serializeMessage(PipelineBoardMessage $message, User $viewer, PipelineBoard $board): array
  {
    $isSystem = (bool) $message->is_system;
    $isAuthor = (int) $message->user_id === (int) $viewer->id;
    $canModerate = $this->permission->userCanManageBoard($viewer, $board);

    return [
      'id' => $message->id,
      'board_id' => $message->board_id,
      'parent_id' => $message->parent_id,
      'user_id' => $message->user_id,
      'body' => $message->body,
      'is_system' => $isSystem,
      'is_pinned' => (bool) $message->is_pinned,
      'pinned_at' => $message->pinned_at?->toISOString(),
      'pinned_by' => $message->pinned_by,
      'edited_at' => $message->edited_at?->toISOString(),
      'created_at' => $message->created_at?->toISOString(),
      'updated_at' => $message->updated_at?->toISOString(),
      'user' => $message->user ? [
        'id' => $message->user->id,
        'name' => $message->user->name,
        'avatar' => $message->user->avatar,
      ] : ($isSystem ? [
        'id' => 0,
        'name' => 'Automation',
        'avatar' => null,
      ] : null),
      'mentions' => $message->mentions
        ?->map(fn (PipelineBoardMessageMention $mention) => [
          'user_id' => $mention->user_id,
          'user' => $mention->user ? [
            'id' => $mention->user->id,
            'name' => $mention->user->name,
            'avatar' => $mention->user->avatar,
          ] : null,
        ])
        ->values()
        ->all() ?? [],
      'attachments' => $message->attachments
        ?->map(fn (PipelineBoardMessageAttachment $attachment) => $this->serializeAttachment($attachment))
        ->values()
        ->all() ?? [],
      'reactions' => $this->reactionSummary($message, $viewer),
      'can_edit' => ! $isSystem && $isAuthor,
      'can_delete' => ! $isSystem && ($isAuthor || $canModerate),
      'can_pin' => $canModerate,
    ];
  }

  public function logBoardActivity(
    PipelineBoard $board,
    User $actor,
    string $eventType,
    string $title,
    ?string $body = null,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $metadata = null,
  ): void {
    app(PipelineBoardActivityService::class)->log(
      $board,
      $actor,
      $eventType,
      $title,
      $body,
      $entityType,
      $entityId,
      $metadata,
    );
  }
}
