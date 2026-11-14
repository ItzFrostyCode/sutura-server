<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\GoogleMapsLinkResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public twin of StoreBranchController::resolveMapsLink for the location
 * picker (shop registration runs signed out). A browser can't follow a
 * maps.app.goo.gl redirect cross-origin, so the server expands it — through
 * GoogleMapsLinkResolver, which only ever fetches https Google hosts.
 */
class MapLinkController extends Controller
{
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $coords = GoogleMapsLinkResolver::resolve($validated['url']);

        if (! $coords) {
            return response()->json(['success' => false]);
        }

        return response()->json([
            'success' => true,
            'lat' => $coords['latitude'],
            'lng' => $coords['longitude'],
        ]);
    }
}
