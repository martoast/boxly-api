<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DropOffReceipt extends Model
{
    protected $fillable = [
        'receipt_number',
        'user_id',
        'created_by',
        'description',
        'dropped_off_at',
        'images',
        'email_sent_at',
    ];

    protected $casts = [
        'dropped_off_at' => 'date',
        'images'         => 'array',
        'email_sent_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function generateReceiptNumber(): string
    {
        do {
            $number = 'DO' . strtoupper(Str::random(6));
        } while (self::where('receipt_number', $number)->exists());

        return $number;
    }
}
