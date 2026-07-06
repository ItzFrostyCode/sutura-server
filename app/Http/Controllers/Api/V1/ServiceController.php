<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\StoreServiceRequest;
use App\Models\Shop;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    const CAT_TAILORING = 'Custom Tailoring & Bespoke';
    const CAT_ALTERATIONS = 'Alterations & Repairs';
    const CAT_SUBLIMATION = 'Sublimation & Digital Printing';
    const CAT_FASHION = 'Fashion & Designer Services';
    const CAT_EMBROIDERY = 'Embroidery & Detailing';
    const CAT_NON_APPAREL = 'Non-Apparel Sublimation & Merch';
    const CAT_COMMERCIAL = 'Commercial & Large Format Printing';
    const CAT_DIGITAL = 'Digital & Design Services';

    public function index(Request $request, Shop $shop): JsonResponse
    {
        $query = $shop->services()->with('pricing');

        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        return response()->json([
            'success' => true,
            'data' => $query->get()
        ]);
    }

    public function store(StoreServiceRequest $request, Shop $shop): JsonResponse
    {
        $service = $shop->services()->create($request->validated());

        return response()->json(['success' => true, 'data' => $service], 201);
    }

    public function update(StoreServiceRequest $request, Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $service->update($request->validated());

        return response()->json(['success' => true, 'data' => $service]);
    }

    public function destroy(Shop $shop, Service $service): JsonResponse
    {
        if ($service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $service->delete();

        return response()->json(['success' => true]);
    }

    public function restore(Shop $shop, int $serviceId): JsonResponse
    {
        $service = Service::onlyTrashed()->where('id', $serviceId)->first();

        if (!$service || $service->shop_id !== $shop->id) {
            return response()->json(['success' => false, 'message' => 'Deleted service not found.'], 404);
        }

        $service->restore();

        return response()->json(['success' => true, 'data' => $service->load('pricing')]);
    }

    public function populate(Request $request, Shop $shop): JsonResponse
    {
        $validated = $request->validate([
            'industry_type' => ['required', 'string', 'in:Tailoring,Sublimation,Fashion,Hybrid'],
        ]);

        $industryType = $validated['industry_type'];
        $defaultServices = [];

        // Define groups as Title => [Tags]
        $tailoringGroup = [
            ['name' => 'Bespoke & Custom Tailoring', 'tags' => ['Barong Tagalog', 'Suits & Blazers', 'Gowns', 'Uniforms Batch Order', 'Traditional Wear'], 'category' => self::CAT_TAILORING],
            ['name' => 'Alterations & Repairs', 'tags' => ['Pants/Jeans Alteration', 'Dress/Gown Adjustment', 'Zipper Repair/Replacement', 'Button & Hook Replacement', 'Patch Attachment'], 'category' => self::CAT_ALTERATIONS],
        ];

        $sublimationGroup = [
            ['name' => 'Sublimation & Printing', 'tags' => ['Full Sublimation Jersey', 'Sublimated Polo', 'Hoodie / Jacket', 'DTF Print', 'Silk Screen Print', 'Vinyl Heat Press', 'Rush Print'], 'category' => self::CAT_SUBLIMATION],
        ];

        $fashionGroup = [
            ['name' => 'Fashion & Designer Services', 'tags' => ['Fashion Design Sketch', 'Styling Consultation', 'Pattern Making', 'Fabric Sourcing', 'Rental Service', 'Event Package Styling'], 'category' => self::CAT_FASHION],
        ];

        $embroideryGroup = [
            ['name' => 'Embroidery & Detailing', 'tags' => ['Logo Embroidery', 'Name Embroidery', 'Appliqué & Patchwork', 'Beadwork & Sequins'], 'category' => self::CAT_EMBROIDERY],
        ];

        $merchGroup = [
            ['name' => 'Non-Apparel Merch', 'tags' => ['Mugs & Tumblers', 'Plates & Coasters', 'Keychains & Ref Magnets', 'Tote & Drawstring Bags', 'Lanyards & ID Straps', 'Puzzles'], 'category' => self::CAT_NON_APPAREL],
        ];

        $commercialGroup = [
            ['name' => 'Commercial Printing', 'tags' => ['Tarpaulin & Banners', 'Stickers & Vinyl Labels', 'Custom Packaging Boxes', 'BIR Receipts & Invoices'], 'category' => self::CAT_COMMERCIAL],
            ['name' => 'Digital & Design Services', 'tags' => ['Custom Layout Design', 'Mockup Generation', 'Mockup Bundle Sales', 'Logo Design'], 'category' => self::CAT_DIGITAL],
        ];

        switch ($industryType) {
            case 'Tailoring':
                $defaultServices = array_merge($tailoringGroup, $embroideryGroup);
                break;
            case 'Sublimation':
                $defaultServices = array_merge($sublimationGroup, $merchGroup, $commercialGroup);
                break;
            case 'Fashion':
                $defaultServices = array_merge($fashionGroup, $tailoringGroup, $embroideryGroup);
                break;
            case 'Hybrid':
                $defaultServices = array_merge($tailoringGroup, $sublimationGroup, $fashionGroup, $embroideryGroup, $merchGroup, $commercialGroup);
                break;
            default:
                $defaultServices = [];
                break;
        }

        foreach ($defaultServices as $srv) {
            $shop->services()->updateOrCreate(
                ['shop_id' => $shop->id, 'name' => $srv['name']],
                [
                    'category' => $srv['category'] ?? null,
                    'tags' => $srv['tags'],
                    'is_active' => true,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Default service catalog populated successfully with real-world pricing logic.',
            'data' => $shop->services()->with('pricing')->get(),
        ]);
    }
}
