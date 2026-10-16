<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreApplicationRequest;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreApplication;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Support\DavaoLocations;
use App\Support\ShopLogin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public side of shop onboarding (ported from sutura2's shop registration).
 *
 * Creates the owner record with a placeholder shop-domain login and an
 * unusable random password, so nobody can sign in yet. The store stays
 * status=pending (hidden from every public query, which already filters on
 * status=approved) until a System Admin approves it and issues the real
 * login + a temporary password (Admin\StoreApplicationController::approve),
 * emailed to the owner's contact_email. Unlike sutura2, that temporary
 * password is random — never lastname+birthday — and must be changed on
 * first sign-in.
 */
class StoreApplicationController extends Controller
{
    private static function privateDisk(): string
    {
        return config('filesystems.private_disk', 'local');
    }

    public function plans(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => SubscriptionPlan::where('is_active', true)
                ->orderBy('price_monthly')
                ->get(['id', 'name', 'slug', 'description', 'price_monthly', 'price_yearly', 'max_staff', 'max_branches', 'features']),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $email = trim((string) $request->input('email'));
        $storeId = $request->input('store_id');

        $query = Store::query()->with('owner');

        if ($storeId) {
            $query->where('id', $storeId);
        } elseif ($email) {
            $query->whereHas('owner', function ($q) use ($email) {
                $q->where('contact_email', $email)->orWhere('email', $email);
            });
        } else {
            return response()->json(['success' => false, 'message' => 'Email or store ID required.'], 422);
        }

        $store = $query->latest('id')->first();

        if (! $store) {
            return response()->json(['success' => false, 'message' => 'Application not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'status' => $store->status,
                'login_email' => $store->owner?->email,
                'rejection_reason' => $store->rejection_reason,
                'approved_at' => $store->approved_at,
            ],
        ]);
    }

    public function store(StoreApplicationRequest $request): JsonResponse
    {
        $plan = SubscriptionPlan::findOrFail($request->plan_id);
        $paths = $this->storeDocuments($request);

        $store = null;
        try {
            $store = $this->createApplication($request, $paths, $plan);
        } catch (\Throwable $e) {
            // Don't leave orphaned government IDs on disk for a rolled-back row.
            Storage::disk(self::privateDisk())->delete(collect($paths)->flatten()->filter()->all());
            throw $e;
        }

        return response()->json([
            'success' => true,
            'message' => 'Application submitted. We will email your shop login once it is approved.',
            'data' => [
                'contact_email' => $request->email,
                'store_id' => $store?->id,
                'store_name' => $store?->name,
                'status' => $store?->status ?? 'pending',
            ],
        ], 201);
    }

    private function createApplication(StoreApplicationRequest $request, array $paths, SubscriptionPlan $plan): Store
    {
        return DB::transaction(function () use ($request, $paths, $plan) {
            $fullName = trim((string) ($request->full_name ?? ''));
            if ($fullName !== '') {
                $parts = preg_split('/\s+/', $fullName);
                $suffixes = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];
                $suffix = null;
                if (count($parts) > 1 && in_array(strtolower(end($parts)), $suffixes, true)) {
                    $suffix = array_pop($parts);
                }
                $lastName = count($parts) > 1 ? array_pop($parts) : ($parts[0] ?? '');
                $middleName = null;
                if (count($parts) > 1 && strlen(rtrim(end($parts), '.')) === 1) {
                    $middleName = array_pop($parts);
                }
                $firstName = implode(' ', $parts) ?: $lastName;

                $firstName = $request->first_name ?: $firstName;
                $middleName = $request->middle_name ?: $middleName;
                $lastName = $request->last_name ?: $lastName;
                $suffix = $request->suffix ?: $suffix;
                $name = $fullName;
            } else {
                $firstName = $request->first_name;
                $middleName = $request->middle_name;
                $lastName = $request->last_name;
                $suffix = $request->suffix;
                $name = trim(preg_replace('/\s+/', ' ', "{$firstName} {$middleName} {$lastName} {$suffix}"));
            }

            $user = User::create([
                'name' => $name,
                'email' => ShopLogin::suggest($request->store_name),
                'contact_email' => $request->email,
                'phone' => $request->contact_number,
                // Never communicated to anyone — the account is unusable
                // until approval issues a real temporary password.
                'password' => Hash::make(Str::random(64)),
            ]);

            $role = Role::where('name', 'store_owner')->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            $store = Store::create([
                'owner_id' => $user->id,
                'name' => $request->store_name,
                'slug' => Str::slug($request->store_name).'-'.uniqid(),
                'address' => $request->address,
                'city' => $request->city,
                'province' => $request->province,
                'phone' => $request->contact_number,
                'email' => $request->email,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'specializations' => array_values(array_unique($request->specializations)),
                'status' => 'pending',
            ]);

            $store->branches()->create([
                'name' => $request->store_name.' — Main',
                'slug' => Str::slug($request->store_name).'-'.uniqid(),
                'address' => $request->address,
                // Official spelling when the typed barangay is one of Davao City's 182; otherwise kept as typed
                // (an application is never refused over it — the admin checks the place before approval).
                'barangay' => DavaoLocations::findBarangay($request->barangay)['name'] ?? $request->barangay,
                // Only the 11 districts discovery filters on; a reverse-geocoded
                // barangay name would never match that filter anyway — the typed barangay's district fills the gap.
                'district' => in_array($request->district, DavaoLocations::districts(), true)
                    ? $request->district
                    : (DavaoLocations::findBarangay($request->barangay)['district'] ?? null),
                'city' => $request->city,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'contact_number' => $request->contact_number,
                'is_main' => true,
                'status' => 'active',
                'verification_status' => 'pending',   // verified when the admin approves the application
            ]);

            StoreApplication::create([
                'user_id' => $user->id,
                'store_id' => $store->id,
                'first_name' => $firstName,
                'middle_name' => $middleName,
                'last_name' => $lastName,
                'suffix' => $suffix,
                'birthday' => $request->birthday,
                'contact_number' => $request->contact_number,
                'requested_plan_id' => $plan->id,
                'billing_cycle' => $request->billing_cycle,
                'quoted_price' => $request->billing_cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly,
                'payment_method' => $request->payment_method,
                'government_id_type' => $request->government_id_type,
                ...$paths,
            ]);

            return $store;
        });
    }

    /** @return array<string, mixed> column => stored path */
    private function storeDocuments(StoreApplicationRequest $request): array
    {
        $put = fn (UploadedFile $file, string $folder) => $file->store("store-applications/{$folder}", self::privateDisk());

        return [
            'landmark_image_path' => $put($request->file('landmark_image'), 'landmarks'),
            'dti_registration_path' => $put($request->file('dti_registration'), 'dti'),
            'tin_id_path' => $put($request->file('tin_id'), 'tin'),
            'brgy_clearance_path' => $put($request->file('brgy_clearance'), 'barangay'),
            'government_id_path' => $put($request->file('government_id'), 'government-id'),
            'payment_receipt_path' => $put($request->file('payment_receipt'), 'receipts'),
            'business_permit_paths' => collect($request->file('business_permits', []))
                ->map(fn (UploadedFile $file) => $put($file, 'permits'))
                ->values()
                ->all(),
        ];
    }
}
