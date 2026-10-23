<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $workspace->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="bg-white border-l-4 border-indigo-500 p-4 shadow-sm sm:rounded-lg text-sm text-gray-800">
                    @switch(session('status'))
                        @case('facebook-connected')
                            Facebook Page connected.
                            @break
                        @case('facebook-disconnected')
                            Facebook Page disconnected.
                            @break
                        @case('facebook-connect-failed')
                            Could not connect a Facebook Page. Make sure you manage at least one Page and try again.
                            @break
                        @case('google-connected')
                            Google Calendar connected.
                            @break
                        @case('google-disconnected')
                            Google Calendar disconnected.
                            @break
                        @case('google-connect-failed')
                            Could not connect Google Calendar. Please try again.
                            @break
                        @case('subscribed')
                            Subscription active. Thanks!
                            @break
                        @default
                            {{ session('status') }}
                    @endswitch
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <dl class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <dt class="text-sm text-gray-500">Trial</dt>
                            <dd class="font-semibold">{{ ucfirst($trial->status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Bot</dt>
                            <dd class="font-semibold">{{ $botSetting->is_enabled ? 'Bot enabled' : 'Bot disabled' }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Facebook</dt>
                            <dd class="font-semibold">
                                @if ($facebookConnected)
                                    <p class="text-sm text-green-700">Facebook connected</p>
                                    <p class="text-xs text-gray-600">{{ $facebookPage->page_name }}</p>
                                    <form method="POST" action="{{ route('facebook.disconnect') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm text-red-600 underline">Disconnect</button>
                                    </form>
                                @else
                                    <p class="text-sm text-gray-600">Facebook not connected</p>
                                    <a href="{{ route('facebook.connect') }}" class="text-sm text-indigo-600 underline">Connect Facebook Page</a>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Google Calendar</dt>
                            <dd class="font-semibold">
                                @if ($googleConnected)
                                    <p class="text-sm text-green-700">Google Calendar connected</p>
                                    <form method="POST" action="{{ route('google.disconnect') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm text-red-600 underline">Disconnect</button>
                                    </form>
                                @else
                                    <p class="text-sm text-gray-600">Google Calendar not connected</p>
                                    <a href="{{ route('google.connect') }}" class="text-sm text-indigo-600 underline">Connect Google Calendar</a>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Trial ends</dt>
                            <dd class="font-semibold">{{ $trial->ends_at->toFormattedDateString() }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="text-lg font-semibold text-gray-900">Billing</h3>
                    <dl class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <dt class="text-sm text-gray-500">Status</dt>
                            <dd class="font-semibold">
                                @switch($billingStatus)
                                    @case('subscribed')
                                        <span class="text-green-700">Subscribed</span>
                                        @break
                                    @case('trialing')
                                        <span class="text-indigo-700">Trialing</span>
                                        @break
                                    @default
                                        <span class="text-red-700">Lapsed</span>
                                @endswitch
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Replies this month</dt>
                            <dd class="font-semibold" aria-label="replies this period">{{ $repliesThisPeriod }}</dd>
                        </div>
                        <div>
                            <dt class="text-sm text-gray-500">Manage</dt>
                            <dd class="font-semibold">
                                @if ($billingStatus === 'subscribed')
                                    <a href="{{ route('billing.portal') }}" class="text-sm text-indigo-600 underline">Manage billing</a>
                                @else
                                    <a href="{{ route('billing.checkout') }}" class="text-sm text-indigo-600 underline">Subscribe</a>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900">Recent conversations</h3>
                        @if ($recentConversations->isEmpty())
                            <p class="mt-2 text-sm text-gray-600">No conversations yet — connect a Facebook Page (or use the chat tester) to get started.</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-100">
                                @foreach ($recentConversations as $conversation)
                                    @php
                                        $last = $conversation->messages->first();
                                    @endphp
                                    <li class="py-2 text-sm">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-gray-800">{{ $conversation->channel === 'web' ? 'Web tester' : ucfirst($conversation->channel) }} — {{ $conversation->external_participant_id }}</span>
                                            <span class="text-xs text-gray-400">{{ $conversation->updated_at->diffForHumans() }}</span>
                                        </div>
                                        @if ($last)
                                            <p class="mt-0.5 truncate text-gray-600">{{ $last->role === 'user' ? 'Customer: ' : 'Bot: ' }}{{ $last->content }}</p>
                                        @endif
                                        @if ($conversation->status === 'needs_human')
                                            <span class="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">needs human</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900">Recent bookings</h3>
                        @if ($recentBookings->isEmpty())
                            <p class="mt-2 text-sm text-gray-600">No bookings yet — connect Google Calendar to let the bot book appointments.</p>
                        @else
                            <ul class="mt-3 divide-y divide-gray-100">
                                @foreach ($recentBookings as $appointment)
                                    <li class="py-2 text-sm">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium text-gray-800">{{ $appointment->customer_name }} <span class="text-gray-400">({{ $appointment->customer_contact }})</span></span>
                                            <span class="text-xs text-gray-400">{{ $appointment->starts_at->format('M j, Y g:i A') }}</span>
                                        </div>
                                        @if ($appointment->status !== 'booked')
                                            <span class="mt-1 inline-block rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700">{{ $appointment->status }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
