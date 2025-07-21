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
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
        ];
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

    /**
     * Check if user has any unpaid quotes
     */
    public function hasUnpaidQuotes(): bool
    {
        return $this->orders()
            ->where('status', 'quote_sent')
            ->exists();
    }
}