<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\PointsTransaction;
use App\Models\User;
use App\Support\Activity;
use App\Support\Options;
use App\Support\Texts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «ادعُ صديقك»: كل زبون عنده كود ورابط (https://…/r/CODE).
 *
 * كيف يوصل الكود للحساب الجديد:
 *  - أندرويد: الرابط يودّي لـ Google Play ومعاه الكود (Install Referrer)، والتطبيق يقراه أول ما يتفتح ويبعته بعد الدخول.
 *  - لو ما وصلش وحده (آيفون، أو نزّل التطبيق من غير الرابط): الصديق يكتب الكود بنفسه من «ادعُ صديقك».
 *
 * الهدية تنصرف للاثنين لما أول طلب للصديق الجديد يتسلّم (مش عند التسجيل) — يمنع الحسابات الوهمية.
 */
class ReferralService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // بدون 0/O و1/I

    public static function enabled(): bool
    {
        return (bool) Options::get('referral.enabled') && PointsService::enabled();
    }

    public function codeFor(User $user): string
    {
        if ($user->referral_code) {
            return $user->referral_code;
        }

        for ($i = 0; $i < 20; $i++) {
            $code = '';
            for ($j = 0; $j < 6; $j++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (! User::where('referral_code', $code)->exists()) {
                $user->forceFill(['referral_code' => $code])->saveQuietly();

                return $code;
            }
        }

        throw new \RuntimeException('referral code generation failed');
    }

    public function link(User $user): string
    {
        return url('r/'.$this->codeFor($user));
    }

    /** null = يقدر يحط كود صديق · غيرها = السبب */
    public function applyBlocker(User $user): ?string
    {
        if (! self::enabled()) {
            return 'ميزة دعوة الأصدقاء موقوفة حالياً.';
        }
        if ($user->referred_by_id) {
            return 'حسابك مربوط بكود صديق من قبل.';
        }
        if ($user->created_at && $user->created_at->lt(now()->subDays((int) Options::get('referral.window_days')))) {
            return 'كود الصديق للحسابات الجديدة بس (خلال '.Options::get('referral.window_days').' أيام من التسجيل).';
        }
        if (Order::where('customer_id', $user->id)->where('status', OrderStatus::Delivered->value)->exists()) {
            return 'كود الصديق يتحط قبل أول طلب.';
        }

        return null;
    }

    public function apply(User $user, string $code): User
    {
        if ($reason = $this->applyBlocker($user)) {
            throw ValidationException::withMessages(['code' => $reason]);
        }

        $code = strtoupper(trim($code));
        $referrer = $code !== '' ? User::where('referral_code', $code)->where('is_active', true)->first() : null;

        if (! $referrer) {
            throw ValidationException::withMessages(['code' => 'الكود هذا مش صحيح.']);
        }
        if ($referrer->id === $user->id) {
            throw ValidationException::withMessages(['code' => 'ما تقدرش تستعمل كودك أنت.']);
        }
        // ما ندعوش اللي دعانا (دائرة)
        if ($referrer->referred_by_id === $user->id) {
            throw ValidationException::withMessages(['code' => 'الكود هذا ما ينفعش لحسابك.']);
        }

        $user->forceFill(['referred_by_id' => $referrer->id, 'referred_at' => now()])->saveQuietly();
        Activity::record('referral.applied', "كود صديق: {$referrer->name} ← {$user->name}", $user, ['referrer_id' => $referrer->id]);

        return $referrer;
    }

    /** بعد تسليم أي طلب: لو هذا أول طلب لصديق مدعو، الهدية للاثنين — مرة وحدة */
    public function onDelivered(Order $order): void
    {
        $user = $order->customer;
        if (! $user || ! $user->referred_by_id || $user->referral_rewarded_at || ! self::enabled()) {
            return;
        }
        if ((float) $order->subtotal < (float) Options::get('referral.min_order')) {
            return;
        }

        DB::transaction(function () use ($user, $order) {
            // نعلّمو أول (يمنع الصرف مرتين لو طلبين اتسلّمو في نفس اللحظة)
            if (User::whereKey($user->id)->whereNull('referral_rewarded_at')->update(['referral_rewarded_at' => now()]) === 0) {
                return;
            }

            $referrer = User::find($user->referred_by_id);
            $points = app(PointsService::class);
            $title = Texts::get('notify.title', ['code' => $order->code]);

            $refereePts = (int) Options::get('referral.referee_points');
            if ($refereePts > 0) {
                $points->referral($user, $refereePts, 'هدية دعوة صديق — أول طلب');
                PushService::toUser($user, '🎁 هدية الترحيب', "خذيت {$refereePts} نقطة هدية على أول طلب — بالهناء!",
                    ['type' => 'referral'], 'customer');
            }

            $cap = (int) Options::get('referral.monthly_cap');
            $thisMonth = $referrer ? User::where('referred_by_id', $referrer->id)
                ->where('id', '!=', $user->id)
                ->whereNotNull('referral_rewarded_at')
                ->where('referral_rewarded_at', '>=', now()->startOfMonth())
                ->count() : 0;

            $referrerPts = (int) Options::get('referral.referrer_points');
            if ($referrer && $referrer->is_active && $referrerPts > 0 && ($cap === 0 || $thisMonth < $cap)) {
                $points->referral($referrer, $referrerPts, "دعوة صديق: {$user->name}");
                PushService::toUser($referrer, '🎉 صديقك طلب!', "{$user->name} كمّل أول طلب — خذيت {$referrerPts} نقطة.",
                    ['type' => 'referral'], 'customer');
            }

            Activity::record('referral.rewarded', "هدية دعوة: {$user->name}", $user,
                ['referrer_id' => $referrer?->id, 'order_id' => $order->id, 'capped' => $cap > 0 && $thisMonth >= $cap]);
        });
    }

    /** للتطبيق: الكود، الرابط، النص، والإحصاءات */
    public function summary(User $user): array
    {
        $enabled = self::enabled();
        $code = $enabled ? $this->codeFor($user) : null;
        $link = $code ? url('r/'.$code) : null;
        $refereePts = (int) Options::get('referral.referee_points');

        $invited = User::where('referred_by_id', $user->id);

        return [
            'enabled' => $enabled,
            'code' => $code,
            'link' => $link,
            'share_text' => $code ? Texts::fill((string) Options::get('referral.share_text'),
                ['link' => $link, 'code' => $code, 'points' => $refereePts]) : null,
            'referrer_points' => (int) Options::get('referral.referrer_points'),
            'referee_points' => $refereePts,
            'min_order' => (float) Options::get('referral.min_order'),
            'invited' => (clone $invited)->count(),
            'rewarded' => (clone $invited)->whereNotNull('referral_rewarded_at')->count(),
            'points_earned' => (int) PointsTransaction::where('user_id', $user->id)->where('type', 'referral')->sum('points'),
            // حطّ كود صديق
            'can_enter_code' => $this->applyBlocker($user) === null,
            'referred_by' => $user->referred_by_id ? User::find($user->referred_by_id)?->name : null,
        ];
    }
}
