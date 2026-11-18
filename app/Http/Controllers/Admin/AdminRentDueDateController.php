<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AdjustRentDueDateRequest;
use App\Http\Requests\ChangeRentDueScheduleRequest;
use App\Models\Contract;
use App\Models\LedgerEntry;
use App\Services\Ledger\DueDateChangeRefused;
use App\Services\Ledger\DueDateConflict;
use App\Services\Ledger\LedgerComputationEngine;
use App\Services\Ledger\RentDueDateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AdminRentDueDateController — thin HTTP layer over RentDueDateService.
 *
 * Reads of a lease's schedule are open to every admin (like the ledger);
 * previews and changes require manage_ledger (route middleware).
 */
class AdminRentDueDateController extends Controller
{
    public function __construct(
        protected RentDueDateService $service,
        protected LedgerComputationEngine $engine,
    ) {}

    public function previewEntry(Request $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        $data = $request->validate(['due_date' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->service->previewAdjustment($ledgerEntry, $data['due_date']));
    }

    public function adjustEntry(AdjustRentDueDateRequest $request, LedgerEntry $ledgerEntry): JsonResponse
    {
        return $this->respond(function () use ($request, $ledgerEntry) {
            $result = $this->service->adjust(
                $ledgerEntry,
                $request->string('due_date'),
                $request->string('reason'),
                auth('admin')->user(),
                $request->string('expected_due_date'),
                $request->string('expected_status'),
            );

            return [
                'changed' => $result['changed'],
                'message' => $result['changed'] ? 'Due date updated.' : 'This charge is already due on that date. Nothing changed.',
                'entry' => $this->engine->decorateEntry($result['entry']),
                'revision_id' => $result['revision']?->id,
            ];
        });
    }

    public function schedule(Contract $contract): JsonResponse
    {
        return response()->json($this->service->schedule($contract));
    }

    public function previewSchedule(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'due_day' => ['required', 'integer', 'between:1,31'],
            'effective_month' => ['required', 'date_format:Y-m'],
        ]);

        return response()->json($this->service->previewScheduleChange($contract, (int) $data['due_day'], $data['effective_month']));
    }

    public function changeSchedule(ChangeRentDueScheduleRequest $request, Contract $contract): JsonResponse
    {
        return $this->scheduleChange($request, $contract, (int) $request->integer('due_day'), false);
    }

    public function resetSchedule(ChangeRentDueScheduleRequest $request, Contract $contract): JsonResponse
    {
        return $this->scheduleChange($request, $contract, (int) config('billing.default_due_day', 1), true);
    }

    private function scheduleChange(ChangeRentDueScheduleRequest $request, Contract $contract, int $dueDay, bool $isDefault): JsonResponse
    {
        return $this->respond(function () use ($request, $contract, $dueDay, $isDefault) {
            $result = $this->service->changeSchedule(
                $contract,
                $dueDay,
                $request->string('effective_month'),
                $request->string('reason'),
                auth('admin')->user(),
                (int) $request->integer('expected_version'),
                $isDefault,
            );

            return [
                'changed' => $result['changed'],
                'message' => $result['changed'] ? 'Rent schedule updated.' : 'The schedule already says this. Nothing changed.',
                'schedule' => $this->service->schedule($contract->fresh()),
            ];
        });
    }

    private function respond(callable $action): JsonResponse
    {
        try {
            return response()->json($action());
        } catch (DueDateConflict $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'stale', 'current' => $e->current], 409);
        } catch (DueDateChangeRefused $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'refused'], 422);
        }
    }
}
