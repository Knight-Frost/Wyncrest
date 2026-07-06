<?php

namespace App\Http\Controllers;

use App\Enums\MediaCollection;
use App\Enums\MediaVisibility;
use App\Http\Requests\ReorderMediaRequest;
use App\Http\Requests\StoreMediaRequest;
use App\Models\Listing;
use App\Models\MaintenanceRequest;
use App\Models\MediaAsset;
use App\Models\Property;
use App\Models\Unit;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * MediaController
 *
 * Handles media upload, download (controlled streaming), reorder, and deletion.
 *
 * SECURITY:
 * - Authorization for upload is checked via MediaAssetPolicy::upload() with the
 *   relevant attachable resource.
 * - Private / restricted assets are never redirected to public URLs — they are
 *   streamed through this controller after the policy check.
 * - `path` and `disk` are in MediaAsset::$hidden and never appear in JSON.
 */
class MediaController extends Controller
{
    public function __construct(private readonly MediaService $mediaService) {}

    // -------------------------------------------------------------------------
    // Landlord gallery uploads
    // -------------------------------------------------------------------------

    /**
     * POST /landlord/properties/{property}/media
     * Upload an image to a property's gallery.
     */
    public function storeForProperty(StoreMediaRequest $request, Property $property): JsonResponse
    {
        $this->authorize('upload', [MediaAsset::class, MediaCollection::PropertyGallery->value, $property]);

        $asset = $this->mediaService->store(
            file: $request->file('file'),
            collection: MediaCollection::PropertyGallery->value,
            attachable: $property,
            uploader: $request->user(),
            owner: $request->user(),
            visibility: MediaVisibility::Public->value,
        );

        if ($request->filled('alt_text')) {
            $asset->update(['alt_text' => $request->input('alt_text')]);
        }
        if ($request->filled('caption')) {
            $asset->update(['caption' => $request->input('caption')]);
        }

        return response()->json($asset->refresh(), 201);
    }

    /**
     * POST /landlord/units/{unit}/media
     * Upload an image to a unit's gallery.
     */
    public function storeForUnit(StoreMediaRequest $request, Unit $unit): JsonResponse
    {
        // Need the property for ownership check — eager load if not already loaded
        $unit->loadMissing('property');

        $this->authorize('upload', [MediaAsset::class, MediaCollection::UnitGallery->value, $unit]);

        $asset = $this->mediaService->store(
            file: $request->file('file'),
            collection: MediaCollection::UnitGallery->value,
            attachable: $unit,
            uploader: $request->user(),
            owner: $request->user(),
            visibility: MediaVisibility::Public->value,
        );

        if ($request->filled('alt_text')) {
            $asset->update(['alt_text' => $request->input('alt_text')]);
        }
        if ($request->filled('caption')) {
            $asset->update(['caption' => $request->input('caption')]);
        }

        return response()->json($asset->refresh(), 201);
    }

    /**
     * POST /landlord/listings/{listing}/media
     * Upload an image to a listing's gallery.
     */
    public function storeForListing(StoreMediaRequest $request, Listing $listing): JsonResponse
    {
        $this->authorize('upload', [MediaAsset::class, MediaCollection::ListingGallery->value, $listing]);

        $asset = $this->mediaService->store(
            file: $request->file('file'),
            collection: MediaCollection::ListingGallery->value,
            attachable: $listing,
            uploader: $request->user(),
            owner: $request->user(),
            visibility: MediaVisibility::Public->value,
        );

        if ($request->filled('alt_text')) {
            $asset->update(['alt_text' => $request->input('alt_text')]);
        }
        if ($request->filled('caption')) {
            $asset->update(['caption' => $request->input('caption')]);
        }

        return response()->json($asset->refresh(), 201);
    }

