<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Affiliate extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_ACTIVE = 'active';
    const STATUS_SUSPENDED = 'suspended';

    const COMMISSION_TYPE_PERCENTAGE = 'percentage';
    const COMMISSION_TYPE_FIXED = 'fixed';

    protected $fillable = [
        'user_id',
        'affiliate_code',
        'commission_type',
        'commission_value',
        'status',
        'bank_beneficiary_name',
        'bank_name',
        'bank_account_number',
        'total_earnings',
        'paid_earnings',
    ];

    protected $casts = [
        'commission_value' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'paid_earnings' => 'decimal:2',
    ];

    protected $appends = ['pending_earnings'];

    /**
     * Get the user that owns this affiliate account.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all referrals made by this affiliate.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    /**
     * Get all conversions for this affiliate.
     */
    public function conversions(): HasMany
    {
        return $this->hasMany(AffiliateConversion::class);
    }

    /**
     * Get all payouts made to this affiliate.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(AffiliatePayout::class);
    }

    /**
     * Calculate pending earnings (not yet paid out).
     */
    public function getPendingEarningsAttribute(): float
    {
        return (float) $this->total_earnings - (float) $this->paid_earnings;
    }

    /**
     * Calculate commission for a given box price.
     */
    public function calculateCommission(float $boxPrice): float
    {
        if ($this->commission_type === self::COMMISSION_TYPE_PERCENTAGE) {
            return round($boxPrice * ($this->commission_value / 100), 2);
        }

        // Fixed amount
        return (float) $this->commission_value;
    }

    /**
     * Generate a unique affiliate code based on user's name.
     * Creates personal codes like "ALEX123" instead of random strings.
     */
    public static function generateCode(?string $name = null): string
    {
        if ($name) {
            // Get first name and clean it
            $firstName = explode(' ', trim($name))[0];
            $firstName = self::cleanNameForCode($firstName);

            if (strlen($firstName) >= 2) {
                // Try name + 2 digit number first, then 3 digits if needed
                for ($digits = 2; $digits <= 4; $digits++) {
                    for ($attempts = 0; $attempts < 10; $attempts++) {
                        $suffix = str_pad(rand(0, pow(10, $digits) - 1), $digits, '0', STR_PAD_LEFT);
                        $code = strtoupper($firstName . $suffix);

                        if (!self::where('affiliate_code', $code)->exists()) {
                            return $code;
                        }
                    }
                }
            }
        }

        // Fallback to random code if name-based fails
        do {
            $code = strtoupper(Str::random(8));
        } while (self::where('affiliate_code', $code)->exists());

        return $code;
    }

    /**
     * Clean a name for use in affiliate code.
     * Removes accents, special characters, keeps only letters.
     */
    private static function cleanNameForCode(string $name): string
    {
        // Remove accents
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        // Keep only letters
        $name = preg_replace('/[^a-zA-Z]/', '', $name);
        // Limit length
        return substr($name, 0, 10);
    }

    /**
     * Check if affiliate is active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Get the referral link for this affiliate.
     */
    public function getReferralLinkAttribute(): string
    {
        $baseUrl = config('app.frontend_url', 'https://boxly.app');
        return "{$baseUrl}?ref={$this->affiliate_code}";
    }

    /**
     * Scope for active affiliates.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for affiliates with pending earnings.
     */
    public function scopeWithPendingEarnings($query)
    {
        return $query->whereRaw('total_earnings > paid_earnings');
    }
}
