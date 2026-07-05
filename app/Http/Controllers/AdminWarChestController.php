<?php

namespace App\Http\Controllers;

use App\Models\WarChestAccount;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminWarChestController extends Controller
{
    /**
     * List all accounts + overall totals.
     */
    public function index()
    {
        $accounts = WarChestAccount::orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'data' => $accounts,
            'totals' => [
                'current_balance' => round((float) $accounts->sum('current_balance'), 2),
                'target_amount' => round((float) $accounts->sum('target_amount'), 2),
            ],
        ]);
    }

    /**
     * Create an account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $account = WarChestAccount::create([
            'name' => $validated['name'],
            'payment_method' => $validated['payment_method'] ?? null,
            'current_balance' => $validated['current_balance'] ?? 0,
            'target_amount' => $validated['target_amount'] ?? 0,
            'currency' => $validated['currency'] ?? 'mxn',
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account created',
            'data' => $account,
        ], 201);
    }

    /**
     * Update an account (balance, target, name, etc. — editable any time).
     */
    public function update(Request $request, WarChestAccount $account)
    {
        $validated = $request->validate($this->rules($account->id, true));

        $account->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Account updated',
            'data' => $account->fresh(),
        ]);
    }

    /**
     * Delete an account.
     */
    public function destroy(WarChestAccount $account)
    {
        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account deleted',
        ]);
    }

    private function rules(?int $ignoreId = null, bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return [
            'name' => "$req|string|max:100",
            'payment_method' => [
                'nullable',
                'string',
                'max:20',
                Rule::unique('war_chest_accounts', 'payment_method')->ignore($ignoreId),
            ],
            'current_balance' => 'sometimes|numeric|min:0|max:9999999999.99',
            'target_amount' => 'sometimes|numeric|min:0|max:9999999999.99',
            'currency' => 'nullable|string|size:3',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
