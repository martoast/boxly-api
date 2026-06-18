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
     * Update the profile. Two modes:
     *  - default (AI tool): deep-merge the partial keys it learned, so nothing
     *    previously learned is lost (lists union, keys overwrite).
     *  - replace=true (the user editing their own profile in the web app): set
     *    the profile to exactly what was sent, so removals (a brand, a size)
     *    actually stick — a union-merge could never delete.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'profile' => 'required|array',
            'replace' => 'sometimes|boolean',
        ]);

        $user = $request->user();
        $merged = $request->boolean('replace')
            ? $validated['profile']
            : $this->deepMerge($user->shopping_profile ?? [], $validated['profile']);
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
