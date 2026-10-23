<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SmsMessage;
use App\Models\Store;
use App\Models\User;
use App\Services\Sms\SmsOutbox;
use App\Support\BranchPin;
use App\Support\PlanGate;
use App\Support\SmsText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Texts to one customer, from their profile. Same outbox as every other text: a message written here waits for the
 * shop to check it (or goes out at once when the shop's texts are set to automatic), and never bypasses the opt-out,
 * number and daily-limit checks in SmsSender.
 */
class CustomerTextController extends Controller
{
    private function ensureOnBook(Store $store, User $customer): void
    {
        $onBook = $store->customers()->where('user_id', $customer->id)->exists()
            || $store->jobOrders()->where('customer_id', $customer->id)->exists()
            || $store->appointments()->where('customer_id', $customer->id)->exists();
        abort_unless($onBook, 404, 'Customer not found in this store');
    }

    private function present(SmsMessage $m): array
    {
        return [
            'id' => $m->id, 'event_label' => $m->event === 'manual' ? 'Written by the shop' : (\App\Services\Sms\SmsTemplates::EVENTS[$m->event] ?? $m->event),
            'status' => $m->status, 'body' => $m->body, 'to_number' => $m->to_number, 'blocked_reason' => $m->blocked_reason,
            'error' => $m->error, 'is_test' => $m->is_test, 'created_at' => $m->created_at, 'sent_at' => $m->sent_at,
        ];
    }

    public function index(Request $request, Store $store, User $customer): JsonResponse
    {
        $this->ensureOnBook($store, $customer);
        $messages = SmsMessage::where('store_id', $store->id)->where('user_id', $customer->id)->forBranch(BranchPin::of($request->user()))->latest('id')->limit(50)->get();

        return response()->json(['success' => true, 'data' => [
            'sms_mode' => $store->sms_mode,
            'plan_allows' => PlanGate::allows($store, 'pro'),
            'has_number' => \App\Support\PhoneNumber::normalize($customer->phone) !== null,
            'opted_out' => (bool) $customer->sms_opt_out,
            'messages' => $messages->map(fn ($m) => $this->present($m))->values(),
        ]]);
    }

    public function store(Request $request, Store $store, User $customer): JsonResponse
    {
        $this->ensureOnBook($store, $customer);
        $data = $request->validate(['body' => ['required', 'string', 'min:5', 'max:320']]);
        $body = SmsText::clean($data['body']);
        abort_if(strlen($body) < 5, 422, 'The message is empty after removing unsupported characters.');
        abort_if($store->sms_mode === 'off', 422, 'Text messages are switched off. Turn them on in Text messages → Settings.');
        abort_unless(PlanGate::allows($store, 'pro'), 422, 'Text messages are part of the Pro plan.');

        $message = SmsOutbox::queue($store, $customer, 'manual', $body);
        abort_if($message === null, 422, 'The text could not be prepared.');

        return response()->json(['success' => true, 'data' => $this->present($message)], 201);
    }
}
