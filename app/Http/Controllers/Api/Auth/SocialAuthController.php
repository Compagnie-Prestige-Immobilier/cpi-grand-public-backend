<?php

namespace App\Http\Controllers\Api\Auth;

use App\Dto\UserData;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * GET /auth/google/redirect — renvoie l'URL de redirection Google.
     */
    public function redirectToGoogle(): JsonResponse
    {
        if (! $this->googleConfigured()) {
            return $this->googleNotConfigured();
        }

        /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
        $driver = Socialite::driver('google');

        $url = $driver
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * POST /auth/google/callback — échange le code contre l'utilisateur Google,
     * trouve ou crée l'utilisateur local, renvoie token + UserData.
     */
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        if (! $this->googleConfigured()) {
            return $this->googleNotConfigured();
        }

        try {
            /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
            $driver = Socialite::driver('google');
            $googleUser = $driver->stateless()->user();
        } catch (\Exception) {
            return response()->json(['message' => "Échec de l'authentification Google."], 422);
        }

        // Find or create user
        $user = User::query()->where('email', $googleUser->getEmail())->first();
        if (! $user) {
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $googleUser->getEmail(),
                // no password — the column is nullable for OAuth users (STEP 8.1)
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'needs_onboarding' => true,   // must complete profile
            ]);
            $user->assignRole('client');

            // Create associated Client record (minimal — will be completed via onboarding)
            Client::create([
                'user_id' => $user->id,
                'name' => $user->name,
                'ref' => Client::generateRef(),
                'email' => $user->email,
                'date_inscription' => now(),
            ]);
        }

        $token = $user->createToken('client-token')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => UserData::from($user),
                'role' => $user->getRoleNames()->first(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'token' => $token,
            ],
        ]);
    }

    private function googleConfigured(): bool
    {
        return (bool) config('services.google.client_id')
            && (bool) config('services.google.client_secret');
    }

    private function googleNotConfigured(): JsonResponse
    {
        return response()->json(['message' => "Google OAuth n'est pas configuré."], 503);
    }
}
