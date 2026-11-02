<?php

namespace App\Http\Controllers\Api\V1\Preferences;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Preferences\UpdatePreferencesRequest;
use App\Http\Resources\UserPreferenceResource;
use App\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET/PUT /api/v1/preferences — the operator's persisted locale, voice and
 * wake word preferences (US-4.5, US-4.4). Each user touches only their own row.
 */
class PreferencesController extends Controller
{
    public function show(Request $request): UserPreferenceResource
    {
        $preferences = $this->preferencesFor($request);

        Gate::authorize('view', $preferences);

        return UserPreferenceResource::make($preferences);
    }

    public function update(UpdatePreferencesRequest $request): UserPreferenceResource
    {
        $preferences = $this->preferencesFor($request);

        Gate::authorize('update', $preferences);

        $data = $request->validated();
        $acceptedConsent = (bool) ($data['wake_word_consent'] ?? false);
        unset($data['wake_word_consent']);

        // First acceptance of the privacy notice is recorded once (US-4.4);
        // turning the wake word off never erases the consent.
        if ($acceptedConsent && $preferences->wake_word_consented_at === null) {
            $data['wake_word_consented_at'] = now();
        }

        $preferences->update($data);

        return UserPreferenceResource::make($preferences);
    }

    /**
     * The preference row is created on first access so the column defaults
     * (locale, speech rate, ...) are always present in the response.
     */
    private function preferencesFor(Request $request): UserPreference
    {
        $preferences = $request->user()->preference()->firstOrCreate();

        if ($preferences->wasRecentlyCreated) {
            // A fresh instance so the column defaults (locale, speech rate, ...)
            // are present in the response without a "created" status.
            return UserPreference::query()->findOrFail($preferences->getKey());
        }

        return $preferences;
    }
}
