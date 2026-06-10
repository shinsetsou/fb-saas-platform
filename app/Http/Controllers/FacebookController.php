<?php

namespace App\Http\Controllers;

use App\Services\Facebook\FacebookGraph;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class FacebookController extends Controller
{
    public function __construct(private FacebookGraph $graph) {}

    public function connect(): RedirectResponse
    {
        return Socialite::driver('facebook')
            ->scopes(['pages_show_list', 'pages_messaging', 'pages_manage_metadata'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->query('error') || ! $request->query('code')) {
            return redirect()->route('dashboard')->with('status', 'facebook-connect-failed');
        }

        try {
            $facebookUser = Socialite::driver('facebook')->user();

            $longLivedUserToken = $this->graph->exchangeForLongLivedToken($facebookUser->token);
            $pages = $this->graph->pages($longLivedUserToken);

            if ($pages === []) {
                return redirect()->route('dashboard')->with('status', 'facebook-connect-failed');
            }

            $page = $pages[0];

            $this->graph->subscribeApp($page['id'], $page['access_token']);

            $workspace = $request->user()->workspaces()->firstOrFail();
            $workspace->facebookPage()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'page_id' => $page['id'],
                    'page_name' => $page['name'],
                    'page_access_token' => $page['access_token'],
                    'user_access_token' => $longLivedUserToken,
                    'subscribed_at' => now(),
                ],
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('dashboard')->with('status', 'facebook-connect-failed');
        }

        return redirect()->route('dashboard')->with('status', 'facebook-connected');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->firstOrFail();
        $workspace->facebookPage()->delete();

        return redirect()->route('dashboard')->with('status', 'facebook-disconnected');
    }
}
