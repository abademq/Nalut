<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * حذف الحساب من داخل التطبيق (شرط Google Play).
 *
 * - البيانات الشخصية تنمسح فوراً (الاسم، الرقم، العناوين، المفضلة، التوكنات، وثائق السائق).
 * - سجل الطلبات والحركات المالية يقعد بدون اسم ولا رقم (محاسبة ونزاعات) — نفس اللي مكتوب في سياسة الخصوصية.
 * - الرقم يتحرر: لو سجّل بيه بعدين، ينشأ حساب جديد فاضي.
 * - حساب متجر: ما ينمسحش لحاله (عنده منتجات ومستحقات) — يوصل طلب للإدارة.
 */
class AccountDeletionService
{
    public function __construct(private readonly WalletService $wallets) {}

    /**
     * موانع الحذف — ترجع قائمة أسباب بالعربي (فاضية = مسموح).
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $reasons = [];

        $active = Order::whereIn('status', OrderStatus::active())
            ->where(fn ($q) => $q->where('customer_id', $user->id)->orWhere('driver_id', $user->id))
            ->count();
        if ($active > 0) {
            $reasons[] = "عندك $active طلب شغّال. استنى لين يكمل أو يتلغى.";
        }

        // السائق والمتجر: فلوس لازم تتسوّى قبل الحذف
        if ($user->hasRole(UserRole::Driver) || $user->hasRole(UserRole::Store)) {
            $balance = $this->wallets->balance($user);
            if (abs($balance) >= 0.01) {
                $reasons[] = 'رصيدك في المحفظة '.number_format($balance, 2).' د.ل — تواصل مع الإدارة لتسويته قبل الحذف.';
            }
            $cash = (float) ($user->driverProfile?->cash_in_hand ?? 0);
            if (abs($cash) >= 0.01) {
                $reasons[] = 'معاك '.number_format($cash, 2).' د.ل نقداً من طلبات — سلّمها للإدارة قبل الحذف.';
            }
        }

        return $reasons;
    }

    /** المتجر: طلب للإدارة بدل الحذف المباشر */
    public function needsAdmin(User $user): bool
    {
        return $user->hasRole(UserRole::Store) || $user->hasRole(UserRole::Admin);
    }

    public function requestByAdmin(User $user, ?string $reason = null): void
    {
        \App\Services\AdminAlerts::send(
            'طلب حذف حساب',
            "{$user->name} — {$user->phone} ({$user->rolesLabel()})".($reason ? " · السبب: $reason" : ''),
            \App\Filament\Resources\Users\UserResource::getUrl('index'),
            'warning',
            "delete-request:{$user->id}"
        );
    }

    /** الحذف الفعلي — البيانات الشخصية تنمسح والسجلات تقعد مجهولة */
    public function delete(User $user, ?string $reason = null): void
    {
        if ($reasons = $this->blockers($user)) {
            throw ValidationException::withMessages(['account' => $reasons]);
        }

        $id = $user->id;
        $phone = $user->phone;
        $files = array_filter([$user->avatar, $user->driverProfile?->id_photo]);

        // التغييرات ما تنكتبش في سجل النشاط (فيها الاسم والرقم القديم)
        \App\Support\Activity::pause(fn () => DB::transaction(function () use ($user, $id) {
            $user->addresses()->delete();
            \App\Models\Favorite::where('user_id', $id)->delete();
            \App\Models\SavedCart::where('user_id', $id)->delete();
            $user->tokens()->delete();

            $user->driverProfile?->forceFill([
                'national_id' => null, 'id_photo' => null, 'plate_number' => null,
                'is_online' => false, 'is_approved' => false,
                'current_lat' => null, 'current_lng' => null,
            ])->save();

            // الرقم يتحرر (عمود فريد حتى مع الحذف الناعم)
            $user->forceFill([
                'name'              => 'حساب محذوف',
                'phone'             => 'del-'.$id,
                'email'             => null,
                'password'          => null,
                'avatar'            => null,
                'fcm_token'         => null,
                'fcm_tokens'        => null,
                'is_active'         => false,
                'marketing_opt_out' => true,
                'points_balance'    => 0,
                'phone_verified_at' => null,
                'remember_token'    => null,
            ])->save();

            $user->delete(); // حذف ناعم — الطلبات القديمة تقعد مربوطة بـ«حساب محذوف»
        }));

        foreach ($files as $f) {
            Storage::disk('public')->delete($f);
        }

        // رموز التحقق القديمة للرقم
        DB::table('otp_codes')->where('phone', $phone)->delete();

        \App\Support\Activity::record('account.deleted', 'حذف الحساب من التطبيق'.($reason ? " — السبب: $reason" : ''),
            properties: ['phone_tail' => substr((string) $phone, -3)]);
    }
}
