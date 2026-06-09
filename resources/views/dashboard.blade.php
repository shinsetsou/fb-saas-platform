<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ $workspace->name }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
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
                            <dd class="font-semibold">{{ $facebookConnected ? 'Facebook connected' : 'Facebook not connected' }}</dd>
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
