<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminNotificationController
 *
 * Read-only platform delivery monitor. Surfaces how the notifications the
 * system generated actually reached users across channels (in-app / email /
 * SMS), so an admin can spot and triage failed deliveries.
 *
 * TRUTHFULNESS: every value here is derived from populated columns on the
 * notifications table (delivered_at / delivery_failed_at / delivery_error and
 * the sms_* equivalents), written by NotificationDeliveryService and
 * SmsDeliveryService. Nothing is fabricated.
 *
 * We deliberately expose only two actionable per-channel states — DELIVERED and
 * FAILED — plus an honest "not_sent" bucket. We do NOT label the null/null case
 * as "pending", because on this platform it can also mean the user disabled the
 * channel or opted into digest delivery (see NotificationDeliveryService); a
 * "pending" count would overstate. Admins act on failures; those are exact.
 */
class AdminNotificationController extends Controller
{
    /**
     * List notification deliveries with per-channel status.
     */
    public function deliveries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'in:email,sms'],
            'status' => ['nullable', 'in:delivered,failed'],
            'type' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $channel = $validated['channel'] ?? null;
        $status = $validated['status'] ?? null;

        // Base query carries the shared filters (date/type/search) that both the
        // paginated list and the summary counts respect. The channel/status
        // outcome filter is applied to the list only, so summary tab counts stay
        // stable while the user toggles between outcomes.
        $applyShared = function (Builder $query) use ($validated) {
            if (! empty($validated['type'])) {
                $query->where('type', $validated['type']);
            }
            if (! empty($validated['from'])) {
                $query->whereDate('created_at', '>=', $validated['from']);
            }
            if (! empty($validated['to'])) {
                $query->whereDate('created_at', '<=', $validated['to']);
            }
            if (! empty($validated['search'])) {
                $term = $validated['search'];
                $query->whereHas('user', function (Builder $u) use ($term) {
                    $u->where('email', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%");
                });
            }

            return $query;
        };

        $listQuery = $applyShared(Notification::query())
            ->with('user:id,first_name,last_name,email,user_type');

        $this->applyOutcomeFilter($listQuery, $channel, $status);

        $page = $listQuery->orderByDesc('created_at')->paginate($perPage);

        $data = collect($page->items())->map(fn (Notification $n) => [
            'id' => $n->id,
            'type' => $n->type instanceof NotificationType ? $n->type->value : $n->type,
            'title' => $n->title,
            'created_at' => $n->created_at?->toISOString(),
            'read_at' => $n->read_at?->toISOString(),
            'recipient' => $n->user ? [
                'id' => $n->user->id,
                'name' => trim("{$n->user->first_name} {$n->user->last_name}") ?: $n->user->email,
                'email' => $n->user->email,
                'user_type' => $n->user->user_type?->value,
            ] : null,
            'email' => [
                'status' => $this->channelStatus($n->delivered_at, $n->delivery_failed_at),
                'at' => ($n->delivered_at ?? $n->delivery_failed_at)?->toISOString(),
                'error' => $n->delivery_error,
            ],
            'sms' => [
                'status' => $this->channelStatus($n->sms_delivered_at, $n->sms_failed_at),
                'at' => ($n->sms_delivered_at ?? $n->sms_failed_at)?->toISOString(),
                'error' => $n->sms_error,
            ],
        ])->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'total' => $applyShared(Notification::query())->count(),
                'email' => [
                    'delivered' => $applyShared(Notification::query())->whereNotNull('delivered_at')->count(),
                    'failed' => $applyShared(Notification::query())->whereNotNull('delivery_failed_at')->count(),
                ],
                'sms' => [
                    'delivered' => $applyShared(Notification::query())->whereNotNull('sms_delivered_at')->count(),
                    'failed' => $applyShared(Notification::query())->whereNotNull('sms_failed_at')->count(),
                ],
                'failed_total' => $applyShared(Notification::query())
                    ->where(function (Builder $q) {
                        $q->whereNotNull('delivery_failed_at')->orWhereNotNull('sms_failed_at');
                    })->count(),
            ],
        ]);
    }

    /**
     * Restrict the list to a channel outcome (delivered/failed).
     */
    protected function applyOutcomeFilter(Builder $query, ?string $channel, ?string $status): void
    {
        if ($status === null) {
            return; // No outcome filter — show everything, newest first.
        }

        $emailColumn = $status === 'delivered' ? 'delivered_at' : 'delivery_failed_at';
        $smsColumn = $status === 'delivered' ? 'sms_delivered_at' : 'sms_failed_at';

        if ($channel === 'email') {
            $query->whereNotNull($emailColumn);
        } elseif ($channel === 'sms') {
            $query->whereNotNull($smsColumn);
        } else {
            // Either channel matching the requested outcome.
            $query->where(function (Builder $q) use ($emailColumn, $smsColumn) {
                $q->whereNotNull($emailColumn)->orWhereNotNull($smsColumn);
            });
        }
    }

    /**
     * Derive an honest per-channel status from its two timestamps.
     *
     * "not_sent" intentionally covers queued / preference-disabled / digest —
     * we do not overstate it as "pending".
     */
    protected function channelStatus($deliveredAt, $failedAt): string
    {
        if ($failedAt !== null) {
            return 'failed';
        }
        if ($deliveredAt !== null) {
            return 'delivered';
        }

        return 'not_sent';
    }
}
