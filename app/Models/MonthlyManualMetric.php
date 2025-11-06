<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlyManualMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'year',
        'month',
        'total_revenue',
        'total_expenses',
        'total_profit',
        'total_orders',
        'boxes_extra_small',
        'boxes_small',
        'boxes_medium',
        'boxes_large',
        'boxes_extra_large',
        'total_conversations',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'total_revenue' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'total_profit' => 'decimal:2',
        'total_orders' => 'integer',
        'boxes_extra_small' => 'integer',
        'boxes_small' => 'integer',
        'boxes_medium' => 'integer',
        'boxes_large' => 'integer',
        'boxes_extra_large' => 'integer',
        'total_conversations' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get total boxes for the month
     */
    public function getTotalBoxesAttribute(): int
    {
        return $this->boxes_extra_small + 
               $this->boxes_small + 
               $this->boxes_medium + 
               $this->boxes_large + 
               $this->boxes_extra_large;
    }

    /**
     * Get or create metric for a specific month
     */
    public static function getOrCreateForMonth(int $year, int $month, int $userId): self
    {
        return self::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['created_by' => $userId]
        );
    }
}