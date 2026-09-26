<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Assistant\AssistantChatService;
use App\Services\Assistant\AssistantException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function __construct(private AssistantChatService $chat) {}

    public function chat(Request $request): JsonResponse
    {
        $messages = $this->validatedMessages($request);

        $user = $request->user();
        $businessId = (int) $user->business_id;
        $businessName = (string) ($user->business->name ?? 'your business');

        return $this->answer($businessId, $businessName, $messages);
    }

    /** Guest how-to answers for landing/auth pages - no business data. */
    public function guide(Request $request): JsonResponse
    {
        return $this->answer(null, 'Custosell ERP', $this->validatedMessages($request));
    }

    /** @return list<array{role: string, content: string}> */
    private function validatedMessages(Request $request): array
    {
        $maxMessages = (int) config('assistant.max_messages', 20);
        $maxChars = (int) config('assistant.max_chars_per_message', 2000);

        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:'.$maxMessages],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'min:1', 'max:'.$maxChars],
        ]);

        return $validated['messages'];
    }

    /** @param list<array{role: string, content: string}> $messages */
    private function answer(?int $businessId, string $businessName, array $messages): JsonResponse
    {
        try {
            $reply = $this->chat->reply($businessId, $businessName, $messages);
        } catch (AssistantException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['reply' => $reply]]);
    }
}
