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
    use HasApiTokens, HasFactory, \Illuminate\Notifications\Notifiable, SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'role', 'roles', 'permissions', 'is_active', 'marketing_opt_out',
        'avatar', 'fcm_token', 'fcm_tokens', 'locale', 'phone_verified_at', 'last_seen_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'role'              => UserRole::class,
            'roles'             => 'array',
            'fcm_tokens'        => 'array',
            'permissions'       => 'array',
            'is_active'         => 'boolean',
            'password'          => 'hashed',
            'phone_verified_at' => 'datetime',
            'last_seen_at'      => 'datetime',
        ];
    }

    /** التطبيقات اللي تستقبل إشعارات، وكل تطبيق ودوره */
    public const APPS = ['customer', 'driver', 'store'];

    protected static function booted(): void
    {
        // role = الدور الأساسي، ولازم يكون ضمن roles. لو ما تحددتش roles ناخذو role بس
        static::saving(function (User $u) {
            $roles = collect($u->roles ?? [])
                ->map(fn ($r) => $r instanceof UserRole ? $r->value : (string) $r)
                ->filter(fn ($r) => UserRole::tryFrom($r) !== null)
                ->unique()->values();

            $primary = $u->role instanceof UserRole ? $u->role->value : ($u->role ?: null);

            if ($roles->isEmpty()) {
                $roles = collect([$primary ?: UserRole::Customer->value]);
            }

            // كود قديم يغيّر role بس: الدور الجديد ينضاف للقائمة
            if ($primary && $u->isDirty('role') && ! $u->isDirty('roles') && ! $roles->contains($primary)) {
                $roles->push($primary);
            }

            // الدور الأساسي انشال من القائمة؟ ناخذو أهم دور باقي
            if (! $primary || ! $roles->contains($primary)) {
                $primary = collect(['admin', 'store', 'driver', 'customer'])->first(fn ($r) => $roles->contains($r));
            }

            $u->roles = $roles->all();
            $u->role = $primary;
        });
    }

    /** ملف السائق — ينشأ أول مرة يحتاجه (حساب عنده دور سائق) */
    public function ensureDriverProfile(): ?DriverProfile
    {
        if (! $this->hasRole(UserRole::Driver)) {
            return $this->driverProfile;
        }

        return $this->driverProfile ?? tap($this->driverProfile()->create(['is_approved' => false, 'is_online' => false]),
            fn () => $this->load('driverProfile'));
    }

    /** عنده الدور هذا؟ (من ضمن أدواره كلها) */
    public function hasRole(UserRole|string $role): bool
    {
        $value = $role instanceof UserRole ? $role->value : $role;

        return in_array($value, $this->roleValues(), true);
    }

    /** @return list<string> */
    public function roleValues(): array
    {
        $roles = $this->roles ?: [$this->role?->value];

        return array_values(array_filter(array_map(
            fn ($r) => $r instanceof UserRole ? $r->value : $r, $roles
        )));
    }

    /** الأدوار بالعربي: «زبون، سائق» */
    public function rolesLabel(): string
    {
        return collect($this->roleValues())
            ->map(fn ($r) => UserRole::tryFrom($r)?->label() ?? $r)
            ->implode('، ');
    }

    public function addRole(UserRole $role): void
    {
        if ($this->hasRole($role)) {
            return;
        }
        $this->roles = [...$this->roleValues(), $role->value];
        $this->save();
    }

    /** يفلتر المستخدمين اللي عندهم الدور (أساسي أو إضافي) */
    public function scopeWithRole($query, UserRole|string $role)
    {
        $value = $role instanceof UserRole ? $role->value : $role;

        return $query->where(fn ($q) => $q->where('role', $value)->orWhereJsonContains('roles', $value));
    }

    /** توكن إشعارات تطبيق معيّن — الحسابات القديمة عندها توكن واحد لدورها الأساسي */
    public function pushTokenFor(string $app): ?string
    {
        $tokens = $this->fcm_tokens ?? [];

        if (! empty($tokens[$app])) {
            return $tokens[$app];
        }

        return $this->role?->value === $app ? $this->fcm_token : null;
    }

    public function setPushToken(?string $app, ?string $token): void
    {
        if (blank($token)) {
            return;
        }

        $app = in_array($app, self::APPS, true) ? $app : ($this->role?->value ?? 'customer');
        $tokens = $this->fcm_tokens ?? [];
        $tokens[$app] = $token;

        $this->forceFill(['fcm_tokens' => $tokens, 'fcm_token' => $token])->save();
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
        return $this->hasRole($role);
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
        if (! $this->hasRole(UserRole::Admin)) {
            return false;
        }

        // null أو فاضية = صلاحية كاملة (نفس اللي مكتوب في فورم المستخدم)
        if (empty($this->permissions)) {
            return true;
        }

        return in_array($key, $this->permissions, true);
    }

    /** الدخول للوحة التحكم */
    public function canAccessPanel($panel = null): bool
    {
        return $this->hasRole(UserRole::Admin) && $this->is_active;
    }
}
