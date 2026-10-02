<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\Store;
use App\Models\StoreBranch;
use App\Services\GoogleMapsLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StoreBranchController extends Controller
{
    /**
     * A branch manager may update their own branch's day-to-day info
     * (hours, contact, location) — matches the dashboard sidebar already
     * showing them "Branches" — but never another branch's, and never the
     * manager_id field itself: reassigning who manages a branch is a
     * promotion/demotion action, the same "never lets them promote anyone
     * to branch manager" rule StaffController::staffManagerCrudDenied()
     * already enforces for staff. store()/setMain()/destroy() (adding,
     * designating, or removing a whole physical location) stay owner-only
     * entirely — bigger structural decisions than editing an existing one.
     */
    private function branchManagerCrudDenied(Request $request, StoreBranch $branch, bool $wantsManagerChange): ?JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('store_owner')) {
            return null;
        }

        $userBranchId = $user->staffProfile->store_branch_id ?? null;
        if ((int) $userBranchId !== (int) $branch->id) {
            return response()->json(['success' => false, 'message' => 'You can only manage your own branch.'], 403);
        }

        if ($wantsManagerChange) {
            return response()->json(['success' => false, 'message' => 'Only the store owner can reassign a branch\'s manager.'], 403);
        }

        return null;
    }

    public function index($storeId)
    {
        $branches = StoreBranch::where('store_id', $storeId)
            ->withCount(['staffProfiles', 'jobOrders'])
            ->with([
                'manager.user:id,name,email,phone,profile_picture',
                'staffProfiles' => function ($q) {
                    $q->where('is_branch_manager', true)->with('user:id,name,email,phone,profile_picture');
                },
            ])
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $branches]);
    }

    public function store(Request $request, $storeId)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'barangay' => 'nullable|string|max:100',
            'city' => 'required|string|max:255',
            // Same 8 Davao City districts the public discovery filter uses
            // (StoreController::publicIndex) — this is the only place a
            // branch's district ever gets set, so leaving it out here meant
            // every newly added branch stayed permanently unfilterable.
            'district' => 'nullable|in:Poblacion,Talomo,Buhangin,Agdao,Toril,Bunawan,Calinan,Tugbok',
            'contact_number' => 'nullable|string',
            // A branch with no coordinates can never get a pin on the map or take part in "near me" — so they are required
            // (paste the Google Maps link in the form and they fill themselves in).
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'operating_hours' => 'nullable|string|max:255',
            'guide_image_url' => 'nullable|string|max:500',
            'manager_id' => 'nullable|integer',
        ]);

        $store = Store::findOrFail($storeId);

        $branchCount = $store->branches()->count();
        $subscription = $store->subscription()->whereIn('status', ['active', 'trial'])->first();
        $canAddBranch = $subscription && $subscription->plan->slug === 'premium';

        if ($branchCount >= 1 && ! $canAddBranch) {
            return response()->json(['success' => false, 'message' => 'Upgrade to the Premium plan to add multiple branches.'], 403);
        }

        $branch = StoreBranch::create([
            'store_id' => $store->id,
            'name' => $request->name,
            // Same pattern as Store::slug (StoreController@store) — lets the
            // public booking page identify a branch without exposing a raw
            // sequential id in the URL.
            'slug' => Str::slug($request->name).'-'.uniqid(),
            'address' => $request->address,
            'landmark' => $request->landmark,
            'barangay' => $request->barangay,
            'city' => $request->city,
            'district' => $request->district,
            'contact_number' => $request->contact_number,
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'operating_hours' => $request->operating_hours,
            'status' => 'active',
            // Not on the public map until the System Admin has checked the location.
            'verification_status' => 'pending',
            'is_main' => $branchCount === 0,
            'guide_image_url' => $request->guide_image_url,
        ]);

        if ($request->filled('manager_id')) {
            $staff = StaffProfile::where('store_id', $storeId)->find($request->manager_id);
            if ($staff) {
                $staff->update([
                    'store_branch_id' => $branch->id,
                    'is_branch_manager' => true,
                ]);
                $bmRole = Role::where('name', 'branch_manager')->first();
                if ($bmRole && $staff->user) {
                    $staff->user->roles()->syncWithoutDetaching([$bmRole->id]);
                }
            }
        }

        $branch->loadCount(['staffProfiles', 'jobOrders']);
        $branch->load([
            'manager.user:id,name,email,phone,profile_picture',
            'staffProfiles' => function ($q) {
                $q->where('is_branch_manager', true)->with('user:id,name,email,phone,profile_picture');
            },
        ]);

        return response()->json(['success' => true, 'message' => 'Branch added successfully.', 'data' => $branch]);
    }

    public function update(Request $request, $storeId, StoreBranch $branch)
    {
        if ($branch->store_id != $storeId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if ($denied = $this->branchManagerCrudDenied($request, $branch, $request->has('manager_id'))) {
            return $denied;
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'barangay' => 'nullable|string|max:100',
            'city' => 'required|string|max:255',
            'district' => 'nullable|in:Poblacion,Talomo,Buhangin,Agdao,Toril,Bunawan,Calinan,Tugbok',
            'contact_number' => 'nullable|string',
            // Present = must be valid; a branch saved before this rule and still without coordinates must supply them now.
            'latitude' => [$branch->latitude === null ? 'required' : 'sometimes', 'numeric', 'between:-90,90'],
            'longitude' => [$branch->longitude === null ? 'required' : 'sometimes', 'numeric', 'between:-180,180'],
            'operating_hours' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,inactive',
            'guide_image_url' => 'nullable|string|max:500',
            'manager_id' => 'nullable',
        ]);

        $before = $branch->only(['address', 'barangay', 'city', 'district', 'latitude', 'longitude']);

        $branch->update([
            'name' => $request->name,
            'address' => $request->address,
            'landmark' => $request->landmark,
            'barangay' => $request->barangay,
            'city' => $request->city,
            'district' => $request->district,
            'contact_number' => $request->contact_number,
            'latitude' => $request->filled('latitude') ? $request->latitude : ($request->latitude === '' ? null : $branch->latitude),
            'longitude' => $request->filled('longitude') ? $request->longitude : ($request->longitude === '' ? null : $branch->longitude),
            'operating_hours' => $request->operating_hours,
            'status' => $request->status ?? $branch->status,
            'guide_image_url' => $request->filled('guide_image_url') ? $request->guide_image_url : ($request->guide_image_url === '' ? null : $branch->guide_image_url),
        ]);

        // Moving the pin or changing where the branch is sends it back for the admin to check again; editing hours,
        // contact or the manager does not.
        if ($branch->verification_status !== 'pending' && $this->locationChanged($before, $branch)) {
            $branch->update(['verification_status' => 'pending', 'verified_at' => null, 'verified_by' => null, 'verification_note' => null]);
        }

        if ($request->has('manager_id')) {
            $managerId = $request->input('manager_id');
            // Clear current manager flag for this branch
            StaffProfile::where('store_branch_id', $branch->id)
                ->where('is_branch_manager', true)
                ->update(['is_branch_manager' => false]);

            if ($managerId) {
                $staff = StaffProfile::where('store_id', $storeId)->find($managerId);
                if ($staff) {
                    $staff->update([
                        'store_branch_id' => $branch->id,
                        'is_branch_manager' => true,
                    ]);
                    $bmRole = Role::where('name', 'branch_manager')->first();
                    if ($bmRole && $staff->user) {
                        $staff->user->roles()->syncWithoutDetaching([$bmRole->id]);
                    }
                }
            }
        }

        $branch->loadCount(['staffProfiles', 'jobOrders']);
        $branch->load([
            'manager.user:id,name,email,phone,profile_picture',
            'staffProfiles' => function ($q) {
                $q->where('is_branch_manager', true)->with('user:id,name,email,phone,profile_picture');
            },
        ]);

        return response()->json(['success' => true, 'message' => 'Branch updated successfully.', 'data' => $branch]);
    }

    public function setMain(Request $request, $storeId, StoreBranch $branch)
    {
        if ($branch->store_id != $storeId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        // Reset all branches of this store to is_main = false
        StoreBranch::where('store_id', $storeId)->update(['is_main' => false]);
        // Set this branch to is_main = true
        $branch->update(['is_main' => true]);

        $branch->loadCount(['staffProfiles', 'jobOrders']);
        $branch->load([
            'manager.user:id,name,email,phone,profile_picture',
            'staffProfiles' => function ($q) {
                $q->where('is_branch_manager', true)->with('user:id,name,email,phone,profile_picture');
            },
        ]);

        // Audit log for accountability
        $branch->store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'branch_set_main',
            'model_type' => StoreBranch::class,
            'model_id' => $branch->id,
            'payload' => [
                'name' => $branch->name,
                'description' => "Designated \"{$branch->name}\" as Primary Headquarters",
            ],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$branch->name} is now designated as the Main Branch.",
            'data' => $branch,
        ]);
    }

    public function destroy(Request $request, $storeId, StoreBranch $branch)
    {
        if ($branch->store_id != $storeId) {
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
        $branch->store->auditLogs()->create([
            'user_id' => $request->user()->id,
            'action' => 'branch_deleted',
            'model_type' => StoreBranch::class,
            'model_id' => $branch->id,
            'payload' => ['name' => $branch->name],
            'ip_address' => $request->ip(),
        ]);

        $branch->delete();

        return response()->json(['success' => true, 'message' => 'Branch deleted successfully.']);
    }

    /**
     * Lets an owner/branch manager paste whatever Google Maps' own "Share"
     * button gives them (a short maps.app.goo.gl link, or the full URL
     * copied straight from the address bar) instead of having to go hunt
     * down raw latitude/longitude themselves. Manual lat/lng entry (and the
     * existing click-to-pin map picker) both stay available — this is an
     * additional, easier path to the same two numbers, not a replacement.
     */
    public function resolveMapsLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $coords = GoogleMapsLinkResolver::resolve($validated['url']);

        if (! $coords) {
            return response()->json([
                'success' => false,
                'message' => "Couldn't find a location in that link. Make sure it's copied from Google Maps' Share button, or enter the coordinates manually below.",
            ], 422);
        }

        return response()->json(['success' => true, 'data' => $coords]);
    }

    /**
     * Did the place move? Text fields are compared as text, the pin numerically (about 1 metre) — "7.0911" and the stored
     * "7.09110000" are the same spot and must not send a verified branch back for checking.
     */
    private function locationChanged(array $before, StoreBranch $branch): bool
    {
        foreach (['address', 'barangay', 'city', 'district'] as $field) {
            if (trim((string) ($before[$field] ?? '')) !== trim((string) $branch->{$field})) {
                return true;
            }
        }
        foreach (['latitude', 'longitude'] as $field) {
            if (abs((float) ($before[$field] ?? 0) - (float) $branch->{$field}) > 0.00001) {
                return true;
            }
        }

        return false;
    }
}
