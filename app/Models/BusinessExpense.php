<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessExpense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category',
        'subcategory',
        'amount',
        'currency',
        'expense_date',
        'description',
        'reference_number',
        'metadata',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'metadata' => 'array',
    ];

    // Expense categories
    const CATEGORY_SHIPPING = 'shipping'; // Actual shipping costs to customers
    const CATEGORY_ADS = 'ads';
    const CATEGORY_SOFTWARE = 'software';
    const CATEGORY_OFFICE = 'office';
    const CATEGORY_PO_BOX = 'po_box';
    const CATEGORY_MISC = 'misc';

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeInMonth($query, int $year, int $month)
    {
        return $query->whereYear('expense_date', $year)
                     ->whereMonth('expense_date', $month);
    }

    public function scopeInYear($query, int $year)
    {
        return $query->whereYear('expense_date', $year);
    }

    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('expense_date', [$startDate, $endDate]);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public static function getCategories(): array
    {
        return [
            self::CATEGORY_SHIPPING => 'Shipping Costs',
            self::CATEGORY_ADS => 'Advertising',
            self::CATEGORY_SOFTWARE => 'Software & Tools',
            self::CATEGORY_OFFICE => 'Office Expenses',
            self::CATEGORY_PO_BOX => 'PO Box Rental',
            self::CATEGORY_MISC => 'Miscellaneous',
        ];
    }
}