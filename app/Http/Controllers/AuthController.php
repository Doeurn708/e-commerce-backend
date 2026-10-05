<?php

namespace App\Http\Controllers;

use App\Models\Otp;
use App\Models\User;
use App\Services\CloudinaryImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /** Avatar uploads go to their own folder. */
    private const AVATAR_FOLDER = 'avatars';

    public function __construct(private CloudinaryImageStore $images) {}
    public function register(Request $request){
        $validated = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','unique:users,email'],
            'password' => ['required','string','min:8','confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Register successfully',
            'user' => $user,
            'token' => $token,
        ],201);
    }

    public function login (Request $request){
        $validated = $request->validate([
            'email' => ['required','email'],
            'password' => ['required','string'],
        ]);

        $user = User::where('email',$validated['email'])->first();

        if(!$user || !Hash::check($validated['password'], $user->password)){
            return response() ->json([
                'message' => "Invalid email or password "
            ],401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'login successfully',
            'user' => $user,
            'token' => $token
        ]);
    }

    // POST /api/reset-password
    public function resetPassword(Request $request){
        $validated = $request->validate([
            'email' => ['required','email','exists:users,email'],
            'password' => ['required','string','min:8','confirmed'],
            'otp' => ['required','digits:6'],
        ]);

        // the reset only goes through with a verified, unexpired code for this email
        $verified = Otp::where('email', $validated['email'])
            ->where('otp', $validated['otp'])
            ->where('verified', true)
            ->where('expires_at', '>', now())
            ->exists();

        if (! $verified) {
            return response()->json([
                'message' => 'Invalid or expired verification code',
            ],422);
        }

        $user = User::where('email', $validated['email'])->firstOrFail();

        $user->password = Hash::make($validated['password']);
        $user->setRememberToken(Str::random(60));
        $user->save();

        // the OTP cannot be replayed
        Otp::where('email', $validated['email'])->delete();

        return response()->json([
            'message' => 'Password reset successfully',
        ]);
    }

    // GET /api/user
    public function user(Request $request){
        return response()->json([
            'user' => $this->presentUser($request->user())
        ]);
    }

    // PATCH|PUT /api/user/profile
    public function updateProfile(Request $request){
        $user = $request->user();

        // `sometimes` so the modal can send just the avatar, and email/role are
        // intentionally absent: changing them needs its own verified flow.
        $validated = $request->validate([
            'name' => ['sometimes','required','string','max:255'],
            'phone' => ['sometimes','nullable','string','max:30'],
            'remove_avatar' => ['sometimes','boolean'],
        ]);

        foreach (['name', 'phone'] as $field) {
            if (array_key_exists($field, $validated)) {
                $user->{$field} = $validated[$field];
            }
        }

        // resolve() keeps the current value when no file is sent, and stores an
        // uploaded one on Cloudinary.
        $previousAvatar = $user->avatar;
        $wantsRemoval = $request->boolean('remove_avatar');
        $avatar = $wantsRemoval
            ? null
            : $this->images->resolveUrl($request, 'avatar', self::AVATAR_FOLDER, $user->avatar);

        if ($avatar !== $previousAvatar) {
            $user->avatar = $avatar;

            // Destroy through the API, not the local disk: the old asset lives
            // in the cloud and would be orphaned there forever otherwise.
            if (filled($previousAvatar)) {
                $this->images->delete($previousAvatar);
            }
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $this->presentUser($user),
        ]);
    }

    /**
     * Shape the user for the SPA: adds the order count the profile header shows
     * so it can be a real number instead of a hardcoded one.
     */
    private function presentUser(User $user): User
    {
        // fresh() drops the previously loaded relations, so the count has to be
        // re-applied on the returned instance or orders_count disappears.
        return $user->fresh(['orders'])->loadCount('orders');
    }

    // POST /api/logout
    public function logout(Request $request){
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }
}
