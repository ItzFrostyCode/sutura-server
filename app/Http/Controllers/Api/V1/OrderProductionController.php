<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\JobOrder;
use App\Models\Store;
use App\Support\OrderFlowPresenter;
use App\Support\ProductionLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moving an order along while it is being made: Next, Back one step, Put on hold, Resume. Each one is instant
 * (no draft to save) and open to the owner, branch managers and staff of the branch. They all go through
 * JobOrderController::applyUpdate, so a move has exactly the same gates, stamps, staff attribution and
 * notifications as a status change. The line itself is App\Support\ProductionLine.
 */
class OrderProductionController extends Controller
{
    use ChecksOrderAccess;

    public function next(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        $to = ProductionLine::next($jobOrder);
        if ($to === null) {
            return $this->fail(ProductionLine::atQualityCheck($jobOrder)
                ? 'Use Quality check passed or Needs a fix on the order page.'
                : 'There is no next step from here.');
        }

        return $this->move($request, $store, $jobOrder, $to, 'order_production_next', 'Moved to '.ProductionLine::label($to).'.');
    }

    public function back(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        $to = ProductionLine::previous($jobOrder);
        if ($to === null) {
            return $this->fail($jobOrder->qc_passed_at ? 'The quality check has passed, so the order cannot go back.' : 'There is no earlier step to go back to.');
        }

        return $this->move($request, $store, $jobOrder, $to, 'order_production_back', 'Back to '.ProductionLine::label($to).'.');
    }

    public function hold(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        $v = $request->validate(['reason' => 'required|string|min:3|max:2000'], ['reason.required' => 'Say why the order is on hold.', 'reason.min' => 'Say why the order is on hold.']);
        if (! ProductionLine::holdable($jobOrder)) {
            return $this->fail('Only an order that is being made can be put on hold.');
        }

        return $this->move($request, $store, $jobOrder, 'on_hold', 'order_production_hold', 'The order is on hold.', [
            'hold_reason' => trim($v['reason']), 'status_before_hold' => $jobOrder->status,
        ]);
    }

    public function resume(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if ($jobOrder->status !== 'on_hold') {
            return $this->fail('This order is not on hold.');
        }
        $to = $jobOrder->status_before_hold ?: ProductionLine::resumeFallback($jobOrder);

        return $this->move($request, $store, $jobOrder, $to, 'order_production_resume', 'Back to '.ProductionLine::label($to).'.', [
            'hold_reason' => null, 'status_before_hold' => null,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function move(Request $request, Store $store, JobOrder $job, string $to, string $audit, string $message, array $extra = []): JsonResponse
    {
        $from = $job->status;
        $res = app(JobOrderController::class)->applyUpdate($request, $store, $job, ['status' => $to] + $extra);
        if ($res->getStatusCode() >= 400) {
            return $res;
        }
        $this->logOrderAction($request, $store, $job, $audit, array_filter(['from' => $from, 'to' => $to, 'reason' => $extra['hold_reason'] ?? null]));
        $fresh = $job->fresh();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => OrderFlowPresenter::payload($fresh, $request->user()),
            'job' => $fresh,
        ]);
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}
