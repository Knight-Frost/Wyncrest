<?php

namespace App\Http\Controllers;

use App\Enums\NotificationType;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Sms\SmsClientInterface;
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Admins have no per-user preferences and must not be matched by id
        // against the users table (IDOR). Return the defaults, unqueried.
        $preferences = $user instanceof User
            ? NotificationPreference::where('user_id', $user->id)->get()
            : collect();

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
     * Which delivery channels this deployment can actually send through.
     * In-app notices always work; email needs a mailer; SMS needs Twilio
     * credentials (absent for the first launch). The Settings page shows an
     * unavailable channel as unavailable instead of a toggle that cannot work.
     *
     * GET /api/notification-preferences/channels
     */
    public function channels(SmsClientInterface $sms)
    {
        return response()->json([
            'in_app' => true,
            'email' => config('mail.default') !== null,
            'sms' => $sms->isConfigured(),
        ]);
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // Notification preferences belong to tenant/landlord accounts only.
        // Reject admin writes outright rather than create rows keyed to an
        // admin id that would collide with a real user's preferences (IDOR).
        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Notification preferences are only available for tenant and landlord accounts.',
            ], 403);
        }

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
        // A channel the deployment cannot send through is never stored as
        // "on": that would promise SMS that silently never arrives.
        $smsAvailable = app(SmsClientInterface::class)->isConfigured();

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
                    'sms_enabled' => $smsAvailable && $channels['sms'],
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
            'sms_available' => $smsAvailable,
        ]);
    }
}
