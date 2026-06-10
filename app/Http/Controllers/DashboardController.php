<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $workspace = $request->user()
            ->workspaces()
            ->with(['trial', 'botSetting', 'facebookPage'])
            ->firstOrFail();

        return view('dashboard', [
            'workspace' => $workspace,
            'trial' => $workspace->trial,
            'botSetting' => $workspace->botSetting,
            'facebookConnected' => $workspace->facebookPage !== null,
            'facebookPage' => $workspace->facebookPage,
            'googleConnected' => $workspace->googleAccount()->exists(),
        ]);
    }
}
