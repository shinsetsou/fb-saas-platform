<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Chat Tester</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow sm:rounded-lg p-6">
                @unless($settings->is_enabled)
                    <p class="mb-4 text-sm text-red-600">Bot is disabled. Enable it in Bot Settings to chat.</p>
                @endunless

                <div class="space-y-3 mb-6">
                    @forelse($messages as $message)
                        <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="rounded-lg px-3 py-2 text-sm {{ $message->role === 'user' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-800' }}">
                                {{ $message->content }}
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Send a message to start testing your bot.</p>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('chat-tester.store') }}" class="flex gap-2">
                    @csrf
                    <x-text-input name="message" class="flex-1" placeholder="Type a message…" required autofocus />
                    <x-primary-button>Send</x-primary-button>
                </form>
                <x-input-error :messages="$errors->get('message')" class="mt-2" />
            </div>
        </div>
    </div>
</x-app-layout>
