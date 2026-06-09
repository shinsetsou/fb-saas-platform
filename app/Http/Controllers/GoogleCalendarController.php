<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class GoogleCalendarController extends Controller
{
    public function connect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar.readonly',
            ])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('dashboard')->with('status', 'google-connect-failed');
        }

        $googleUser = Socialite::driver('google')->user();
        $workspace = $request->user()->workspaces()->firstOrFail();

        $workspace->googleAccount()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'access_token' => $googleUser->token,
                'refresh_token' => $googleUser->refreshToken ?? $workspace->googleAccount?->refresh_token ?? '',
                'expires_at' => Carbon::now()->addSeconds((int) ($googleUser->expiresIn ?? 3600)),
                'calendar_id' => 'primary',
            ],
        );

        return redirect()->route('dashboard')->with('status', 'google-connected');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->firstOrFail();
        $workspace->googleAccount()->delete();

        return redirect()->route('dashboard')->with('status', 'google-disconnected');
    }
}
