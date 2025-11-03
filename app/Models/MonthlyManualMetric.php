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
        'total_conversations',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'total_conversations' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function getOrCreateForMonth(int $year, int $month, int $userId): self
    {
        return self::firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['created_by' => $userId, 'total_conversations' => 0]
        );
    }
}