<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use App\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * NotificationPreferenceController
 * 
 * API for managing user notification preferences.
 * Phase 3.8: Control email/SMS delivery per notification type.
 */
class NotificationPreferenceController extends Controller
{
    /**
     * Get user's notification preferences
     * 
     * GET /api/notification-preferences
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Get all user preferences
        $preferences = NotificationPreference::where('user_id', $user->id)->get();

        // Format response
        $formatted = [];
        foreach (NotificationType::cases() as $type) {
            $preference = $preferences->firstWhere('notification_type', $type->value);
            
            if ($preference) {
                $formatted[$type->value] = [
                    'email' => $preference->email_enabled,
                    'sms' => $preference->sms_enabled,
                ];
            } else {
                // No preference → show defaults
                $formatted[$type->value] = [
                    'email' => true,
                    'sms' => false,
                ];
            }
        }

        return response()->json($formatted);
    }

    /**
     * Update user's notification preferences
     * 
     * PUT /api/notification-preferences
     * 
     * Body example:
     * {
     *   "rent_generated": { "email": true, "sms": false },
     *   "payment_failed": { "email": true, "sms": true }
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // Validate request
        $validator = Validator::make($request->all(), [
            '*.email' => 'required|boolean',
            '*.sms' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Invalid preference format',
                'errors' => $validator->errors(),
            ], 422);
        }

        $preferences = $request->all();
        $updated = [];

        foreach ($preferences as $notificationType => $channels) {
            // Validate notification type exists
            try {
                $typeEnum = NotificationType::from($notificationType);
            } catch (\ValueError $e) {
                continue; // Skip invalid types
            }

            // Update or create preference
            $preference = NotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $notificationType,
                ],
                [
                    'email_enabled' => $channels['email'],
                    'sms_enabled' => $channels['sms'],
                ]
            );

            $updated[$notificationType] = [
                'email' => $preference->email_enabled,
                'sms' => $preference->sms_enabled,
            ];
        }

        return response()->json([
            'message' => 'Preferences updated successfully',
            'preferences' => $updated,
        ]);
    }
}
