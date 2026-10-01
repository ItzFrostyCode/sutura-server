<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymentMethod;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The shop's own list of places customers can pay. Owners manage the whole shop's list; a branch
 * manager manages only their branch's methods. Customers read the active ones (public).
 */
class PaymentMethodController extends Controller
{
    private function managerBranch(Request $request): ?int
    {
        return $request->user()->hasRole('store_owner') ? null : (int) ($request->user()->staffProfile->store_branch_id ?? 0);
    }

    /** Owners may touch anything; a branch manager only methods scoped to their own branch. */
    private function denied(Request $request, PaymentMethod $m, Store $store): ?JsonResponse
    {
        if ($m->store_id !== $store->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }
        if (! $request->user()->hasRole('store_owner') && (int) $m->store_branch_id !== $this->managerBranch($request)) {
            return response()->json(['success' => false, 'message' => 'Only the owner can change shop-wide payment methods.'], 403);
        }

        return null;
    }

    private function rules(Store $store): array
    {
        return [
            'kind' => ['required', Rule::in(PaymentMethod::KINDS)],
            'name' => ['required', 'string', 'max:80'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:80'],
            'qr_path' => ['nullable', 'string', 'max:2048'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'store_branch_id' => ['nullable', 'integer', Rule::exists('store_branches', 'id')->where('store_id', $store->id)],
        ];
    }

    public function index(Request $request, Store $store): JsonResponse
    {
        $branch = $this->managerBranch($request);
        $methods = $store->paymentMethods()->with('branch:id,name')
            ->when($branch, fn ($q) => $q->where(fn ($qq) => $qq->whereNull('store_branch_id')->orWhere('store_branch_id', $branch)))
            ->orderBy('id')->get();

        return response()->json(['success' => true, 'data' => $methods]);
    }

    public function store(Request $request, Store $store): JsonResponse
    {
        // A branch manager's methods always belong to their own branch — whatever branch they send is ignored.
        $branch = $this->managerBranch($request);
        $rules = $this->rules($store);
        if ($branch !== null) {
            unset($rules['store_branch_id']);
        }
        $data = $request->validate($rules);
        if ($branch !== null) {
            $data['store_branch_id'] = $branch;
        }
        $method = $store->paymentMethods()->create($data);

        return response()->json(['success' => true, 'data' => $method], 201);
    }

    public function update(Request $request, Store $store, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($denied = $this->denied($request, $paymentMethod, $store)) {
            return $denied;
        }
        $rules = $this->rules($store);
        if ($this->managerBranch($request) !== null) {
            unset($rules['store_branch_id']);
        }
        $data = $request->validate($rules);
        $paymentMethod->update($data);

        return response()->json(['success' => true, 'data' => $paymentMethod->fresh()]);
    }

    public function destroy(Request $request, Store $store, PaymentMethod $paymentMethod): JsonResponse
    {
        if ($denied = $this->denied($request, $paymentMethod, $store)) {
            return $denied;
        }
        // Past payments keep their record (the link is nulled), so history survives.
        $paymentMethod->delete();

        return response()->json(['success' => true]);
    }

    /** Customer-facing: active methods for a shop, plus the branch's own when ?branch_id is given. */
    public function publicIndex(Request $request, Store $store): JsonResponse
    {
        $branch = $request->integer('branch_id') ?: null;
        $methods = $store->paymentMethods()->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('store_branch_id')->when($branch, fn ($qq) => $qq->orWhere('store_branch_id', $branch)))
            ->orderBy('id')
            ->get(['id', 'kind', 'name', 'account_name', 'account_number', 'qr_path', 'instructions']);

        return response()->json(['success' => true, 'data' => $methods]);
    }
}
