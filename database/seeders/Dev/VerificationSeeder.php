<?php

namespace Database\Seeders\Dev;

use App\Enums\UserType;
use App\Models\Admin;
use App\Models\Document;
use App\Models\User;
use App\Models\VerificationNote;
use App\Models\VerificationRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * VerificationSeeder — identity verification requests.
 *
 * Two jobs:
 *
 *  1. For every catalog demo user whose verification status is not
 *     "unverified", create a matching verification_request WITH real attached
 *     Document rows (and a real placeholder file on disk) so the document
 *     viewer has something genuine to preview/download — not just a note
 *     claiming documents were submitted.
 *
 *  2. Seed a small set of standalone, seeder-owned demo accounts (not part of
 *     SeedCatalog, so they don't ripple into Property/Contract/Ledger
 *     seeders) whose sole purpose is to populate the *live* admin review
 *     queue: pending, needs-info, missing-documents, and a resubmission
 *     case. Without these, every seeded account is already "verified" and
 *     the queue an admin lands on is empty.
 */
class VerificationSeeder extends DevSeeder
{
    public function run(): void
    {
        $adminId = $this->superAdmin()?->id;
        $reviewerId = Admin::where('email', 'reviewer@'.$this->domain())->value('id');
        $reviewed = 0;

        foreach (array_merge(SeedCatalog::LANDLORDS, SeedCatalog::TENANTS) as $person) {
            $status = $person['verification'];
            if ($status === 'unverified') {
                continue; // never submitted
            }

            $user = $this->user($person['key']);
            if (! $user) {
                continue;
            }

            $req = $this->createRequest($user, $status, $adminId);
            $this->attachDocuments($user, $req, $status);
            $reviewed++;
        }

        $queued = $this->seedQueueDemoCases($adminId, $reviewerId);

        $this->command?->info(
            "  ✓ Verification: {$reviewed} reviewed requests (with real attached documents) + "
            ."{$queued} live queue cases (pending / needs-info / missing-docs / resubmitted)."
        );
    }

    protected function createRequest(User $user, string $status, ?int $adminId): VerificationRequest
    {
        $submittedAt = now()->subDays(18);

        $attributes = [
            'user_id' => $user->id,
            'note' => 'Submitted Ghana Card and proof of address for review (demo).',
            'submitted_at' => $submittedAt,
        ];

        // Reviewed outcomes carry a reviewer + timestamp + reason; queued ones don't.
        // NOTE: the request-level status vocabulary ('approved') is distinct from
        // the user-level VerificationStatus enum ('verified') — VerificationService
        // sets the request to 'approved' on approval, so the seeder must match.
        match ($status) {
            'verified' => $attributes = array_merge($attributes, [
                'status' => 'approved',
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $submittedAt->copy()->addDays(2),
                'decision_reason' => 'Identity confirmed against submitted Ghana Card.',
            ]),
            'rejected' => $attributes = array_merge($attributes, [
                'status' => 'rejected',
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $submittedAt->copy()->addDays(2),
                'decision_reason' => 'Submitted document was expired. Please re-submit a valid ID.',
            ]),
            'needs_more_information' => $attributes = array_merge($attributes, [
                'status' => 'needs_more_information',
                'reviewed_by_admin_id' => $adminId,
                'reviewed_at' => $submittedAt->copy()->addDays(1),
                'decision_reason' => 'Proof of address is unreadable. Please upload a clearer copy.',
            ]),
            default => $attributes['status'] = 'pending', // awaiting review
        };

        return VerificationRequest::create($attributes);
    }

    /**
     * Attach real Document rows (with a real file written to the local disk)
     * to a reviewed catalog request. Fully verified accounts get both an
     * identity document and a proof of address, modelling a complete case.
     */
    protected function attachDocuments(User $user, VerificationRequest $req, string $status): void
    {
        $this->makeDocument($user, $req, 'identity_document', 'ghana-card.pdf', 'application/pdf', $this->placeholderPdf());

        if ($status === 'verified') {
            $this->makeDocument($user, $req, 'proof_of_address', 'proof-of-address.png', 'image/png', $this->placeholderPng());
        }
    }

    protected function makeDocument(User $user, VerificationRequest $req, string $type, string $filename, string $mime, string $content): Document
    {
        $path = "documents/verification/{$req->id}/{$filename}";
        Storage::disk('local')->put($path, $content);

        return Document::create([
            'owner_user_id' => $user->id,
            'uploaded_by_id' => $user->id,
            'document_type' => $type,
            'original_filename' => $filename,
            'stored_path' => $path,
            'disk' => 'local',
            'mime_type' => $mime,
            'size_bytes' => strlen($content),
            'related_type' => VerificationRequest::class,
            'related_id' => $req->id,
        ]);
    }

