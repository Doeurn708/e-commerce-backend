<?php

namespace App\Http\Controllers;

use App\Models\Otp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class OtpController extends Controller
{
    public function send(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Generate 6-digit OTP
        $otp = random_int(100000, 999999);

        // Delete old OTP
        Otp::where('email', $request->email)->delete();

        // Store OTP
        Otp::create([
            'email' => $request->email,
            'otp' => $otp,
            'expires_at' => now()->addMinutes(5),
        ]);

        // Send email
        Mail::raw(
            "Your OTP code is: {$otp}. This code will expire in 5 minutes.",
            function ($message) use ($request) {
                $message->to($request->email)
                    ->subject('Your OTP Code');
            }
        );

        return response()->json([
            'message' => 'OTP sent successfully.',
        ]);
    }

    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp' => 'required|digits:6',
        ]);

        $otp = Otp::where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('verified', false)
            ->first();

        if (!$otp) {
            return response()->json([
                'message' => 'Invalid OTP.',
            ], 422);
        }

        if (now()->greaterThan($otp->expires_at)) {
            return response()->json([
                'message' => 'OTP has expired.',
            ], 422);
        }

        $otp->update([
            'verified' => true,
        ]);

        return response()->json([
            'message' => 'OTP verified successfully.',
        ]);
    }
}
