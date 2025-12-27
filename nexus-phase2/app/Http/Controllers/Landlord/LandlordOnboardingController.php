<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * LandlordOnboardingController
 * 
 * Provides guided onboarding checklist for landlords.
 * Helps new landlords understand setup steps.
 */
class LandlordOnboardingController extends Controller
{
    /**
     * Get onboarding checklist status.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Profile completed
        $profileCompleted = !empty($user->first_name) 
            && !empty($user->last_name) 
            && !empty($user->phone);

        // Identity verified
        $identityVerified = $user->hasVerifiedIdentity();

        // Property created
        $propertyCreated = $user->properties()->count() > 0;

        // Unit created
        $unitCreated = $user->properties()->whereHas('units')->exists();

        // Listing created
        $listingCreated = $user->listings()->count() > 0;

        // Calculate completion percentage
        $steps = [
            $profileCompleted,
            $identityVerified,
            $propertyCreated,
            $unitCreated,
            $listingCreated,
        ];

        $completedCount = count(array_filter($steps));
        $totalCount = count($steps);
        $completionPercentage = ($completedCount / $totalCount) * 100;

        return response()->json([
            'completion_percentage' => $completionPercentage,
            'steps' => [
                [
                    'key' => 'profile',
                    'title' => 'Complete Your Profile',
                    'description' => 'Add your full name and contact information',
                    'completed' => $profileCompleted,
                    'action' => '/profile/edit',
                ],
                [
                    'key' => 'identity',
                    'title' => 'Verify Your Identity',
                    'description' => 'Required to unlock advanced features like payments',
                    'completed' => $identityVerified,
                    'action' => '/identity/verify',
                    'help_text' => $identityVerified ? null : 'Contact support to start verification',
                ],
                [
                    'key' => 'property',
                    'title' => 'Add a Property',
                    'description' => 'Create your first property listing',
                    'completed' => $propertyCreated,
                    'action' => '/landlord/properties/create',
                ],
                [
                    'key' => 'unit',
                    'title' => 'Add a Unit',
                    'description' => 'Add rentable units to your property',
                    'completed' => $unitCreated,
                    'action' => $propertyCreated ? '/landlord/units/create' : null,
                    'disabled' => !$propertyCreated,
                ],
                [
                    'key' => 'listing',
                    'title' => 'Create a Listing',
                    'description' => 'Publish your first listing for tenants to find',
                    'completed' => $listingCreated,
                    'action' => $unitCreated ? '/landlord/listings/create' : null,
                    'disabled' => !$unitCreated,
                ],
            ],
        ]);
    }
}
