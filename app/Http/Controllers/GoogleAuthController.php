<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Throwable;

class GoogleAuthController extends Controller
{
    /**
     * Resolve the Google driver as its concrete provider so fluent
     * methods such as stateless() are known to static analysis.
     */
    private function driver(): GoogleProvider
    {
        return Socialite::driver('google');
    }

    /**
     * Redirect the user to Google's login page.
     */
    public function redirect(): RedirectResponse|JsonResponse
    {
        // Without a client id the request reaches Google with no client_id and
        // Google answers with an "invalid_request" screen and no way back to the
        // SPA, which looks like the button "does nothing". Fail fast instead,
        // using the same response shape as a failed callback so the SPA shows a
        // real message.
        if (! $this->isConfigured()) {
            return $this->respond([
                'error' => 'Google sign-in is not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.',
            ], 503);
        }

        return $this->driver()->stateless()->redirect();
    }

    /**
     * Whether the Google OAuth client credentials are present.
     */
    private function isConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'));
    }

    /**
     * Handle Google's callback and create a Sanctum token.
     */
    public function callback(): JsonResponse|RedirectResponse
    {
        try {
            // Get the authenticated Google user.
            $googleUser = $this->driver()->stateless()->user();

            $googleId = $googleUser->getId();
            $email = $googleUser->getEmail();

            // Ensure Google returned the required account details.
            if (! $googleId || ! $email) {
                return response()->json([
                    'message' => 'Google did not provide a valid ID or email.',
                ], 422);
            }

            // Find an account by Google ID or email.
            $user = User::where('google_id', $googleId)
                ->orWhere('email', $email)
                ->first();

            // Create a new account if one does not exist.
            if (! $user) {
                $user = User::create([
                    'name' => $googleUser->getName() ?: 'Google User',
                    'email' => $email,
                    'google_id' => $googleId,
                    'email_verified_at' => now(),
                    'password' => Hash::make(Str::random(40)),
                    'role' => 'customer',
                    'is_active' => true,
                ]);
            } elseif ($user->google_id && $user->google_id !== $googleId) {
                // The email already belongs to a different Google account.
                return response()->json([
                    'message' => 'This email is already linked to another Google account.',
                ], 409);
            } elseif (! $user->google_id) {
                // Link the Google ID to an existing account.
                $user->google_id = $googleId;
                $user->email_verified_at = $user->email_verified_at ?? now();
                $user->save();
            }

            // Prevent inactive accounts from logging in.
            if (! $user->is_active) {
                return response()->json([
                    'message' => 'Your account is inactive. Please contact support.',
                ], 403);
            }

            // Create a Laravel Sanctum API token.
            $token = $user->createToken('google-login')->plainTextToken;

            $payload = [
                'message' => 'Google login successful',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'avatar' => $googleUser->getAvatar(),
                ],
                'token' => $token,
                'token_type' => 'Bearer',
            ];

            return $this->respond($payload, 200);
        } catch (Throwable $e) {
            report($e);

            return $this->respond([
                'error' => 'Google login failed. Please try again.',
            ], 500);
        }
    }

    /**
     * Return JSON to API clients, but send browsers back to the SPA so the
     * user never lands on a raw JSON body.
     */
    private function respond(array $payload, int $status): JsonResponse|RedirectResponse
    {
        if (request()->expectsJson()) {
            return response()->json($payload, $status);
        }

        $url = rtrim((string) config('services.google.frontend_url'), '/').'/auth/google/callback';

        $query = $payload;
        if (array_key_exists('user', $query) && is_array($query['user'])) {
            $query['user'] = json_encode($query['user'], JSON_UNESCAPED_UNICODE);
        }
        $query = array_map(
            static fn ($value): string => is_scalar($value) ? (string) $value : '',
            $query
        );

        return redirect()->away($url.'?'.http_build_query($query));
    }
}
