<?php

namespace App\Actions\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class CreateWorkspaceForUser
{
    public function handle(User $user): Workspace
    {
        $baseName = "{$user->name} Workspace";

        $workspace = Workspace::create([
            'name' => $baseName,
            'slug' => $this->uniqueSlug($baseName),
            'timezone' => config('app.timezone', 'UTC'),
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $workspace->trial()->create([
            'starts_at' => now(),
            'ends_at' => now()->addDays(14),
            'status' => 'trialing',
        ]);

        $workspace->botSetting()->create([
            'is_enabled' => false,
            'business_name' => $baseName,
            'booking_timezone' => $workspace->timezone,
            'default_appointment_duration_minutes' => 30,
            'buffer_minutes' => 0,
        ]);

        return $workspace;
    }

    private function uniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        $candidate = $slug;
        $counter = 2;

        while (Workspace::where('slug', $candidate)->exists()) {
            $candidate = "{$slug}-{$counter}";
            $counter++;
        }

        return $candidate;
    }
}
