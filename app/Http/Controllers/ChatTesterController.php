<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatTesterController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $request->user()->workspaces()->with('botSetting')->firstOrFail();

        $conversation = $workspace->conversations()
            ->where('channel', 'web')
            ->where('external_participant_id', $this->participantId($request))
            ->with('messages')
            ->first();

        return view('chat-tester.index', [
            'workspace' => $workspace,
            'settings' => $workspace->botSetting,
            'messages' => $conversation
                ? $conversation->messages()->whereIn('role', ['user', 'assistant'])->orderBy('id')->get()
                : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $workspace = $request->user()->workspaces()->firstOrFail();

        // Dispatch synchronously so the reply is ready on redirect.
        ProcessIncomingMessage::dispatchSync(
            $workspace->id,
            'web',
            $this->participantId($request),
            $validated['message'],
        );

        return redirect()->route('chat-tester.index');
    }

    private function participantId(Request $request): string
    {
        return 'tester-'.$request->user()->id;
    }
}
