<?php

namespace App\Http\Controllers;

use App\Models\BusinessExpense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminBusinessExpenseController extends Controller
{
    /**
     * List expenses with filtering
     */
    public function index(Request $request)
    {
        $query = BusinessExpense::with('creator');

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by subcategory
        if ($request->has('subcategory')) {
            $query->where('subcategory', $request->subcategory);
        }

        // Filter by date range
        if ($request->has('start_date') && $request->has('end_date')) {
            $query->inDateRange($request->start_date, $request->end_date);
        }

        // Filter by month
        if ($request->has('year') && $request->has('month')) {
            $query->inMonth($request->year, $request->month);
        }

        // Filter by year
        if ($request->has('year') && !$request->has('month')) {
            $query->inYear($request->year);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'expense_date');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $expenses = $query->paginate($request->get('per_page', 50));

        return response()->json([
            'success' => true,
            'data' => $expenses
        ]);
    }

    /**
     * Show single expense
     */
    public function show(BusinessExpense $expense)
    {
        $expense->load('creator');

        return response()->json([
            'success' => true,
            'data' => $expense
        ]);
    }

    /**
     * Create expense
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:50',
            'subcategory' => 'nullable|string|max:50',
            'amount' => 'required|numeric|min:0.01|max:9999999.99',
            'currency' => 'nullable|string|size:3',
            'expense_date' => 'required|date',
            'description' => 'nullable|string|max:1000',
            'reference_number' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ]);

        try {
            $expense = BusinessExpense::create([
                ...$validated,
                'created_by' => $request->user()->id,
                'currency' => $validated['currency'] ?? 'mxn',
            ]);

            Log::info('Business expense created', [
                'expense_id' => $expense->id,
                'category' => $expense->category,
                'amount' => $expense->amount,
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Expense created successfully',
                'data' => $expense->fresh('creator')
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create expense', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create expense',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update expense
     */
    public function update(Request $request, BusinessExpense $expense)
    {
        $validated = $request->validate([
            'category' => 'sometimes|required|string|max:50',
            'subcategory' => 'nullable|string|max:50',
            'amount' => 'sometimes|required|numeric|min:0.01|max:9999999.99',
            'currency' => 'nullable|string|size:3',
            'expense_date' => 'sometimes|required|date',
            'description' => 'nullable|string|max:1000',
            'reference_number' => 'nullable|string|max:100',
            'metadata' => 'nullable|array',
        ]);

        try {
            $expense->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Expense updated successfully',
                'data' => $expense->fresh('creator')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update expense',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Delete expense
     */
    public function destroy(BusinessExpense $expense)
    {
        try {
            $expense->delete();

            return response()->json([
                'success' => true,
                'message' => 'Expense deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete expense',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Bulk import
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'expenses' => 'required|array|min:1|max:500',
            'expenses.*.category' => 'required|string|max:50',
            'expenses.*.amount' => 'required|numeric|min:0.01',
            'expenses.*.expense_date' => 'required|date',
            'expenses.*.description' => 'nullable|string|max:1000',
        ]);

        DB::beginTransaction();

        try {
            $created = [];

            foreach ($request->expenses as $expenseData) {
                $expense = BusinessExpense::create([
                    ...$expenseData,
                    'created_by' => $request->user()->id,
                    'currency' => $expenseData['currency'] ?? 'mxn',
                ]);

                $created[] = $expense;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => count($created) . ' expenses imported successfully',
                'data' => $created
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to import expenses',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get categories
     */
    public function categories()
    {
        return response()->json([
            'success' => true,
            'data' => BusinessExpense::getCategories()
        ]);
    }
}