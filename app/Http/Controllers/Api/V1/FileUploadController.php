<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    private const SHOPS_DIR = 'shops/';
    private const NO_FILE_UPLOADED = 'No file uploaded';

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
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/catalog', 'public');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'url' => config('app.url') . Storage::url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    public function uploadSupportAttachment(Request $request, Shop $shop): JsonResponse
    {
        if ($request->user()->cannot('view', $shop) && !$request->user()->hasRole('shop_owner')) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ((int)$request->header('Content-Length') > 52428800) {
            return response()->json(['message' => 'Payload too large'], 413);
        }

        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi,webm|max:51200', // 50MB
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/support', 'public');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'url' => config('app.url') . Storage::url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    public function uploadPublicReceipt(Request $request, Shop $shop): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/receipts', 'public');
            
            return response()->json([
                'success' => true,
                'data' => [
                    'url' => config('app.url') . Storage::url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }
}
