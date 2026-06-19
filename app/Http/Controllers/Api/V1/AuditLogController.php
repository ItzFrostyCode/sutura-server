<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Shop $shop, Request $request): JsonResponse
    {
        $query = $shop->auditLogs()->with('user:id,name');

        if ($request->has('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        if ($request->has('action')) {
            $query->where('action', $request->action);
        }

        return response()->json([
            'success' => true,
            'data' => $query->latest()->paginate($request->input('per_page', 20))
        ]);
    }
}
