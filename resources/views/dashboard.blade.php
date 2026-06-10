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
                            <dd class="font-semibold">{{ $repliesThisPeriod }}</dd>
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
                        <p class="mt-2 text-sm text-gray-600">Facebook message history will appear here after the Page integration is connected.</p>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-900">Recent bookings</h3>
                        <p class="mt-2 text-sm text-gray-600">Calendar bookings will appear here after Google Calendar is connected.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
