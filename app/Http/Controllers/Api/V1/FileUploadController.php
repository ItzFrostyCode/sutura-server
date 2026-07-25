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

    // Single source of truth for which disk uploads live on. Switching this
    // to 's3' at the real September migration updates both where files are
    // stored AND where their URLs are generated from, together -- they can't
    // drift apart again the way they did before (store() said 'public',
    // url() used the app's default disk instead, which happened to be a
    // *different* disk with no 'url' config of its own).
    private const UPLOAD_DISK = 'public';

    public function store(Request $request, Shop $shop): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/catalog', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    public function uploadSupportAttachment(Request $request, Shop $shop): JsonResponse
    {
        if ((int)$request->header('Content-Length') > 52428800) {
            return response()->json(['message' => 'Payload too large'], 413);
        }

        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi,webm|max:51200', // 50MB
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/support', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path)
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
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/receipts', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    /**
     * Reference/design images a customer attaches to a bulk/custom order
     * inquiry (e.g. a jersey design mockup, an existing uniform photo).
     */
    public function uploadPublicReferenceImage(Request $request, Shop $shop): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::SHOPS_DIR . $shop->id . '/references', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path)
                ]
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }
}
