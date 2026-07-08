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
use App\Services\ModuleAccessService;
use App\Services\PipelineService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PipelineBoardConversationService
{
  public function __construct(
    protected PipelineService $pipeline,
    protected ModuleAccessService $moduleAccess,
    protected PipelineCollaborationService $collaboration,
    protected PipelineNotificationService $notifier,
  ) {}

  /** @return array{messages_count: int, unread_count: int, has_unread: bool, pinned_count: int} */
  public function conversationSummary(int $businessId, User $user, int $boardId): array
  {
    $board = $this->pipeline->getBoard($businessId, $user, $boardId);
    $messages = $this->loadBoardMessages($board);
    $unreadCount = $this->unreadCountForUser($board, $user, $messages);

    return [
      'messages_count' => $messages->count(),
      'unread_count' => $unreadCount,
      'has_unread' => $unreadCount > 0,
      'pinned_count' => $messages->where('is_pinned', true)->count(),
    ];
  }

  /** @return list<array<string, mixed>> */
  public function listMessages(int $businessId, User $user, int $boardId): array
  {
    $board = $this->pipeline->getBoard($businessId, $user, $boardId);
    $messages = $this->loadBoardMessages($board);

    $serialized = $messages
      ->map(fn (PipelineBoardMessage $message) => $this->serializeMessage($message, $user, $board))
      ->values()
      ->all();

    usort($serialized, function (array $a, array $b) {
      $aPinned = ! empty($a['is_pinned']);
      $bPinned = ! empty($b['is_pinned']);
      if ($aPinned !== $bPinned) {
        return $bPinned <=> $aPinned;
      }

      return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
    });

    return $serialized;
  }

  /** @return array<string, mixed> */
  public function storeMessage(
    int $businessId,
    User $user,
    int $boardId,
    string $body,
    ?int $parentId = null,
    bool $isSystem = false,
  ): array {
    $board = $this->pipeline->getBoard($businessId, $user, $boardId);
    if (! $isSystem) {
      $this->pipeline->ensureCanEditBoard($user, $board);
    }

    $trimmed = trim($body);
    if ($trimmed === '') {
      abort(422, 'Message cannot be empty.');
    }

    if ($parentId !== null) {
      $parent = PipelineBoardMessage::query()
        ->where('business_id', $businessId)
        ->where('board_id', $board->id)
        ->whereKey($parentId)
        ->firstOrFail();

      if ($parent->parent_id !== null) {
        abort(422, 'Replies cannot be nested further — reply to the main message instead.');
      }
    }

    $message = PipelineBoardMessage::create([
      'business_id' => $businessId,
      'board_id' => $board->id,
      'user_id' => $user->id,
      'parent_id' => $parentId,
      'body' => $trimmed,
    ]);

    $mentionedUsers = $this->syncMentions($message, $board, $user);
    $this->markConversationRead($businessId, $user, $boardId, (int) $message->id);

    $serialized = $this->serializeMessage($this->reloadMessage($message), $user, $board);

    if (! $isSystem) {
      $recipients = $this->messageNotificationRecipients($board, $user, $parentId);
      $this->notifier->notifyBoardMessage(
        $board,
        $user,
        $trimmed,
        $recipients,
        $parentId !== null,
      );

      if ($mentionedUsers !== []) {
        $this->notifier->notifyBoardMention($board, $user, $trimmed, $mentionedUsers);
      }
    }

    $this->logBoardActivity(
      $board,
      $user,
      'message',
      $isSystem ? 'Automation posted to conversation' : 'New board message',
      $trimmed,
      'message',
      (int) $message->id,
    );

    return $serialized;
  }

  /** @return array<string, mixed> */
  public function storeSystemMessage(int $businessId, User $user, int $boardId, string $body): array
  {
    return $this->storeMessage($businessId, $user, $boardId, $body, null, true);
  }

  /** @return array<string, mixed> */
  public function updateMessage(int $businessId, User $user, int $messageId, string $body): array
  {
    $message = $this->findMessageForBusiness($businessId, $messageId);
    $board = $this->pipeline->getBoard($businessId, $user, (int) $message->board_id);
    $this->assertCanEditMessage($user, $message, $board);

    $trimmed = trim($body);
    if ($trimmed === '') {
      abort(422, 'Message cannot be empty.');
    }

    $message->update([
      'body' => $trimmed,
      'edited_at' => now(),
    ]);

    $mentionedUsers = $this->syncMentions($message, $board, $user);
    if ($mentionedUsers !== []) {
      $this->notifier->notifyBoardMention($board, $user, $trimmed, $mentionedUsers);
    }

    return $this->serializeMessage($this->reloadMessage($message), $user, $board);
  }

  public function deleteMessage(int $businessId, User $user, int $messageId): void
  {
    $message = $this->findMessageForBusiness($businessId, $messageId);
    $board = $this->pipeline->getBoard($businessId, $user, (int) $message->board_id);
    $this->assertCanDeleteMessage($user, $message, $board);

    $replyIds = PipelineBoardMessage::query()
      ->where('parent_id', $message->id)
      ->pluck('id');

    foreach ($replyIds->merge([$message->id]) as $id) {
      $attachments = PipelineBoardMessageAttachment::query()->where('message_id', $id)->get();
      foreach ($attachments as $attachment) {
        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();
      }
    }

    PipelineBoardMessage::query()
      ->where('parent_id', $message->id)
      ->delete();

    $message->delete();
  }

  /** @return array<string, mixed> */
  public function togglePin(int $businessId, User $user, int $messageId): array
  {
    $message = $this->findMessageForBusiness($businessId, $messageId);
    $board = $this->pipeline->getBoard($businessId, $user, (int) $message->board_id);
    $this->pipeline->userCanManageBoard($user, $board) || abort(403, 'Only board managers can pin messages.');

    $nextPinned = ! $message->is_pinned;
    $message->update([
      'is_pinned' => $nextPinned,
      'pinned_at' => $nextPinned ? now() : null,
      'pinned_by' => $nextPinned ? $user->id : null,
    ]);

    if ($nextPinned) {
      $this->logBoardActivity(
        $board,
        $user,
        'message_pinned',
        'Pinned a conversation message',
        $message->body,
        'message',
        (int) $message->id,
      );
    }

    return $this->serializeMessage($this->reloadMessage($message), $user, $board);
  }

  /** @return array<string, mixed> */
  public function uploadAttachment(
    int $businessId,
    User $user,
    int $messageId,
    UploadedFile $file,
  ): array {
    $message = $this->findMessageForBusiness($businessId, $messageId);
    $board = $this->pipeline->getBoard($businessId, $user, (int) $message->board_id);
    $this->pipeline->ensureCanEditBoard($user, $board);

    if ((int) $message->user_id !== (int) $user->id && ! $this->pipeline->userCanManageBoard($user, $board)) {
      abort(403, 'You cannot attach files to this message.');
    }

    $path = $file->store('pipeline-board-conversation', 'public');
    $attachment = PipelineBoardMessageAttachment::create([
      'message_id' => $message->id,
      'user_id' => $user->id,
      'file_name' => $file->getClientOriginalName(),
      'file_path' => $path,
      'mime_type' => $file->getClientMimeType() ?: $file->getMimeType(),
      'file_size' => $file->getSize(),
    ]);

    $this->logBoardActivity(
      $board,
      $user,
      'message_attachment',
      'Added attachment to conversation',
      $attachment->file_name,
      'message',
      (int) $message->id,
    );

    return $this->serializeAttachment($attachment);
  }

  public function deleteAttachment(int $businessId, User $user, int $attachmentId): void
  {
    $attachment = PipelineBoardMessageAttachment::query()
      ->whereKey($attachmentId)
      ->with('message')
      ->firstOrFail();

    $message = $attachment->message;
    if (! $message) {
      abort(404);
    }

    $board = $this->pipeline->getBoard($businessId, $user, (int) $message->board_id);
    if ((int) $attachment->user_id !== (int) $user->id
      && (int) $message->user_id !== (int) $user->id
      && ! $this->pipeline->userCanManageBoard($user, $board)
      && ! $this->moduleAccess->isBusinessOwner($user)) {
      abort(403, 'You cannot delete this attachment.');
    }

    Storage::disk('public')->delete($attachment->file_path);
    $attachment->delete();
  }

  /** @return array<string, mixed> */
  public function toggleReaction(int $businessId, User $user, int $messageId, ?string $reaction): array
  {
    $message = $this->findMessageForBusiness($businessId, $messageId);
    $this->pipeline->getBoard($businessId, $user, (int) $message->board_id);

    $existing = PipelineBoardMessageReaction::query()
      ->where('message_id', $message->id)
      ->where('user_id', $user->id)
      ->first();

    if ($reaction === null || $reaction === '') {
      $existing?->delete();
    } elseif (! $this->isValidReaction($reaction)) {
      abort(422, 'Invalid reaction.');
    } elseif ($existing && $existing->reaction === $reaction) {
      $existing->delete();
    } elseif ($existing) {
      $existing->update(['reaction' => $reaction]);
    } else {
      PipelineBoardMessageReaction::create([
        'message_id' => $message->id,
        'user_id' => $user->id,
        'reaction' => $reaction,
      ]);
    }

    return $this->reactionSummary($message, $user);
  }

  /** @return array{last_read_message_id: int|null, unread_count: int} */
  public function markConversationRead(
    int $businessId,
    User $user,
    int $boardId,
    ?int $lastMessageId = null,
  ): array {
    $board = $this->pipeline->getBoard($businessId, $user, $boardId);

    if ($lastMessageId !== null && $lastMessageId < 1) {
      abort(422, 'Invalid message id.');
    }

    $latestId = $lastMessageId ?? (int) PipelineBoardMessage::query()
      ->where('board_id', $board->id)
      ->max('id');

    PipelineBoardConversationRead::updateOrCreate(
      ['board_id' => $board->id, 'user_id' => $user->id],
      [
        'last_read_message_id' => $latestId > 0 ? $latestId : null,
        'last_read_at' => now(),
      ],
    );

    $messages = $this->loadBoardMessages($board);

    return [
      'last_read_message_id' => $latestId > 0 ? $latestId : null,
      'unread_count' => $this->unreadCountForUser($board, $user, $messages),
    ];
  }

  protected function findMessageForBusiness(int $businessId, int $messageId): PipelineBoardMessage
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
  protected function loadBoardMessages(PipelineBoard $board): Collection
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

  protected function reloadMessage(PipelineBoardMessage $message): PipelineBoardMessage
  {
    return $message->fresh([
      'user:id,name,avatar',
      'reactions:id,message_id,user_id,reaction',
      'mentions.user:id,name,avatar',
      'attachments',
      'pinnedByUser:id,name,avatar',
    ]) ?? $message;
  }

  protected function assertCanEditMessage(User $user, PipelineBoardMessage $message, PipelineBoard $board): void
  {
    if ((int) $message->user_id !== (int) $user->id) {
      abort(403, 'You can only edit your own messages.');
    }

    $this->pipeline->getBoard((int) $board->business_id, $user, (int) $board->id);
  }

  protected function assertCanDeleteMessage(User $user, PipelineBoardMessage $message, PipelineBoard $board): void
  {
    $isAuthor = (int) $message->user_id === (int) $user->id;
    // Author or board manager/owner only — contributors cannot delete others' messages.
    if ($isAuthor || $this->pipeline->userCanManageBoard($user, $board)) {
      return;
    }

    abort(403, 'You can only delete your own messages or moderate as a board manager.');
  }

  /** @param  Collection<int, PipelineBoardMessage>  $messages */
  protected function unreadCountForUser(PipelineBoard $board, User $user, Collection $messages): int
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
  protected function messageNotificationRecipients(
    PipelineBoard $board,
    User $actor,
    ?int $parentId,
  ): array {
    $recipients = collect($this->collaboration->boardRecipientsForNotifications($board, $actor));

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
  protected function syncMentions(PipelineBoardMessage $message, PipelineBoard $board, User $actor): array
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
  protected function parseMentionIds(string $body): array
  {
    preg_match_all('/@\[(\d+)\]/', $body, $matches);

    return collect($matches[1] ?? [])
      ->map(fn ($id) => (int) $id)
      ->filter(fn ($id) => $id > 0)
      ->unique()
      ->values()
      ->all();
  }

  protected function isValidReaction(string $reaction): bool
  {
    if (in_array($reaction, ['like', 'dislike'], true)) {
      return true;
    }

    return mb_strlen($reaction) <= 8 && preg_match('/^\X$/u', $reaction) === 1;
  }

  /** @return array{likes: int, dislikes: int, user_reaction: string|null, emoji_counts: array<string, int>} */
  protected function reactionSummary(PipelineBoardMessage $message, User $viewer): array
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
  protected function serializeAttachment(PipelineBoardMessageAttachment $attachment): array
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
  protected function serializeMessage(PipelineBoardMessage $message, User $viewer, PipelineBoard $board): array
  {
    $isAuthor = (int) $message->user_id === (int) $viewer->id;
    $canModerate = $this->pipeline->userCanManageBoard($viewer, $board);

    return [
      'id' => $message->id,
      'board_id' => $message->board_id,
      'parent_id' => $message->parent_id,
      'user_id' => $message->user_id,
      'body' => $message->body,
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
      ] : null,
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
      'can_edit' => $isAuthor,
      // Author or board manager/owner only — collaborators cannot delete others.
      'can_delete' => $isAuthor || $canModerate,
      'can_pin' => $canModerate,
    ];
  }

  protected function logBoardActivity(
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
