<?php

namespace App\Http\Controllers\Api\Assistant;

use App\Domains\Assistant\Services\OpenAiShoppingAssistant;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class OpenAiAssistantController extends Controller
{
    public function __invoke(Request $request, OpenAiShoppingAssistant $assistant): JsonResponse
    {
        $input = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
            'previous_response_id' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            return response()->json([
                'data' => $assistant->respond(
                    message: $input['message'],
                    previousResponseId: $input['previous_response_id'] ?? null,
                ),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => config('services.openai.api_key')
                    ? 'AI-ассистент временно недоступен.'
                    : 'Для AI-ассистента не настроен OPENAI_API_KEY.',
            ], 503);
        }
    }
}
