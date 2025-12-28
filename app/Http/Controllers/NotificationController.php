<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * NotificationController
 * 
 * Handles user notification retrieval and management.
 * Read-only for Phase 3.5 (no delivery logic).
 */
class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notificationService
    ) {}

    /**
     * Get user's notifications (paginated)
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->getUserNotifications(
            $request->user(),
            perPage: $request->input('per_page', 20)
        );

        return response()->json($notifications);
    }

    /**
     * Get user's unread notifications
     */
    public function unread(Request $request): JsonResponse
    {
        $notifications = $this->notificationService->getUnreadNotifications(
            $request->user()
        );

        return response()->json($notifications);
    }

    /**
     * Get unread count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Notification $notification, NotificationService $service): JsonResponse
    {
        // Policy check: User can only mark their own notifications as read
        $this->authorize('update', $notification);

        $service->markAsRead($notification);

        return response()->json([
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'message' => "{$count} notifications marked as read",
            'count' => $count,
        ]);
    }
}
