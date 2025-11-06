<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyManualMetric extends Model
{
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
        'is_manual_mode', // 👈 NEW FLAG
        'notes',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
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
        'is_manual_mode' => 'boolean', // 👈 NEW FLAG
    ];

    protected $appends = ['total_boxes'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTotalBoxesAttribute()
    {
        return $this->boxes_extra_small + 
               $this->boxes_small + 
               $this->boxes_medium + 
               $this->boxes_large + 
               $this->boxes_extra_large;
    }

    public static function getOrCreateForMonth(int $year, int $month, int $userId)
    {
        return self::firstOrCreate(
            [
                'year' => $year,
                'month' => $month,
            ],
            [
                'created_by' => $userId,
                'total_revenue' => 0,
                'total_expenses' => 0,
                'total_profit' => 0,
                'total_orders' => 0,
                'boxes_extra_small' => 0,
                'boxes_small' => 0,
                'boxes_medium' => 0,
                'boxes_large' => 0,
                'boxes_extra_large' => 0,
                'total_conversations' => 0,
                'is_manual_mode' => false, // 👈 Default to false
            ]
        );
    }
}