    /**
     * POST /tenant/maintenance/{maintenanceRequest}/media
     * POST /landlord/maintenance/{maintenanceRequest}/media
     * Attach a photo (evidence) or receipt to a maintenance request. Callable
     * by the filing tenant or the responsible landlord; owner is always the
     * tenant (the request's subject), uploader is whoever is authenticated.
     */
    public function storeForMaintenanceRequest(StoreMediaRequest $request, MaintenanceRequest $maintenanceRequest): JsonResponse
    {
        $this->authorize('upload', [MediaAsset::class, MediaCollection::MaintenanceEvidence->value, $maintenanceRequest]);

        $asset = $this->mediaService->store(
            file: $request->file('file'),
            collection: MediaCollection::MaintenanceEvidence->value,
            attachable: $maintenanceRequest,
            uploader: $request->user(),
            owner: $maintenanceRequest->tenant,
            visibility: MediaVisibility::Restricted->value,
        );

        if ($request->filled('caption')) {
            $asset->update(['caption' => $request->input('caption')]);
        }

        return response()->json($asset->refresh(), 201);
    }

    // -------------------------------------------------------------------------
    // Avatar uploads (tenant + landlord)
    // -------------------------------------------------------------------------

    /**
     * POST /tenant/avatar  or  POST /landlord/avatar
     * Upload or replace the authenticated user's avatar.
     * Attachable is the User model itself; visibility is public.
     */
    public function storeAvatar(StoreMediaRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('upload', [MediaAsset::class, MediaCollection::Avatar->value, $user]);

        // Archive the previous active avatar (only one should be active at a time)
        MediaAsset::where('owner_user_id', $user->id)
            ->where('collection', MediaCollection::Avatar->value)
            ->where('status', 'active')
            ->get()
            ->each(fn (MediaAsset $a) => $this->mediaService->delete($a, $user));

        $asset = $this->mediaService->store(
            file: $request->file('file'),
            collection: MediaCollection::Avatar->value,
            attachable: $user,
            uploader: $user,
            owner: $user,
            visibility: MediaVisibility::Public->value,
        );

        if ($request->filled('alt_text')) {
            $asset->update(['alt_text' => $request->input('alt_text')]);
        }

        return response()->json($asset->refresh(), 201);
    }

    // -------------------------------------------------------------------------
    // Controlled streaming (private / restricted assets)
    // -------------------------------------------------------------------------

    /**
     * GET /media/{mediaAsset}  [name: media.show]
     *
     * Policy-gated streaming endpoint for private and restricted assets.
     * Public assets are served directly via Storage URLs and should not reach here,
     * but if they do we still apply the policy for belt-and-braces safety.
     *
     * SECURITY: `path` and `disk` are read internally (bypassing $hidden) via
     * getRawOriginal() so they never appear in JSON and are only used here.
     */
    public function show(Request $request, MediaAsset $mediaAsset): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $mediaAsset);

        $disk = $mediaAsset->getRawOriginal('disk');
        $path = $mediaAsset->getRawOriginal('path');

        if (! Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'File not found.'], 404);
        }

        return Storage::disk($disk)->download($path, $mediaAsset->original_filename);
    }

    // -------------------------------------------------------------------------
    // Reorder
    // -------------------------------------------------------------------------

    /**
     * PATCH /landlord/media/reorder
     * Update sort_order for a list of MediaAsset IDs (landlord-owned only).
     */
    public function reorder(ReorderMediaRequest $request): JsonResponse
    {
        $ids = $request->validated()['ids'];

        // Verify caller owns all assets being reordered
        $assets = MediaAsset::whereIn('id', $ids)
            ->where('owner_user_id', $request->user()->id)
            ->get();

        if ($assets->count() !== count($ids)) {
            return response()->json(['message' => 'One or more media assets not found or not owned by you.'], 403);
        }

        $this->mediaService->reorder($ids);

        return response()->json(['message' => 'Sort order updated.']);
    }

    // -------------------------------------------------------------------------
    // Deletion
    // -------------------------------------------------------------------------

    /**
     * DELETE /landlord/media/{mediaAsset}
     * Delete (archive) a media asset.
     */
    public function destroy(Request $request, MediaAsset $mediaAsset): JsonResponse
    {
        $this->authorize('delete', $mediaAsset);

        $this->mediaService->delete($mediaAsset, $request->user());

        return response()->json(['message' => 'Media asset deleted.']);
    }
}