    /**
     * Standalone demo accounts that keep the live review queue non-empty:
     * a pending tenant, a pending landlord missing proof of address, a
     * tenant needing more info, a tenant with zero documents (missing-doc
     * warning), and a tenant who was rejected then resubmitted (previous
     * attempts). Created directly here (not via SeedCatalog) so they don't
     * ripple into Property/Contract/Ledger/Application seeders.
     */
    protected function seedQueueDemoCases(?int $adminId, ?int $reviewerId): int
    {
        $password = Hash::make($this->demoPassword());
        $count = 0;

        // -- Pending tenant, identity document on file -----------------------
        $tenantPending = $this->makeStandaloneUser(
            'verify.tenant.pending@'.$this->domain(), UserType::TENANT, 'Abena', 'Sarpong', '0249900001', $password
        );
        $reqPending = VerificationRequest::create([
            'user_id' => $tenantPending->id,
            'note' => 'First-time verification — Ghana Card attached.',
            'submitted_at' => now()->subDays(2),
            'status' => 'pending',
        ]);
        $this->makeDocument($tenantPending, $reqPending, 'identity_document', 'ghana-card.pdf', 'application/pdf', $this->placeholderPdf());
        $count++;

        // -- Pending landlord, identity doc only (missing proof of address) --
        $landlordPending = $this->makeStandaloneUser(
            'verify.landlord.pending@'.$this->domain(), UserType::LANDLORD, 'Kwabena', 'Osei', '0549900002', $password
        );
        $reqLandlordPending = VerificationRequest::create([
            'user_id' => $landlordPending->id,
            'note' => 'New landlord account — Ghana Card attached, proof of address to follow.',
            'submitted_at' => now()->subHours(20),
            'status' => 'pending',
        ]);
        $this->makeDocument($landlordPending, $reqLandlordPending, 'identity_document', 'ghana-card.pdf', 'application/pdf', $this->placeholderPdf());
        $count++;

        // -- Tenant needing more information ---------------------------------
        $tenantNeedsInfo = $this->makeStandaloneUser(
            'verify.tenant.needsinfo@'.$this->domain(), UserType::TENANT, 'Yaw', 'Boakye', '0249900003', $password
        );
        $reqNeedsInfo = VerificationRequest::create([
            'user_id' => $tenantNeedsInfo->id,
            'note' => 'Submitting for review.',
            'submitted_at' => now()->subDays(4),
            'status' => 'needs_more_information',
            'reviewed_by_admin_id' => $reviewerId ?? $adminId,
            'reviewed_at' => now()->subDays(3),
            'decision_reason' => 'The submitted photo ID is blurry. Please upload a clearer scan.',
        ]);
        $this->makeDocument($tenantNeedsInfo, $reqNeedsInfo, 'identity_document', 'ghana-card-blurry.pdf', 'application/pdf', $this->placeholderPdf());
        $count++;

        // -- Tenant with zero documents (missing-document warning state) -----
        $tenantNoDocs = $this->makeStandaloneUser(
            'verify.tenant.nodocs@'.$this->domain(), UserType::TENANT, 'Efe', 'Danso', '0249900004', $password
        );
        VerificationRequest::create([
            'user_id' => $tenantNoDocs->id,
            'note' => null,
            'submitted_at' => now()->subHours(3),
            'status' => 'pending',
        ]);
        $count++;

        // -- Tenant with a previous rejection who resubmitted -----------------
        $tenantResubmitted = $this->makeStandaloneUser(
            'verify.tenant.resubmitted@'.$this->domain(), UserType::TENANT, 'Nii', 'Lartey', '0249900005', $password
        );
        $firstAttempt = VerificationRequest::create([
            'user_id' => $tenantResubmitted->id,
            'note' => 'First submission.',
            'submitted_at' => now()->subDays(10),
            'status' => 'rejected',
            'reviewed_by_admin_id' => $adminId,
            'reviewed_at' => now()->subDays(9),
            'decision_reason' => 'Name on the submitted ID did not match the account name.',
        ]);
        $this->makeDocument($tenantResubmitted, $firstAttempt, 'identity_document', 'id-mismatch.pdf', 'application/pdf', $this->placeholderPdf());

        $secondAttempt = VerificationRequest::create([
            'user_id' => $tenantResubmitted->id,
            'note' => 'Resubmitting with corrected profile name and a new ID scan.',
            'submitted_at' => now()->subDays(1),
            'status' => 'pending',
        ]);
        $this->makeDocument($tenantResubmitted, $secondAttempt, 'identity_document', 'ghana-card-corrected.pdf', 'application/pdf', $this->placeholderPdf());
        $count += 2; // two requests for this one demo case

        // A real internal note on the needs-info case, from the scoped reviewer.
        if ($reviewerId) {
            VerificationNote::create([
                'verification_request_id' => $reqNeedsInfo->id,
                'admin_id' => $reviewerId,
                'body' => 'Emailed the applicant asking for a clearer ID scan — will re-check once they respond.',
            ]);
        }

        return $count;
    }

    protected function makeStandaloneUser(string $email, UserType $type, string $first, string $last, string $phone, string $password): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'user_type' => $type,
                'password' => $password,
                'first_name' => $first,
                'last_name' => $last,
                'phone' => $phone,
                'city' => 'Accra',
                'email_verified_at' => now()->subDays(5),
                'identity_verified' => false,
                'verification_status' => 'pending',
                'account_status' => 'active',
                'is_active' => true,
            ],
        );
    }

    /** A small, real, valid PDF — not a fabricated placeholder string. */
    protected function placeholderPdf(): string
    {
        return <<<'PDF'
%PDF-1.1
1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj
2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 /MediaBox [0 0 300 150] >> endobj
3 0 obj << /Type /Page /Parent 2 0 R /Resources << /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >> /Contents 4 0 R >> endobj
4 0 obj << /Length 66 >>
stream
BT /F1 14 Tf 20 100 Td (Wyncrest demo verification document) Tj ET
endstream
endobj
trailer << /Root 1 0 R /Size 5 >>
%%EOF
PDF;
    }

    /** The smallest valid PNG (1x1 transparent pixel). */
    protected function placeholderPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}
