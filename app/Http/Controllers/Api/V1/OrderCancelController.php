<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ChecksOrderAccess;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\JobOrder;
use App\Models\Store;
use App\Notifications\AppointmentStatusNotification;
use App\Notifications\JobStatusUpdatedNotification;
use App\Notifications\StoreActivityNotification;
use App\Support\SafeNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The only way to cancel an order: owner / branch manager, with the reason and how the customer agreed.
 * (The generic status update refuses `cancelled`.) Refunds are recorded separately and never move money.
 */
class OrderCancelController extends Controller
{
    use ChecksOrderAccess;

    public const AGREED_VIA = ['in_person', 'message', 'phone', 'online'];

    public function cancel(Request $request, Store $store, JobOrder $jobOrder): JsonResponse
    {
        if ($denied = $this->orderAccessDenied($request, $store, $jobOrder)) {
            return $denied;
        }
        if (! $this->isMoneyRole($request)) {
            return response()->json(['success' => false, 'message' => 'Only the owner or a branch manager can cancel an order.'], 403);
        }
        if (in_array($jobOrder->status, ['completed', 'cancelled', 'rejected'], true)) {
            return response()->json(['success' => false, 'message' => 'This order is already '.$jobOrder->status.' and can no longer be cancelled.'], 422);
        }

        // An order made by mistake never involved the customer: no agreement is asked, they are not told, and it only
        // applies while no money has been taken (otherwise it is a real cancellation, with a refund to record).
        $mistake = $request->input('reason') === 'entered_by_mistake';
        $v = $request->validate([
            'reason' => ['required', 'string', Rule::in(JobOrder::CANCELLATION_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
            'agreed_via' => [$mistake ? 'nullable' : 'required', 'string', Rule::in(self::AGREED_VIA)],
            'customer_agreed' => [$mistake ? 'nullable' : 'required', $mistake ? 'boolean' : 'accepted'],
        ]);
        if ($mistake) {
            $paid = (float) $jobOrder->total_amount - (float) $jobOrder->balance - (float) ($jobOrder->discount_amount ?? 0);
            if ($paid > 0.009) {
                return response()->json(['success' => false, 'message' => 'Money was already taken on this order. Cancel it with the customer\'s agreement and record the refund instead.'], 422);
            }
        }

        $oldStatus = $jobOrder->status;
        $record = [
            'agreed_via' => $v['agreed_via'] ?? null,
            'note' => $v['note'] ?? null,
            'by' => $request->user()->id,
            'at' => now()->toIso8601String(),
        ];

        DB::transaction(function () use ($jobOrder, $v, $record, $oldStatus) {
            $data = $jobOrder->custom_order_data ?? [];
            $data['cancellation'] = $record;
            $jobOrder->update(['status' => 'cancelled', 'cancellation_reason' => $v['reason'], 'custom_order_data' => $data]);

            // Close the open production stage the order was in, the same as moving it out of that stage.
            DB::table('job_order_staff')->where('job_order_id', $jobOrder->id)->whereNull('completed_at')->update(['completed_at' => now()]);
            // A wrong order lets go of the consultation it was written from, so the right one can be made from it again.
            if ($v['reason'] === 'entered_by_mistake') {
                Appointment::where('job_order_id', $jobOrder->id)->where('kind', '!=', 'production')->update(['job_order_id' => null, 'outcome' => null]);
            }
            if ($oldStatus === 'on_hold' && $jobOrder->held_at) {
                $jobOrder->forceFill(['held_at' => null])->save();
            }
        });

        // A cancelled order must not leave fitting visits on the calendar.
        $jobOrder->fittings()->whereIn('status', ['pending', 'confirmed', 'in_progress'])->get()
            ->each(function (Appointment $appointment) use ($request) {
                $appointment->update(['status' => 'cancelled']);
                if (! $mistake) {
                    $appointment->customer?->notify(new AppointmentStatusNotification($appointment, 'cancelled', null, $request->user()));
                }
            });

        if (! $mistake) {
            SafeNotify::send($jobOrder->customer, new JobStatusUpdatedNotification($jobOrder, 'cancelled', $request->user()));
        }
        $this->notifyOthers($request, $store, $jobOrder);
        $this->logOrderAction($request, $store, $jobOrder, 'job_order_cancelled', [
            'order_number' => $jobOrder->order_number,
            'from_status' => $oldStatus,
            'reason' => $v['reason'],
        ] + $record);

        return response()->json([
            'success' => true,
            'message' => 'Order cancelled.',
            'data' => $jobOrder->fresh(['customer', 'service', 'assignedStaff']),
        ]);
    }

    /** Tell the owner (when a manager cancelled) and the branch's other managers. */
    private function notifyOthers(Request $request, Store $store, JobOrder $jobOrder): void
    {
        $notification = fn () => new StoreActivityNotification(
            'job_cancelled',
            'Order cancelled',
            "{$jobOrder->order_number} ({$jobOrder->customer?->name}) was cancelled.",
            '/dashboard/jobs/'.$jobOrder->id,
            ['job_order_id' => $jobOrder->id, 'order_number' => $jobOrder->order_number],
        );
        if (! $request->user()->hasRole('store_owner')) {
            $store->owner?->notify($notification());
        }
        if ($jobOrder->store_branch_id) {
            \App\Models\User::whereHas('staffProfile', fn ($q) => $q->where('store_id', $store->id)
                ->where('store_branch_id', $jobOrder->store_branch_id)->where('is_branch_manager', true))
                ->where('id', '!=', $request->user()->id)->get()
                ->each(fn ($manager) => $manager->notify($notification()));
        }
    }
}
