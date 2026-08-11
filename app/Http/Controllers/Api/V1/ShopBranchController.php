<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ShopBranch;
use App\Models\Shop;

class ShopBranchController extends Controller
{

    public function index($shopId)
    {
        $branches = ShopBranch::where('shop_id', $shopId)->withCount(['staffProfiles', 'jobOrders'])->get();
        return response()->json(['success' => true, 'data' => $branches]);
    }

    public function store(Request $request, $shopId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'contact_number' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'guide_image_url' => 'nullable|string|max:500',
        ]);

        $shop = Shop::findOrFail($shopId);
        
        $branchCount = $shop->branches()->count();
        $subscription = $shop->subscription()->whereIn('status', ['active', 'trial'])->first();
        $canAddBranch = $subscription && $subscription->plan->slug === 'premium';

        if ($branchCount >= 1 && !$canAddBranch) {
            return response()->json(['success' => false, 'message' => 'Upgrade to the Premium plan to add multiple branches.'], 403);
        }

        $branch = ShopBranch::create([
            'shop_id'          => $shop->id,
            'name'             => $request->name,
            // Same pattern as Shop::slug (ShopController@store) — lets the
            // public booking page identify a branch without exposing a raw
            // sequential id in the URL.
            'slug'             => \Illuminate\Support\Str::slug($request->name) . '-' . uniqid(),
            'address'          => $request->address,
            'landmark'         => $request->landmark,
            'city'             => $request->city,
            'contact_number'   => $request->contact_number,
            'latitude'         => $request->latitude,
            'longitude'        => $request->longitude,
            'operating_hours'  => $request->operating_hours,
            'status'           => 'active',
            'is_main'          => $branchCount === 0,
            'guide_image_url'  => $request->guide_image_url,
        ]);

        // Load counts so the frontend card renders correct values immediately
        $branch->loadCount(['staffProfiles', 'jobOrders']);

        return response()->json(['success' => true, 'message' => 'Branch added successfully.', 'data' => $branch]);
    }

    public function update(Request $request, $shopId, ShopBranch $branch)
    {
        if ($branch->shop_id != $shopId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'city' => 'required|string|max:255',
            'contact_number' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
            'guide_image_url' => 'nullable|string|max:500',
        ]);

        $branch->update([
            'name' => $request->name,
            'address' => $request->address,
            'landmark' => $request->landmark,
            'city' => $request->city,
            'contact_number' => $request->contact_number,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'operating_hours' => $request->operating_hours,
            'status' => $request->status ?? $branch->status,
            'guide_image_url' => $request->guide_image_url,
        ]);

        return response()->json(['success' => true, 'message' => 'Branch updated successfully.', 'data' => $branch]);
    }

    public function destroy(Request $request, $shopId, ShopBranch $branch)
    {
        if ($branch->shop_id != $shopId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($branch->is_main) {
            return response()->json(['success' => false, 'message' => 'Cannot delete the main branch.'], 403);
        }

        if ($branch->jobOrders()->count() > 0 || $branch->staffProfiles()->count() > 0) {
            return response()->json(['success' => false, 'message' => 'Cannot delete branch because it has active job orders or staff assigned.'], 403);
        }

        // Same accountability/timeline gap job_order_deleted/staff_removed/
        // service_deleted already closed — closing down an entire branch
        // location is arguably the single highest-stakes deletion in the
        // system, and previously left no trace in the Audit Log at all.
        $branch->shop->auditLogs()->create([
            'user_id'    => $request->user()->id,
            'action'     => 'branch_deleted',
            'model_type' => \App\Models\ShopBranch::class,
            'model_id'   => $branch->id,
            'payload'    => ['name' => $branch->name],
            'ip_address' => $request->ip(),
        ]);

        $branch->delete();

        return response()->json(['success' => true, 'message' => 'Branch deleted successfully.']);
    }
}
