<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingMessage;
use App\Models\FacebookPage;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FacebookWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.facebook.verify_token');
        $provided = (string) $request->query('hub_verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && $expected !== ''
            && hash_equals($expected, $provided)) {
            return response((string) $request->query('hub_challenge'), 200);
        }

        return response('', 403);
    }

    public function receive(Request $request): Response
    {
        if (! $this->signatureValid($request)) {
            return response('', 403);
        }

        foreach ($request->input('entry', []) as $entry) {
            $pageId = $entry['id'] ?? null;

            if ($pageId === null) {
                continue;
            }

            $page = FacebookPage::with('workspace.botSetting', 'workspace.trial', 'workspace.subscriptions')
                ->where('page_id', $pageId)
                ->first();

            if ($page === null) {
                continue;
            }

            foreach ($entry['messaging'] ?? [] as $event) {
                $text = $event['message']['text'] ?? null;
                $mid = $event['message']['mid'] ?? null;
                $senderId = $event['sender']['id'] ?? null;

                if ($text === null || $mid === null || $senderId === null) {
                    continue;
                }

                $ledger = WebhookEvent::firstOrCreate(
                    ['event_id' => $mid],
                    ['provider' => 'facebook'],
                );

                if (! $ledger->wasRecentlyCreated) {
                    continue;
                }

                $workspace = $page->workspace;

                if (! $workspace->botSetting?->is_enabled || ! $workspace->canUseBot()) {
                    continue;
                }

                ProcessIncomingMessage::dispatch($workspace->id, 'facebook', $senderId, $text);
            }
        }

        return response('', 200);
    }

    private function signatureValid(Request $request): bool
    {
        $header = $request->header('X-Hub-Signature-256');

        if (! is_string($header) || $header === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) config('services.facebook.client_secret'));

        return hash_equals($expected, $header);
    }
}
