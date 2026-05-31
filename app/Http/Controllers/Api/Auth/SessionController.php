<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domains\Customers\Services\CustomerStateService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class SessionController extends Controller
{
    public function csrf(): JsonResponse
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function show(Request $request, CustomerStateService $customers): JsonResponse
    {
        return response()->json([
            'data' => $this->sessionPayload($request->user(), $customers),
        ]);
    }

    public function register(Request $request, CustomerStateService $customers): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::query()->create($payload);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => $this->sessionPayload($user, $customers),
            'csrf_token' => csrf_token(),
        ], 201);
    }

    public function login(Request $request, CustomerStateService $customers): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials.'], 422);
        }

        $request->session()->regenerate();

        return response()->json([
            'data' => $this->sessionPayload($request->user(), $customers),
            'csrf_token' => csrf_token(),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(?User $user, CustomerStateService $customers): array
    {
        if ($user === null) {
            return ['user' => null, 'customer_state' => null];
        }

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'customer_state' => $customers->state($user),
        ];
    }
}
