<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->roles()->whereIn('name', $roles)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Insufficient permissions.'
            ], 403);
        }

        // Admin routes intentionally act across every shop; skip tenant scoping.
        if (in_array('admin', $roles, true)) {
            return $next($request);
        }

        // Every other role-gated route is scoped to a single shop. Verify the
        // authenticated user actually belongs to the {shop} in the URL —
        // having the role globally is not enough, otherwise staff/branch
        // managers of one shop could read or modify another shop's data.
        //
        // Some controllers accept the route segment as a raw id (e.g. `$shopId`)
        // instead of type-hinting `Shop $shop`, so implicit route-model binding
        // never runs for them. Resolve it manually in that case rather than
        // skipping the check.
        $shopParam = $request->route('shop');
        if ($shopParam !== null) {
            $shop = $shopParam instanceof Shop ? $shopParam : Shop::find($shopParam);

            if (! $shop) {
                return response()->json([
                    'success' => false,
                    'message' => 'Shop not found.'
                ], 404);
            }

            $belongsToShop = $user->hasRole('shop_owner')
                ? $shop->owner_id === $user->id
                : $user->staffProfile?->shop_id === $shop->id;

            if (! $belongsToShop) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not belong to this shop.'
                ], 403);
            }
        }

        return $next($request);
    }
}
