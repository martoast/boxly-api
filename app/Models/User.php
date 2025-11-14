<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, Billable, HasApiTokens;

    /**
     * User type constants
     */
    const TYPE_EXPAT = 'expat';
    const TYPE_BUSINESS = 'business';
    const TYPE_SHOPPER = 'shopper';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'password_set',
        'phone',
        'preferred_language',
        'street',
        'exterior_number',
        'interior_number',
        'colonia',
        'municipio',
        'estado',
        'postal_code',
        'provider',
        'role',
        'user_type',
        'registration_source',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'registration_source' => 'array', // Automatically cast JSON to array
        ];
    }

    /**
     * Get all available user types
     */
    public static function getUserTypes(): array
    {
        return [
            self::TYPE_EXPAT => [
                'label' => 'Expat',
                'description' => 'Foreign nationals living in Mexico',
                'icon' => 'globe',
            ],
            self::TYPE_BUSINESS => [
                'label' => 'Business',
                'description' => 'Companies needing B2B solutions',
                'icon' => 'briefcase',
            ],
            self::TYPE_SHOPPER => [
                'label' => 'Online Shopper',
                'description' => 'Shop from US/international online stores',
                'icon' => 'shopping-cart',
            ],
        ];
    }

    /**
     * Check if user is an expat
     */
    public function isExpat(): bool
    {
        return $this->user_type === self::TYPE_EXPAT;
    }

    /**
     * Check if user is a business
     */
    public function isBusiness(): bool
    {
        return $this->user_type === self::TYPE_BUSINESS;
    }

    /**
     * Check if user is a shopper
     */
    public function isShopper(): bool
    {
        return $this->user_type === self::TYPE_SHOPPER;
    }

    /**
     * Get the user type label
     */
    public function getUserTypeLabel(): string
    {
        $types = self::getUserTypes();
        return $types[$this->user_type]['label'] ?? 'Unknown';
    }

    /**
     * Get registration source as array (handles both JSON and old string format)
     */
    public function getRegistrationSourceData(): array
    {
        if (!$this->registration_source) {
            return [];
        }

        // If it's already an array (cast worked), return it
        if (is_array($this->registration_source)) {
            return $this->registration_source;
        }

        // Try to decode if it's a JSON string
        $decoded = json_decode($this->registration_source, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // If it's a plain string (old format), wrap it
        return ['source' => $this->registration_source];
    }

    /**
     * Scope for filtering by user type
     */
    public function scopeOfType($query, $type)
    {
        return $query->where('user_type', $type);
    }

    /**
     * Scope for business users
     */
    public function scopeBusinesses($query)
    {
        return $query->where('user_type', self::TYPE_BUSINESS);
    }

    /**
     * Scope for expat users
     */
    public function scopeExpats($query)
    {
        return $query->where('user_type', self::TYPE_EXPAT);
    }

    /**
     * Scope for shopper users
     */
    public function scopeShoppers($query)
    {
        return $query->where('user_type', self::TYPE_SHOPPER);
    }

    /**
     * Get the user's orders.
     */
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get active orders (not delivered or cancelled)
     */
    public function activeOrders()
    {
        return $this->orders()->whereNotIn('status', ['delivered', 'cancelled']);
    }

    /**
     * Get orders that are collecting items
     */
    public function collectingOrders()
    {
        return $this->orders()->where('status', 'collecting');
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get the user's full address as array
     */
    public function getAddressAttribute(): array
    {
        return [
            'street' => $this->street,
            'exterior_number' => $this->exterior_number,
            'interior_number' => $this->interior_number,
            'colonia' => $this->colonia,
            'municipio' => $this->municipio,
            'estado' => $this->estado,
            'postal_code' => $this->postal_code,
        ];
    }

    /**
     * Check if user has complete address
     */
    public function hasCompleteAddress(): bool
    {
        return $this->street &&
               $this->exterior_number &&
               $this->colonia &&
               $this->municipio &&
               $this->estado &&
               $this->postal_code;
    }
}