<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineBoard;
use App\Models\PipelineBoardConversationRead;
use App\Models\PipelineBoardMessage;
use App\Models\PipelineBoardMessageAttachment;
use App\Models\PipelineBoardMessageReaction;
use App\Models\User;
use App\Services\ModuleAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PipelineBoardConversationService
{
  public function __construct(
    protected PipelineBoardService $boards,
    protected PipelineBoardPermissionService $permission,
    protected ModuleAccessService $moduleAccess,
    protected PipelineNotificationService $notifier,
    protected PipelineBoardConversationPresenter $presenter,
  ) {}

  /** @return array{messages_count: int, unread_count: int, has_unread: bool, pinned_count: int} */
  public function conversationSummary(int $businessId, User $user, int $boardId): array
  {
    $board = $this->boards->getBoard($businessId, $user, $boardId);
    $messages = $this->presenter->loadBoardMessages($board);
    $unreadCount = $this->presenter->unreadCountForUser($board, $user, $messages);

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
    $board = $this->boards->getBoard($businessId, $user, $boardId);
    $messages = $this->presenter->loadBoardMessages($board);

    $serialized = $messages
      ->map(fn (PipelineBoardMessage $message) => $this->presenter->serializeMessage($message, $user, $board))
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
    $board = $this->boards->getBoard($businessId, $user, $boardId);
    if (! $isSystem) {
      $this->permission->ensureCanEditBoard($user, $board);
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
        abort(422, 'Replies cannot be nested further - reply to the main message instead.');
      }
    }

    $message = PipelineBoardMessage::create([
      'business_id' => $businessId,
      'board_id' => $board->id,
      'user_id' => $user->id,
      'parent_id' => $parentId,
      'body' => $trimmed,
      'is_system' => $isSystem,
    ]);

    $mentionedUsers = $this->presenter->syncMentions($message, $board, $user);
    $this->markConversationRead($businessId, $user, $boardId, (int) $message->id);

    $serialized = $this->presenter->serializeMessage($this->presenter->reloadMessage($message), $user, $board);

    if (! $isSystem) {
      $recipients = $this->presenter->messageNotificationRecipients($board, $user, $parentId);
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

    $this->presenter->logBoardActivity(
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
    $message = $this->presenter->findMessageForBusiness($businessId, $messageId);
    $board = $this->boards->getBoard($businessId, $user, (int) $message->board_id);
    $this->presenter->assertCanEditMessage($user, $message, $board);

    $trimmed = trim($body);
    if ($trimmed === '') {
      abort(422, 'Message cannot be empty.');
    }

    $message->update([
      'body' => $trimmed,
      'edited_at' => now(),
    ]);

    $mentionedUsers = $this->presenter->syncMentions($message, $board, $user);
    if ($mentionedUsers !== []) {
      $this->notifier->notifyBoardMention($board, $user, $trimmed, $mentionedUsers);
    }

    return $this->presenter->serializeMessage($this->presenter->reloadMessage($message), $user, $board);
  }

  public function deleteMessage(int $businessId, User $user, int $messageId): void
  {
    $message = $this->presenter->findMessageForBusiness($businessId, $messageId);
    $board = $this->boards->getBoard($businessId, $user, (int) $message->board_id);
    $this->presenter->assertCanDeleteMessage($user, $message, $board);

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
    $message = $this->presenter->findMessageForBusiness($businessId, $messageId);
    $board = $this->boards->getBoard($businessId, $user, (int) $message->board_id);
    $this->permission->userCanManageBoard($user, $board) || abort(403, 'Only board managers can pin messages.');

    $nextPinned = ! $message->is_pinned;
    if ($nextPinned) {
      PipelineBoardMessage::query()
        ->where('board_id', $board->id)
        ->whereKeyNot($message->id)
        ->where('is_pinned', true)
        ->update([
          'is_pinned' => false,
          'pinned_at' => null,
          'pinned_by' => null,
        ]);
    }

    $message->update([
      'is_pinned' => $nextPinned,
      'pinned_at' => $nextPinned ? now() : null,
      'pinned_by' => $nextPinned ? $user->id : null,
    ]);

    if ($nextPinned) {
      $this->presenter->logBoardActivity(
        $board,
        $user,
        'message_pinned',
        'Pinned a conversation message',
        $message->body,
        'message',
        (int) $message->id,
      );
    }

    return $this->presenter->serializeMessage($this->presenter->reloadMessage($message), $user, $board);
  }

  /** @return array<string, mixed> */
  public function uploadAttachment(
    int $businessId,
    User $user,
    int $messageId,
    UploadedFile $file,
  ): array {
    $message = $this->presenter->findMessageForBusiness($businessId, $messageId);
    $board = $this->boards->getBoard($businessId, $user, (int) $message->board_id);
    $this->permission->ensureCanEditBoard($user, $board);

    if ((int) $message->user_id !== (int) $user->id && ! $this->permission->userCanManageBoard($user, $board)) {
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

    $this->presenter->logBoardActivity(
      $board,
      $user,
      'message_attachment',
      'Added attachment to conversation',
      $attachment->file_name,
      'message',
      (int) $message->id,
    );

    return $this->presenter->serializeAttachment($attachment);
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

    $board = $this->boards->getBoard($businessId, $user, (int) $message->board_id);
    if ((int) $attachment->user_id !== (int) $user->id
      && (int) $message->user_id !== (int) $user->id
      && ! $this->permission->userCanManageBoard($user, $board)
      && ! $this->moduleAccess->isBusinessOwner($user)) {
      abort(403, 'You cannot delete this attachment.');
    }

    Storage::disk('public')->delete($attachment->file_path);
    $attachment->delete();
  }

  /** @return array<string, mixed> */
  public function toggleReaction(int $businessId, User $user, int $messageId, ?string $reaction): array
  {
    $message = $this->presenter->findMessageForBusiness($businessId, $messageId);
    $board = $this->boards->getBoard($businessId, $user, (int) $message->board_id);
    $this->permission->ensureCanContributeToBoard($user, $board);

    $existing = PipelineBoardMessageReaction::query()
      ->where('message_id', $message->id)
      ->where('user_id', $user->id)
      ->first();

    if ($reaction === null || $reaction === '') {
      $existing?->delete();
    } elseif (! $this->presenter->isValidReaction($reaction)) {
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

    return $this->presenter->reactionSummary($message, $user);
  }

  /** @return array{last_read_message_id: int|null, unread_count: int} */
  public function markConversationRead(
    int $businessId,
    User $user,
    int $boardId,
    ?int $lastMessageId = null,
  ): array {
    $board = $this->boards->getBoard($businessId, $user, $boardId);

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

    $messages = $this->presenter->loadBoardMessages($board);

    return [
      'last_read_message_id' => $latestId > 0 ? $latestId : null,
      'unread_count' => $this->presenter->unreadCountForUser($board, $user, $messages),
    ];
  }
}
