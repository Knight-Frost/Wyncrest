<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ContractStatus;
use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Notification;
use App\Models\User;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\TenantReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * TenantDashboardController
 *
 * Single source of truth for the tenant dashboard. Every value here is derived
 * from real, authenticated, owner-scoped backend records — there are no
 * hardcoded counts, names, applications, messages, or readiness values.
 */
class TenantDashboardController extends Controller
{
    public function index(Request $request, TenantReadinessService $readiness, LedgerComputationEngine $engine): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $uid = (int) $user->id;

        // ---- Counts (real queries) -----------------------------------------
        $savedListingsCount = $user->savedListings()->count();
        $applicationsActiveCount = Application::where('tenant_id', $uid)->active()->count();
        $verifiedListingsCount = Listing::query()->public()->verified()->count();
        $unreadNotifications = Notification::where('user_id', $uid)->unread()->count();

        // ---- Active lease ---------------------------------------------------
        $activeContract = Contract::where('tenant_id', $uid)
            ->where('status', ContractStatus::ACTIVE)
            ->with(['listing.unit.property', 'listing.primaryPhoto', 'landlord'])
            ->latest('start_date')
            ->first();

        // ---- Rent summary (from the immutable ledger) ----------------------
        $rentSummary = null;
        if ($activeContract !== null) {
            $unpaid = LedgerEntry::where('tenant_id', $uid)->unpaid()->get();
            $nextDue = $unpaid->sortBy(fn ($e) => $e->due_date)->first();

            $rentSummary = [
                // "What the tenant currently owes" = outstanding unpaid
                // obligations, via the same engine that powers every other
                // balance figure in the app.
                'balance_cents' => $engine->computeOutstanding(['tenant_id' => $uid]),
                'currency' => $activeContract->currency,
                // Whether ANY ledger entry exists for this tenant (paid or
                // unpaid) — distinct from next_due, which only reflects
                // unpaid entries and is legitimately null for a paid-up
                // tenant with real history.
                'has_history' => LedgerEntry::where('tenant_id', $uid)->exists(),
                'next_due' => $nextDue ? [
                    'id' => $nextDue->id,
                    'amount_cents' => (int) $nextDue->amount_cents,
                    'due_date' => $nextDue->due_date?->toDateString(),
                    'status' => $nextDue->status->value,
                    'type' => $nextDue->type->value,
                ] : null,
            ];
        }

        // ---- Recent applications -------------------------------------------
        $applications = Application::where('tenant_id', $uid)
            ->with(['listing.unit.property', 'listing.primaryPhoto'])
            ->latest('submitted_at')
            ->limit(5)
            ->get();

        // ---- Curated listings (active, published, verified landlord) -------
        $curatedListings = Listing::query()->public()->verified()
            ->with(['unit.property', 'primaryPhoto', 'landlord'])
            ->latest('published_at')
            ->limit(6)
            ->get();

        // ---- Saved listings preview ----------------------------------------
        $savedListings = $user->savedListings()
            ->with(['unit.property', 'primaryPhoto'])
            ->orderBy('saved_listings.created_at', 'desc')
            ->limit(4)
            ->get();

        // ---- Recent notifications ------------------------------------------
        $notifications = Notification::where('user_id', $uid)
            ->latest('created_at')
            ->limit(5)
            ->get();

        // ---- Recent conversations (real messaging, real unread) ------------
        $recentConversations = Conversation::query()
            ->forParticipant($user)
            ->withCount(['messages as unread_count' => function ($q) use ($uid) {
                $q->where('is_read', false)
                    ->where('sender_type', User::class)
                    ->where('sender_id', '!=', $uid);
            }])
            ->orderByDesc('last_message_at')
            ->limit(5)
            ->get()
            ->map(function (Conversation $c) use ($user) {
                $other = $c->otherParticipant($user);
                $last = $c->messages()->latest('created_at')->first();

                return [
                    'id' => $c->id,
                    'title' => $c->title,
                    'status' => $c->status,
                    'last_message_at' => $c->last_message_at,
                    'unread_count' => (int) ($c->unread_count ?? 0),
                    'other_participant' => $other
                        ? ['id' => $other->id, 'name' => $other->full_name, 'initials' => $other->initials, 'avatar_url' => $other->avatar_url]
                        : null,
                    'preview' => $last ? Str::limit($last->body, 80) : null,
                ];
            });

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->full_name,
                'first_name' => $user->first_name,
                'email' => $user->email,
                'initials' => $user->initials,
                'city' => $user->city,
                'user_type' => $user->user_type->value,
                'identity_verified' => $user->identity_verified,
            ],
            'readiness' => $readiness->compute($user),
            'stats' => [
                'applications_count' => $applicationsActiveCount,
                'saved_listings_count' => $savedListingsCount,
                'verified_listings_count' => $verifiedListingsCount,
                'unread_notifications_count' => $unreadNotifications,
            ],
            'active_contract' => $activeContract,
            'rent_summary' => $rentSummary,
            'applications' => $applications,
            'curated_listings' => $curatedListings,
            'saved_listings' => $savedListings,
            'recent_conversations' => $recentConversations,
            'notifications' => $notifications,
            'feature_availability' => [
                'applications' => true,
                'maintenance' => $activeContract !== null,
                'documents' => true,
                'messages' => true,
                'compare' => true,
            ],
        ]);
    }
}
