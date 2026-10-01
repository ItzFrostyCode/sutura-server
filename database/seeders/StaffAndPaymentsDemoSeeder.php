<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Measurement;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\SupportTicketReply;
use App\Models\User;
use App\Notifications\AppointmentAssignedNotification;
use App\Notifications\StoreActivityNotification;
use Illuminate\Database\Seeder;

/**
 * Demo data for the appointment → job → payment rework and the staff module, so a fresh
 * `migrate --seed` shows every new screen with something in it instead of empty states.
 * Idempotent — safe to re-run. Runs after LocalTestSeeder (needs its shop, staff and customers).
 */
class StaffAndPaymentsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::where('slug', 'thread-needle')->first();
        if (! $store) {
            return;
        }
        $main = $store->branches()->where('is_main', true)->first();
        $staff = User::where('email', 'juan.delacruz@sutura.com')->first();
        $admin = User::where('email', 'admin@sutura.com')->first();

        $this->paymentMethods($store);
        $this->requirements($store);
        $this->backfillPaymentTypes();
        if ($main) {
            $this->appointments($store, $main->id, $staff);
        }
        $this->measurements($store);
        $this->staffTicket($store, $staff, $admin);
        $this->staffNotifications($store, $staff);
    }

    /** Shop-wide GCash and Maya, plus a bank account for the main branch only. */
    private function paymentMethods(Store $store): void
    {
        $main = $store->branches()->where('is_main', true)->value('id');
        $methods = [
            ['kind' => 'gcash', 'name' => 'GCash', 'account_name' => 'Thread & Needle Tailoring', 'account_number' => '0917 123 4567', 'instructions' => 'Send the exact amount, then upload the screenshot here.', 'store_branch_id' => null],
            ['kind' => 'maya', 'name' => 'Maya', 'account_name' => 'Thread & Needle Tailoring', 'account_number' => '0918 765 4321', 'instructions' => null, 'store_branch_id' => null],
            ['kind' => 'bank_transfer', 'name' => 'BPI Savings', 'account_name' => 'Maria Cruz', 'account_number' => '1234 5678 90', 'instructions' => 'Main branch account. Use your order number as the reference.', 'store_branch_id' => $main],
        ];
        foreach ($methods as $m) {
            PaymentMethod::updateOrCreate(['store_id' => $store->id, 'name' => $m['name']], $m + ['is_active' => true]);
        }
    }

    /** Shop defaults plus a few offerings that override them, so precedence is visible. */
    private function requirements(Store $store): void
    {
        $store->update([
            'default_measurement_requirement' => 'shop',
            'default_fitting_requirement' => 'optional',
            'default_payment_policy' => 'deposit',
            'default_payment_percent' => 50,
        ]);
        // Formal/bridal work: fitting required.
        Service::where('store_id', $store->id)->where(fn ($q) => $q->where('name', 'like', '%Bridal%')->orWhere('name', 'like', '%Barong%'))
            ->update(['fitting_requirement' => 'required']);
        // Bulk uniforms and jerseys: sized from a roster, so no body measurements and no fitting; uniforms are paid in full first.
        Service::where('store_id', $store->id)->where(fn ($q) => $q->where('name', 'like', '%Jersey%')->orWhere('name', 'like', '%Uniform%'))
            ->update(['measurement_requirement' => 'none', 'fitting_requirement' => 'none']);
        Service::where('store_id', $store->id)->where('name', 'like', '%Uniform%')->update(['payment_policy' => 'full']);
        // Repairs: nothing to measure or fit.
        Service::where('store_id', $store->id)->where('name', 'like', '%Alteration%')
            ->update(['measurement_requirement' => 'none', 'fitting_requirement' => 'none']);
    }

    /** Payments seeded before the source/type columns existed: first one is the deposit (or full), the rest balances. */
    private function backfillPaymentTypes(): void
    {
        Payment::whereNull('type')->with('jobOrder')->orderBy('id')->get()
            ->groupBy('job_order_id')
            ->each(function ($payments) {
                foreach ($payments->values() as $i => $payment) {
                    $owing = (float) ($payment->jobOrder?->balance ?? 0) > 0;
                    $payment->forceFill(['type' => $i === 0 ? ($owing ? 'deposit' : 'full') : 'balance'])->save();
                }
            });
    }

    private function appointments(Store $store, int $branchId, ?User $staff): void
    {
        $base = ['store_id' => $store->id, 'store_branch_id' => $branchId, 'duration_minutes' => 30, 'intake_channel' => 'walk_in'];

        // An "Other" visit with its purpose label (completed, so it never blocks that customer from booking).
        if ($jose = User::where('email', 'jose.rizal@gmail.com')->first()) {
            Appointment::updateOrCreate(
                ['store_id' => $store->id, 'customer_id' => $jose->id, 'appointment_type' => 'other', 'purpose_label' => 'Fabric shopping'],
                $base + ['scheduled_at' => now()->subDays(6)->setTime(10, 0), 'status' => 'completed', 'notes' => 'Went with the customer to choose a fabric.']
            );
        }

        // A request the shop declined, with a preset reason (rejected never blocks a new booking).
        if ($tester = User::where('email', 'tomas.tester@gmail.com')->first()) {
            Appointment::updateOrCreate(
                ['store_id' => $store->id, 'customer_id' => $tester->id, 'appointment_type' => 'consultation', 'status' => 'rejected'],
                $base + [
                    'intake_channel' => 'online', 'scheduled_at' => now()->addDays(4)->setTime(14, 0),
                    'rejection_reason_code' => 'schedule_unavailable', 'rejection_note' => 'We are fully booked that afternoon — please pick another day.',
                ]
            );
        }

        // The staff demo login gets an appointment of their own, so "Assigned to me" and its alerts are not empty.
        if ($staff) {
            Appointment::where('store_id', $store->id)->where('status', 'confirmed')->whereNull('assigned_staff_id')
                ->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->first()
                ?->update(['assigned_staff_id' => $staff->id]);
        }
    }

    /** One profile still waiting on a fitting check, so the Finalized / Pending fitting badges both appear. */
    private function measurements(Store $store): void
    {
        if (! $customer = User::where('email', 'maria.clara@gmail.com')->first()) {
            return;
        }
        Measurement::updateOrCreate(
            ['store_id' => $store->id, 'customer_id' => $customer->id, 'profile_name' => 'Barong Tagalog', 'version' => 1],
            [
                'source' => 'store_owner', 'status' => 'pending_fitting', 'superseded_at' => null,
                'metrics' => ['Chest' => '92', 'Waist' => '78', 'Shoulder' => '43', 'Sleeve' => '60'],
                'notes' => 'Taken from the paper sheet — confirm at the first fitting.',
            ]
        );
    }

    /** A ticket from the plain staff login, answered by the system admin. */
    private function staffTicket(Store $store, ?User $staff, ?User $admin): void
    {
        if (! $staff || ! $admin) {
            return;
        }
        $ticket = SupportTicket::updateOrCreate(
            ['store_id' => $store->id, 'user_id' => $staff->id, 'subject' => 'Cannot attach more than 6 photos to a measurement'],
            [
                'message' => 'The paper measurement sheet has 8 pages. Can the limit be raised, or should I combine them into fewer photos?',
                'type' => 'general', 'priority' => 'low', 'status' => 'open',
            ]
        );
        SupportTicketReply::updateOrCreate(
            ['ticket_id' => $ticket->id, 'is_admin_reply' => true],
            ['user_id' => $admin->id, 'message' => 'Thanks for flagging this — for now please combine pages into up to 6 photos. We will review the limit.']
        );
    }

    /** The staff bell: an assigned appointment and the newest order in their branch. */
    private function staffNotifications(Store $store, ?User $staff): void
    {
        if (! $staff) {
            return;
        }
        $staff->notifications()->delete();
        if ($appointment = Appointment::where('assigned_staff_id', $staff->id)->orderByDesc('scheduled_at')->first()) {
            $staff->notify(new AppointmentAssignedNotification($appointment));
        }
        if ($job = $store->jobOrders()->latest('id')->first()) {
            $staff->notify(new StoreActivityNotification(
                'job_new_for_branch', 'New Order', "Order {$job->order_number} was added.", '/dashboard/jobs/'.$job->id, ['job_order_id' => $job->id]
            ));
        }
    }
}
