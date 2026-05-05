<?php

namespace App\Http\Controllers;

use App\Models\ShopHero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ShopHeroController extends Controller
{
    /**
     * Public — what the /shop storefront should render.
     * Returns null when no active campaign is configured (frontend
     * falls back to a clean default hero).
     */
    public function publicShow()
    {
        return response()->json([
            'success' => true,
            'data'    => ShopHero::active(),
        ]);
    }

    /**
     * Admin — fetch the current active hero (or null) for the edit
     * form. Same payload as publicShow but kept on a separate route
     * for clarity / future-proofing (e.g. listing all campaigns).
     */
    public function show()
    {
        return response()->json([
            'success' => true,
            'data'    => ShopHero::active(),
        ]);
    }

    /**
     * Admin — upsert the active hero. If no active row exists, create
     * one. Otherwise update the latest active row in place. Toggling
     * is_active=false here effectively retires the campaign.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'title'     => 'required|string|max:120',
            'subtitle'  => 'nullable|string|max:240',
            'cta_label' => 'required|string|max:60',
            'cta_link'  => 'required|string|max:1000',
            'is_active' => 'nullable|boolean',
        ]);

        $hero = ShopHero::active() ?? new ShopHero();
        $hero->fill($validated);
        if (! $request->has('is_active')) $hero->is_active = true;
        $hero->save();

        return response()->json([
            'success' => true,
            'data'    => $hero->fresh(),
        ]);
    }

    /**
     * Admin — upload a new hero image. Stored on Spaces under
     * shop-heroes/, the URL replaces image_url on the active hero.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:10240',
        ]);

        try {
            $hero = ShopHero::active() ?? ShopHero::create([
                'title'     => 'Tienda Boxly',
                'cta_label' => 'Comprar',
                'cta_link'  => '/shop?view=all',
                'is_active' => true,
            ]);

            // Delete old image if present
            if ($hero->image_path) {
                Storage::disk('spaces')->delete($hero->image_path);
            }

            $file = $request->file('image');
            $filename = "hero-" . $hero->id . "-" . time() . "." . $file->getClientOriginalExtension();
            $path = Storage::disk('spaces')->putFileAs('shop-heroes', $file, $filename, 'public');
            $url  = config('filesystems.disks.spaces.url') . '/' . $path;

            $hero->update([
                'image_path' => $path,
                'image_url'  => $url,
            ]);

            return response()->json([
                'success' => true,
                'data'    => $hero->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Shop hero image upload failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo subir la imagen: ' . $e->getMessage(),
            ], 500);
        }
    }
}
