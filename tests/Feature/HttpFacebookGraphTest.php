<?php

namespace Tests\Feature;

use App\Services\Facebook\HttpFacebookGraph;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpFacebookGraphTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.facebook.graph_version', 'v21.0');
    }

    public function test_exchange_for_long_lived_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['access_token' => 'long-lived-token']),
        ]);

        $token = app(HttpFacebookGraph::class)->exchangeForLongLivedToken('short-token');

        $this->assertSame('long-lived-token', $token);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v21.0/oauth/access_token')
            && str_contains($request->url(), 'grant_type=fb_exchange_token')
            && str_contains($request->url(), 'fb_exchange_token=short-token'));
    }

    public function test_pages_returns_managed_pages(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Page One', 'access_token' => 'pt-1'],
            ]]),
        ]);

        $pages = app(HttpFacebookGraph::class)->pages('user-token');

        $this->assertSame([['id' => '111', 'name' => 'Page One', 'access_token' => 'pt-1']], $pages);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v21.0/me/accounts'));
    }

    public function test_pages_skips_entries_without_access_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Has Token', 'access_token' => 'pt-1'],
                ['id' => '222', 'name' => 'No Token'],
            ]]),
        ]);

        $pages = app(HttpFacebookGraph::class)->pages('user-token');

        $this->assertSame([['id' => '111', 'name' => 'Has Token', 'access_token' => 'pt-1']], $pages);
    }

    public function test_subscribe_app_posts_messages_field(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

        app(HttpFacebookGraph::class)->subscribeApp('111', 'pt-1');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/v21.0/111/subscribed_apps')
            && $request['subscribed_fields'] === 'messages');
    }

    public function test_send_message_posts_recipient_and_text(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'm1'])]);

        app(HttpFacebookGraph::class)->sendMessage('111', 'pt-1', 'recipient-9', 'Hello!');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/v21.0/111/messages')
            && $request['recipient']['id'] === 'recipient-9'
            && $request['message']['text'] === 'Hello!'
            && $request['messaging_type'] === 'RESPONSE');
    }
}
