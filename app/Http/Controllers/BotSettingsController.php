<?php

namespace App\Http\Controllers;

use App\Support\BookingHours;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BotSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        $workspace = $request->user()->workspaces()->with('botSetting')->firstOrFail();

        return view('bot-settings.edit', [
            'workspace' => $workspace,
            'settings' => $workspace->botSetting,
            'weekdays' => BookingHours::WEEKDAYS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $request->user()->workspaces()->with('botSetting')->firstOrFail();

        $validated = $request->validate([
            'is_enabled' => ['nullable', 'boolean'],
            'business_name' => ['required', 'string', 'max:255'],
            'business_description' => ['nullable', 'string', 'max:5000'],
            'default_appointment_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'booking_timezone' => ['required', 'timezone'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'cancellation_instructions' => ['nullable', 'string', 'max:2000'],
            'bot_tone' => ['nullable', 'string', 'max:1000'],
            'booking_hours' => ['nullable', 'array'],
            'booking_hours.*' => ['array'],
            'booking_hours.*.*' => ['array'],
            'booking_hours.*.*.start' => ['nullable', 'string'],
            'booking_hours.*.*.end' => ['nullable', 'string'],
        ]);

        $normalized = BookingHours::normalize($request->input('booking_hours'));
        $hoursErrors = BookingHours::validate($normalized);

        if ($hoursErrors !== []) {
            throw ValidationException::withMessages(['booking_hours' => $hoursErrors]);
        }

        $validated['is_enabled'] = $request->boolean('is_enabled');
        $validated['booking_hours'] = $normalized;

        $workspace->botSetting->update($validated);

        return redirect()
            ->route('bot-settings.edit')
            ->with('status', 'bot-settings-updated');
    }
}
