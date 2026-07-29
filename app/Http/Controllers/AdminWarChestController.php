<?php

namespace App\Http\Controllers;

use App\Models\WarChestAccount;
use App\Models\WarChestTransaction;
use Illuminate\Http\Request;

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
     * Single account.
     */
    public function show(WarChestAccount $account)
    {
        return response()->json(['success' => true, 'data' => $account]);
    }

    /**
     * Create an account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        $account = WarChestAccount::create([
            'name' => $validated['name'],
            'current_balance' => $validated['current_balance'] ?? 0,
            'target_amount' => $validated['target_amount'] ?? 0,
            'currency' => $validated['currency'] ?? 'mxn',
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Log an opening-balance entry so the checkbook reconciles.
        if ((float) $account->current_balance > 0) {
            $account->transactions()->create([
                'direction' => 'in',
                'amount' => round((float) $account->current_balance, 2),
                'balance_after' => round((float) $account->current_balance, 2),
                'source_type' => 'opening',
                'description' => 'Saldo inicial',
                'occurred_at' => now(),
                'created_by' => $request->user()->id,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Account created',
            'data' => $account,
        ], 201);
    }

    /**
     * Update an account (balance, target, name, etc. — editable any time).
     * A manual balance change is recorded as an "adjustment" ledger entry.
     */
    public function update(Request $request, WarChestAccount $account)
    {
        $validated = $request->validate($this->rules(true));

        $oldBalance = (float) $account->current_balance;
        $account->update($validated);

        if ($request->has('current_balance')) {
            $delta = round((float) $account->current_balance - $oldBalance, 2);
            if ($delta != 0.0) {
                $account->transactions()->create([
                    'direction' => $delta >= 0 ? 'in' : 'out',
                    'amount' => round(abs($delta), 2),
                    'balance_after' => round((float) $account->current_balance, 2),
                    'source_type' => 'adjustment',
                    'description' => 'Ajuste manual de saldo',
                    'occurred_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Account updated',
            'data' => $account->fresh(),
        ]);
    }

    /**
     * Delete an account (its transactions cascade).
     */
    public function destroy(WarChestAccount $account)
    {
        $account->delete();

        return response()->json(['success' => true, 'message' => 'Account deleted']);
    }

    /**
     * Ledger for one account. Optional ?year=&month= filter. Returns the
     * paginated transactions plus in/out/net totals for the filtered range.
     */
    public function transactions(Request $request, WarChestAccount $account)
    {
        $query = $account->transactions()->getQuery();

        if ($request->filled('year')) {
            $query->whereYear('occurred_at', $request->year);
            if ($request->filled('month')) {
                $query->whereMonth('occurred_at', $request->month);
            }
        }

        $summaryBase = clone $query;
        $in = (float) (clone $summaryBase)->where('direction', 'in')->sum('amount');
        $out = (float) (clone $summaryBase)->where('direction', 'out')->sum('amount');

        // Recompute the running balance across the ENTIRE history in true
        // chronological order (occurred_at, id). A backdated entry reorders
        // everything after it. Invariant: the cumulative sum == current_balance.
        $running = [];
        $sum = 0.0;
        $account->transactions()
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['id', 'direction', 'amount'])
            ->each(function ($tx) use (&$running, &$sum) {
                $sum += $tx->direction === 'in' ? (float) $tx->amount : -1 * (float) $tx->amount;
                $running[$tx->id] = round($sum, 2);
            });

        $transactions = $query
            ->orderBy('occurred_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($request->get('per_page', 50));

        // Overlay the recomputed running balance onto the returned page.
        $transactions->getCollection()->transform(function ($tx) use ($running) {
            $tx->balance_after = $running[$tx->id] ?? $tx->balance_after;
            return $tx;
        });

        return response()->json([
            'success' => true,
            'account' => $account,
            'data' => $transactions,
            'summary' => [
                'in' => round($in, 2),
                'out' => round($out, 2),
                'net' => round($in - $out, 2),
            ],
        ]);
    }

    /**
     * Add a manual ledger entry (money in/out not tied to an order/expense).
     * Adjusts the account balance and records the transaction.
     */
    public function storeTransaction(Request $request, WarChestAccount $account)
    {
        $validated = $request->validate([
            'direction' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01|max:9999999999.99',
            'description' => 'nullable|string|max:255',
            'occurred_at' => 'nullable|date',
        ]);

        $delta = ($validated['direction'] === 'in' ? 1 : -1) * (float) $validated['amount'];

        $txn = $account->move($delta, [
            'source_type' => 'manual',
            'description' => $validated['description'] ?? null,
            'occurred_at' => $validated['occurred_at'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Transaction added',
            'data' => $txn,
            'account' => $account->fresh(),
        ], 201);
    }

    /**
     * Delete any ledger entry and reverse its balance effect. The checkbook is
     * fully admin-maintained, so historical order/expense entries left over from
     * the old automatic routing can be cleaned out here too.
     */
    public function destroyTransaction(WarChestAccount $account, WarChestTransaction $transaction)
    {
        if ($transaction->account_id !== $account->id) {
            return response()->json(['success' => false, 'message' => 'Transaction not found'], 404);
        }

        // Reverse the balance effect (in added money, out removed it).
        $effect = $transaction->direction === 'in' ? (float) $transaction->amount : -1 * (float) $transaction->amount;
        $account->current_balance = round((float) $account->current_balance - $effect, 2);
        $account->save();

        $transaction->delete();

        return response()->json([
            'success' => true,
            'message' => 'Transaction deleted',
            'account' => $account->fresh(),
        ]);
    }

    private function rules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes|required' : 'required';

        return [
            'name' => "$req|string|max:100",
            'current_balance' => 'sometimes|numeric|min:0|max:9999999999.99',
            'target_amount' => 'sometimes|numeric|min:0|max:9999999999.99',
            'currency' => 'nullable|string|size:3',
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
