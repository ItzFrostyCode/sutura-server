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
            'city' => 'required|string|max:255',
            'contact_number' => 'nullable|string',
        ]);

        $shop = Shop::findOrFail($shopId);
        
        $branchCount = $shop->branches()->count();
        $subscription = $shop->subscription()->where('status', 'active')->first();
        $isEnterprise = $subscription && $subscription->plan->slug === 'enterprise';

        if ($branchCount >= 1 && !$isEnterprise) {
            return response()->json(['success' => false, 'message' => 'Upgrade to Enterprise Tier to add multiple branches.'], 403);
        }

        $branch = ShopBranch::create([
            'shop_id' => $shop->id,
            'name' => $request->name,
            'address' => $request->address,
            'city' => $request->city,
            'contact_number' => $request->contact_number,
            'is_main' => $branchCount === 0,
        ]);

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
            'city' => 'required|string|max:255',
            'contact_number' => 'nullable|string',
        ]);

        $branch->update([
            'name' => $request->name,
            'address' => $request->address,
            'city' => $request->city,
            'contact_number' => $request->contact_number,
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

        $branch->delete();

        return response()->json(['success' => true, 'message' => 'Branch deleted successfully.']);
    }
}
