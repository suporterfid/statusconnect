<?php

// Adapted from TaskConnect: app/Http/Controllers/Api/V1/UserPreferencesController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Infrastructure\Persistence\Eloquent\User;
use App\Infrastructure\Persistence\Eloquent\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserPreferencesController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(403);
        }

        $validated = $request->validate([
            'locale' => ['sometimes', 'required', 'string', Rule::in(['en', 'pt_BR'])],
            'timezone' => ['sometimes', 'required', 'string', 'timezone', 'max:64'],
        ]);

        $preferences = UserPreference::query()->firstOrCreate(
            ['user_id' => $actor->id],
            ['locale' => 'en', 'timezone' => 'UTC'],
        );

        if (array_key_exists('locale', $validated)) {
            $preferences->locale = $validated['locale'];
        }

        if (array_key_exists('timezone', $validated)) {
            $preferences->timezone = $validated['timezone'];
        }

        $preferences->save();
        $actor->setRelation('preferences', $preferences->fresh());

        return response()->json([
            'data' => new UserResource($actor),
        ]);
    }
}
