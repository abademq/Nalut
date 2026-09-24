<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements \Filament\Models\Contracts\FilamentUser
{
    use HasApiTokens, HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'role', 'permissions', 'is_active',
        'avatar', 'fcm_token', 'locale', 'phone_verified_at', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role'              => UserRole::class,
            'permissions'       => 'array',
            'is_active'         => 'boolean',
            'password'          => 'hashed',
            'phone_verified_at' => 'datetime',
            'last_seen_at'      => 'datetime',
        ];
    }

    public function store(): HasOne
    {
        return $this->hasOne(Store::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(DriverProfile::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    /** كل حركات محفظة المستخدم — تستعملها صفحة السجل المالي */
    public function walletTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(WalletTransaction::class, Wallet::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id');
    }

    public function isRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    public function walletBalance(): float
    {
        return (float) ($this->wallet?->balance ?? 0);
    }

    /**
     * فحص الصلاحية.
     * الإدارة بصلاحيات null = وصول كامل. غير الإدارة ما عندهاش وصول للوحة.
     */
    public function hasPermission(string $key): bool
    {
        if ($this->role !== UserRole::Admin) {
            return false;
        }

        if ($this->permissions === null) {
            return true;
        }

        return in_array($key, $this->permissions, true);
    }

    /** الدخول للوحة التحكم */
    public function canAccessPanel($panel = null): bool
    {
        return $this->role === UserRole::Admin && $this->is_active;
    }
}
