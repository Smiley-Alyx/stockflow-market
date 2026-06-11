<?php

namespace App\Http\Controllers\Api\Assistant;

use App\Domains\Assistant\Exceptions\AssistantConfigurationException;
use App\Domains\Assistant\Services\AssistantProviderManager;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class AssistantController extends Controller
{
    public function __invoke(Request $request, AssistantProviderManager $assistant): JsonResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'conversation_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            return response()->json([
                'data' => $assistant->respond(
                    message: $input['message'],
                    conversationId: $input['conversation_id'] ?? null,
                ),
            ]);
        } catch (AssistantConfigurationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'AI-ассистент временно недоступен.'], 503);
        }
    }
}
