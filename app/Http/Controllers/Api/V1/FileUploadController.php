<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    public function store(Request $request, Shop $shop): JsonResponse
    {
        if ($request->user()->cannot('view', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store('shops/' . $shop->id . '/catalog', 'public');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'url' => config('app.url') . Storage::url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => 'No file uploaded'], 400);
    }
}
