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
        'scope',
        'category',
        'subcategory',
        'amount',
        'currency',
        'payment_method',
        'expense_date',
        'description',
        'reference_number',
        'metadata',
        'created_by',
    ];

    // Which War Chest account the expense was paid from (debits that balance).
    // Mirrors the War Chest account list 1:1 — a chip with no matching
    // account is an expense that can never be reconciled against a balance.
    const PAYMENT_METHODS = ['NU', 'HSBC', 'Stripe US', 'Stripe MX', 'US Bank Boxly LLC'];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'metadata' => 'array',
    ];

    // Scope: business expenses feed the P&L / dashboard profit; personal
    // expenses (the owners' own rent/food/etc.) are tracked separately and
    // NEVER counted against business profit.
    const SCOPE_BUSINESS = 'business';
    const SCOPE_PERSONAL = 'personal';

    // Business expense categories
    const CATEGORY_SHIPPING = 'shipping'; // Actual shipping costs to customers
    const CATEGORY_ADS = 'ads';
    const CATEGORY_SOFTWARE = 'software';
    const CATEGORY_OFFICE = 'office';
    const CATEGORY_PO_BOX = 'po_box';
    const CATEGORY_MISC = 'misc';

    // Personal expense categories (scope = personal)
    const PERSONAL_CATEGORY_RENT = 'rent';
    const PERSONAL_CATEGORY_FOOD = 'food';
    const PERSONAL_CATEGORY_MISC = 'misc';

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

    // Only business expenses (used for all dashboard / profit math).
    public function scopeBusiness($query)
    {
        return $query->where('scope', self::SCOPE_BUSINESS);
    }

    // Only personal expenses (owners' own spending — never in business profit).
    public function scopePersonal($query)
    {
        return $query->where('scope', self::SCOPE_PERSONAL);
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

    public static function getPersonalCategories(): array
    {
        return [
            self::PERSONAL_CATEGORY_RENT => 'Rent',
            self::PERSONAL_CATEGORY_FOOD => 'Food',
            self::PERSONAL_CATEGORY_MISC => 'Miscellaneous',
        ];
    }

    public static function getScopes(): array
    {
        return [self::SCOPE_BUSINESS, self::SCOPE_PERSONAL];
    }
}