<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Bot settings
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <form method="POST" action="{{ route('bot-settings.update') }}" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-6">
                @csrf
                @method('PUT')

                @if (session('status') === 'bot-settings-updated')
                    <p class="text-sm text-green-700">Bot settings saved.</p>
                @endif

                <label class="flex items-center gap-3">
                    <input type="checkbox" name="is_enabled" value="1" @checked(old('is_enabled', $settings->is_enabled))>
                    <span>Enable autopilot replies</span>
                </label>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="business_name">Business name</label>
                    <input class="mt-1 block w-full rounded-md border-gray-300" id="business_name" name="business_name" value="{{ old('business_name', $settings->business_name) }}" required>
                    @error('business_name') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="business_description">Business description</label>
                    <textarea class="mt-1 block w-full rounded-md border-gray-300" id="business_description" name="business_description" rows="4">{{ old('business_description', $settings->business_description) }}</textarea>
                    @error('business_description') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="default_appointment_duration_minutes">Duration</label>
                        <input class="mt-1 block w-full rounded-md border-gray-300" id="default_appointment_duration_minutes" name="default_appointment_duration_minutes" type="number" min="15" max="480" value="{{ old('default_appointment_duration_minutes', $settings->default_appointment_duration_minutes) }}" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="booking_timezone">Timezone</label>
                        <input class="mt-1 block w-full rounded-md border-gray-300" id="booking_timezone" name="booking_timezone" value="{{ old('booking_timezone', $settings->booking_timezone) }}" required>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="buffer_minutes">Buffer</label>
                        <input class="mt-1 block w-full rounded-md border-gray-300" id="buffer_minutes" name="buffer_minutes" type="number" min="0" max="240" value="{{ old('buffer_minutes', $settings->buffer_minutes) }}" required>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="cancellation_instructions">Cancellation instructions</label>
                    <textarea class="mt-1 block w-full rounded-md border-gray-300" id="cancellation_instructions" name="cancellation_instructions" rows="3">{{ old('cancellation_instructions', $settings->cancellation_instructions) }}</textarea>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700" for="bot_tone">Bot tone</label>
                    <textarea class="mt-1 block w-full rounded-md border-gray-300" id="bot_tone" name="bot_tone" rows="3">{{ old('bot_tone', $settings->bot_tone) }}</textarea>
                </div>

                @php
                    $stored = old('booking_hours', $settings->booking_hours ?? null);
                    if (is_string($stored)) {
                        $stored = json_decode($stored, true) ?: null;
                    }
                    $hoursByDay = [];
                    foreach ($weekdays as $day) {
                        $ranges = $stored[$day] ?? [];
                        $hoursByDay[$day] = [];
                        foreach ($ranges as $range) {
                            $hoursByDay[$day][] = [
                                'start' => $range['start'] ?? '',
                                'end' => $range['end'] ?? '',
                            ];
                        }
                    }
                @endphp

                <fieldset class="border border-gray-200 rounded-md p-4">
                    <legend class="px-2 text-sm font-medium text-gray-700">Booking hours</legend>
                    <p class="text-xs text-gray-500 mb-3">Times are in <strong>{{ $settings->booking_timezone }}</strong>. Leave a day empty to mark it closed.</p>

                    @error('booking_hours')
                        <div class="mb-3 text-sm text-red-600 space-y-1">
                            @foreach ((array) $message as $line)
                                <p>{{ $line }}</p>
                            @endforeach
                        </div>
                    @enderror

                    <div class="space-y-3" x-data="bookingHours(@js($hoursByDay))">
                        @foreach ($weekdays as $day)
                            <div class="grid grid-cols-1 md:grid-cols-[120px_1fr_auto] gap-3 items-start">
                                <div class="text-sm font-medium text-gray-700 pt-2 capitalize">{{ $day }}</div>
                                <div class="space-y-2">
                                    <template x-for="(range, index) in hours.{{ $day }}" :key="index">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <input
                                                type="time"
                                                class="rounded-md border-gray-300 text-sm"
                                                :name="`booking_hours[{{ $day }}][${index}][start]`"
                                                x-model="range.start"
                                            >
                                            <span class="text-gray-500 text-sm">to</span>
                                            <input
                                                type="time"
                                                class="rounded-md border-gray-300 text-sm"
                                                :name="`booking_hours[{{ $day }}][${index}][end]`"
                                                x-model="range.end"
                                            >
                                            <button
                                                type="button"
                                                class="text-xs text-red-600 hover:underline"
                                                x-show="hours.{{ $day }}.length > 1"
                                                x-on:click="hours.{{ $day }}.splice(index, 1)"
                                            >Remove</button>
                                        </div>
                                    </template>
                                </div>
                                <button
                                    type="button"
                                    class="text-xs text-indigo-600 hover:underline pt-2"
                                    x-on:click="hours.{{ $day }}.push({ start: '09:00', end: '17:00' })"
                                >+ Add range</button>
                            </div>
                        @endforeach
                    </div>
                </fieldset>

                <button class="inline-flex items-center px-4 py-2 bg-gray-900 text-white rounded-md font-semibold text-xs uppercase tracking-widest">
                    Save settings
                </button>
            </form>
        </div>
    </div>
</x-app-layout>
