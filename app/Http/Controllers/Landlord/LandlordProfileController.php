<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateLandlordProfileRequest;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LandlordProfileController
 *
 * Real, authenticated landlord profile — mirrors TenantProfileController.
 * No hardcoded fields beyond what the User model actually stores.
 */
class LandlordProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->presentUser($request->user()),
        ]);
    }

    public function update(UpdateLandlordProfileRequest $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        $original = $user->only(array_keys($request->validated()));

        $user->update($request->validated());

        $audit->log(
            actor: $user,
            action: 'landlord_profile_updated',
            subject: $user,
            description: 'Landlord updated their profile details',
            oldValues: $original,
            newValues: $request->validated(),
            severity: 'info',
        );

        return response()->json([
            'user' => $this->presentUser($user->fresh()),
        ]);
    }

    /**
     * Build the safe display payload. Sensitive/privileged columns are never
     * included; full_name + initials are appended for the UI.
     */
    private function presentUser($user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'initials' => $user->initials,
            'email' => $user->email,
            'phone' => $user->phone,
            'user_type' => $user->user_type->value,
            'identity_verified' => $user->identity_verified,
            'avatar_url' => $user->avatar_url,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
