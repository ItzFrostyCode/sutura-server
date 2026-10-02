<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\SmsMessage;
use App\Services\Sms\SmsOutbox;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use App\Models\JobOrder;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $this->receiptHistory($store, $main?->id);
        $this->smsDemo($store);
        $this->pendingBranch($store);
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

    /**
     * A regular customer (Pia Santos) with eight finished orders spread over the last ~8 months, each paid by a
     * deposit and a balance with a real receipt image — so the Statements screen has something to filter by
     * week / month / year and to download. Idempotent (keyed on the order number).
     */
    private function receiptHistory(Store $store, ?int $branchId): void
    {
        $owner = $store->owner;
        $service = Service::where('store_id', $store->id)->where('is_active', true)->first();
        if (! $owner || ! $service || ! function_exists('imagecreatetruecolor')) {
            return;
        }

        $pia = User::firstOrCreate(
            ['email' => 'pia.santos@gmail.com'],
            ['name' => 'Pia Santos', 'password' => Hash::make('password'), 'email_verified_at' => now(), 'phone' => '09000000008']
        );
        if ($role = Role::where('name', 'customer')->first()) {
            $pia->roles()->syncWithoutDetaching([$role->id]);
        }

        // [days ago the order was settled, total, method] — the deposit was paid 9 days before that, so every
        // date stays in the past however recent the order is.
        $history = [
            [4, 3200, 'gcash'], [11, 5400, 'paymaya'], [19, 2800, 'gcash'], [33, 7600, 'bank_transfer'],
            [58, 4100, 'gcash'], [96, 6900, 'paymaya'], [150, 3500, 'gcash'], [235, 8800, 'bank_transfer'],
        ];
        foreach ($history as $i => [$daysAgo, $total, $method]) {
            $n = $i + 1;
            $when = now()->subDays($daysAgo + 9)->setTime(10 + ($n % 6), 15 + $n * 3);
            $orderNo = 'ORD-H'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);

            $job = JobOrder::withoutEvents(fn () => JobOrder::firstOrCreate(
                ['store_id' => $store->id, 'order_number' => $orderNo],
                [
                    'tracking_code' => 'HIST'.strtoupper(Str::random(4)), 'intake_channel' => 'online', 'fulfillment_type' => 'pickup',
                    'store_branch_id' => $branchId, 'customer_id' => $pia->id, 'service_id' => $service->id,
                    'total_amount' => $total, 'balance' => 0, 'payment_status' => 'paid', 'status' => 'completed',
                    'due_date' => $when->copy()->addDays(10)->toDateString(), 'notes' => 'Past order (demo history)',
                    'measurement_requirement' => 'none', 'fitting_requirement' => 'none', 'payment_policy' => 'deposit', 'payment_policy_percent' => 50,
                ]
            ));
            JobOrder::withoutEvents(fn () => $job->forceFill(['created_at' => $when, 'updated_at' => $when->copy()->addDays(10)])->save());

            $deposit = round($total / 2, 2);
            foreach ([['deposit', $deposit, $when], ['balance', $total - $deposit, $when->copy()->addDays(9)]] as $j => [$type, $amount, $at]) {
                $ref = 'DEMO-'.$orderNo.'-'.($j + 1);
                if (Payment::where('reference', $ref)->exists()) {
                    continue;
                }
                $file = "stores/{$store->id}/receipts/demo-{$orderNo}-".($j + 1).'.png';
                Storage::disk('public')->put($file, $this->receiptPng($method, $amount, $ref, $at, $store->name, $pia->name));
                $payment = new Payment;
                $payment->forceFill([
                    'job_order_id' => $job->id, 'amount' => $amount, 'payment_method' => $method, 'source' => 'online', 'type' => $type,
                    'reference' => $ref, 'recorded_by' => $pia->id, 'receipt_path' => '/storage/'.$file,
                    'verified_at' => $at->copy()->addHour(), 'verified_by' => $owner->id, 'notes' => 'Demo receipt',
                    'created_at' => $at, 'updated_at' => $at->copy()->addHour(),
                ])->save();
            }
        }
    }

    /** A plain wallet-style receipt screenshot (PNG) drawn with GD — stands in for a customer's real screenshot. */
    private function receiptPng(string $method, float $amount, string $reference, \Illuminate\Support\Carbon $at, string $shop, string $payer): string
    {
        $brand = ['gcash' => [0, 110, 230, 'GCash'], 'paymaya' => [20, 160, 90, 'Maya'], 'bank_transfer' => [90, 60, 150, 'Bank transfer']][$method] ?? [60, 60, 60, 'Payment'];
        $img = imagecreatetruecolor(360, 560);
        $bg = imagecolorallocate($img, 247, 247, 249);
        $head = imagecolorallocate($img, $brand[0], $brand[1], $brand[2]);
        $ink = imagecolorallocate($img, 30, 30, 34);
        $muted = imagecolorallocate($img, 120, 120, 128);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $bg);
        imagefilledrectangle($img, 0, 0, 360, 120, $head);
        imagestring($img, 5, 24, 36, $brand[3].' - Payment sent', $white);
        imagestring($img, 3, 24, 66, $at->format('M d, Y  h:i A'), $white);
        imagestring($img, 2, 24, 160, 'Amount', $muted);
        imagestring($img, 5, 24, 182, 'PHP '.number_format($amount, 2), $ink);
        imagestring($img, 2, 24, 240, 'Sent to', $muted);
        imagestring($img, 4, 24, 260, Str::limit($shop, 34, ''), $ink);
        imagestring($img, 2, 24, 310, 'From', $muted);
        imagestring($img, 4, 24, 330, Str::limit($payer, 34, ''), $ink);
        imagestring($img, 2, 24, 380, 'Reference no.', $muted);
        imagestring($img, 4, 24, 400, $reference, $ink);
        imagestring($img, 1, 24, 500, 'Demo receipt generated for testing - not a real transaction', $muted);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    /**
     * Made-up mobile numbers for the demo customers, so the text-message outbox has someone to "send" to. The 0900 prefix is
     * not an assigned mobile prefix, so these can never reach a real person — and the SMS driver is in test mode anyway.
     * One customer deliberately has a broken number so the "fix the number" step can be seen.
     */
    private function smsDemo(Store $store): void
    {
        $phones = [
            'jose.rizal@gmail.com' => '09000000001', 'andres.b@gmail.com' => '09000000002', 'maria.clara@gmail.com' => '09000000003',
            'juan.delacruz@gmail.com' => '09000000004', 'liza.fernandez@example.com' => '09000000005', 'mark.villanueva@example.com' => '09000000006',
            'cristina.ramos@example.com' => '0900-000', 'pia.santos@gmail.com' => '09000000008', 'tess.tester@gmail.com' => '09000000009', 'tomas.tester@gmail.com' => '09000000010',
        ];
        foreach ($phones as $email => $phone) {
            User::where('email', $email)->where(fn ($q) => $q->whereNull('phone')->orWhere('phone', '09171230001'))->update(['phone' => $phone]);
        }
        $store->update(['sms_mode' => 'review']);

        // Real drafts built from the real templates, on real records.
        if ($appt = Appointment::with(['store', 'customer'])->where('store_id', $store->id)->where('status', 'confirmed')->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->first()) {
            $t = SmsTemplates::reminder($appt);
            SmsOutbox::queue($store, $appt->customer, $t['event'], $t['body'], 'appointment', $appt->id, $t['dedupe']);
        }
        $cristina = User::where('email', 'cristina.ramos@example.com')->first();
        if ($cristina && ($appt = Appointment::with('store')->where('store_id', $store->id)->where('customer_id', $cristina->id)->first())) {
            $t = SmsTemplates::reminder($appt);
            SmsOutbox::queue($store, $cristina, $t['event'], $t['body'], 'appointment', $appt->id, $t['dedupe'].':demo');
        }
        if ($job = JobOrder::with(['store', 'customer'])->where('store_id', $store->id)->where('status', 'ready_for_fitting')->first()) {
            if ($t = SmsTemplates::order($job, 'ready_for_fitting')) {
                SmsOutbox::queue($store, $job->customer, $t['event'], $t['body'], 'job_order', $job->id, $t['dedupe'].':demo');
            }
        }
        // One already approved and "sent" in test mode, so the Sent tab has an example.
        if ($job = JobOrder::with(['store', 'customer'])->where('store_id', $store->id)->where('status', 'ready_for_pickup')->first()) {
            $t = SmsTemplates::order($job, 'ready_for_pickup');
            $m = $t ? SmsOutbox::queue($store, $job->customer, $t['event'], $t['body'], 'job_order', $job->id, $t['dedupe']) : null;
            if ($m && $m->status === 'draft') {
                $m->forceFill(['status' => 'approved', 'approved_by' => $store->owner_id, 'approved_at' => now()])->save();
                SmsSender::send($m);
            }
        }
    }

    /** A second branch waiting for the System Admin's location check, so the admin queue and the owner's "awaiting check" badge have something to show. */
    private function pendingBranch(Store $store): void
    {
        \App\Models\StoreBranch::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'ubalde-agdao-branch'],
            [
                'name' => 'Ubalde Branch (Agdao)', 'address' => 'Jasmin St', 'barangay' => 'Ubalde', 'district' => 'Agdao', 'city' => 'Davao City',
                'landmark' => 'Near the Ubalde chapel', 'latitude' => 7.0905, 'longitude' => 125.6144, 'contact_number' => '09000000011',
                'operating_hours' => 'Mon-Sat 8:00 AM - 5:00 PM', 'status' => 'active', 'is_main' => false, 'verification_status' => 'pending',
            ]
        );
    }
}
