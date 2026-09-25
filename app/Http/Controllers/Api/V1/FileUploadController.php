<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{
    private const STORES_DIR = 'stores/';

    private const NO_FILE_UPLOADED = 'No file uploaded';

    // iPhones/Macs save camera photos as HEIC by default, which browsers
    // can't display and PHP's GD (what XAMPP ships) can't decode -- neither
    // the `image` nor `mimes` rule below accepts it, so without this message
    // a Mac/iPhone user just sees a generic "invalid file" error with no clue
    // why a photo that opens fine in Preview won't upload.
    private const INVALID_IMAGE_MESSAGE = 'Only JPG, PNG, or WEBP images are supported. '
        .'If this photo was taken on an iPhone/Mac it may be saved as HEIC -- go to '
        .'iPhone Settings -> Camera -> Formats -> "Most Compatible" (or export/share it '
        .'as JPEG from Photos) before uploading.';

    private const INVALID_ATTACHMENT_MESSAGE = 'Only JPG, PNG, WEBP images or MP4/MOV/AVI/WEBM videos are supported. '
        .'If this was taken on an iPhone/Mac, photos may be saved as HEIC -- go to '
        .'iPhone Settings -> Camera -> Formats -> "Most Compatible" (or export/share it '
        .'as JPEG from Photos) before uploading.';

    // Single source of truth for which disk uploads live on. Switching this
    // to 's3' at the real September migration updates both where files are
    // stored AND where their URLs are generated from, together -- they can't
    // drift apart again the way they did before (store() said 'public',
    // url() used the app's default disk instead, which happened to be a
    // *different* disk with no 'url' config of its own).
    private const UPLOAD_DISK = 'public';

    public function store(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'file.image' => self::INVALID_IMAGE_MESSAGE,
            'file.mimes' => self::INVALID_IMAGE_MESSAGE,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::STORES_DIR.$store->id.'/catalog', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path),
                ],
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    public function uploadSupportAttachment(Request $request, Store $store): JsonResponse
    {
        if ((int) $request->header('Content-Length') > 52428800) {
            return response()->json(['message' => 'Payload too large'], 413);
        }

        $request->validate([
            'file' => 'required|file|mimes:jpeg,png,jpg,webp,mp4,mov,avi,webm|max:51200', // 50MB
        ], [
            'file.mimes' => self::INVALID_ATTACHMENT_MESSAGE,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::STORES_DIR.$store->id.'/support', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path),
                ],
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    public function uploadPublicReceipt(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'file.image' => self::INVALID_IMAGE_MESSAGE,
            'file.mimes' => self::INVALID_IMAGE_MESSAGE,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::STORES_DIR.$store->id.'/receipts', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path),
                ],
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }

    /**
     * Reference/design images a customer attaches to a bulk/custom order
     * inquiry (e.g. a jersey design mockup, an existing uniform photo).
     */
    public function uploadPublicReferenceImage(Request $request, Store $store): JsonResponse
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], [
            'file.image' => self::INVALID_IMAGE_MESSAGE,
            'file.mimes' => self::INVALID_IMAGE_MESSAGE,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $path = $file->store(self::STORES_DIR.$store->id.'/references', self::UPLOAD_DISK);

            return response()->json([
                'success' => true,
                'data' => [
                    'url' => Storage::disk(self::UPLOAD_DISK)->url($path),
                ],
            ]);
        }

        return response()->json(['success' => false, 'message' => self::NO_FILE_UPLOADED], 400);
    }
}
