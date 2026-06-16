<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * The user's learned shopping profile — long-term, cross-chat preferences the
 * AI assistant maintains (sizes, brands, categories, budget, style notes).
 * Distinct from the regular profile (address/phone) and from chat messages.
 */
class ShoppingProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()->shopping_profile ?? (object) [],
        ]);
    }

    /**
     * Merge-update the profile. The AI sends only the keys it learned; we deep
     * merge so nothing previously learned is lost.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'profile' => 'required|array',
        ]);

        $user = $request->user();
        $current = $user->shopping_profile ?? [];
        $merged = $this->deepMerge($current, $validated['profile']);
        $merged['updated_at'] = now()->toIso8601String();

        $user->shopping_profile = $merged;
        $user->save();

        return response()->json(['success' => true, 'data' => $merged]);
    }

    private function deepMerge(array $current, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (is_array($value) && isset($current[$key]) && is_array($current[$key])) {
                // Associative → recurse; list → union de-duped.
                $current[$key] = array_is_list($value)
                    ? array_values(array_unique([...$current[$key], ...$value]))
                    : $this->deepMerge($current[$key], $value);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }
}
