<?php

namespace App\Support;

/**
 * دعم التوصيل: السائق ياخذ أجرته على الرسوم الكاملة، والزبون يدفع جزء بس، والباقي على الشركة.
 * مثال: الرسوم 10 · «نسبة 50%» → الزبون 5 والشركة 5 · «الزبون يدفع بالكثير 3» → الزبون 3 والشركة 7.
 * يتضبط من «إعدادات التشغيل ← التوصيل والعمولة».
 */
class DeliverySubsidy
{
    public const MODES = [
        'none' => 'بدون — الزبون يدفع الرسوم كاملة',
        'percent' => 'الشركة تدفع نسبة (%) من الرسوم',
        'fixed' => 'الشركة تدفع مبلغ ثابت من كل توصيلة',
        'customer_max' => 'الزبون يدفع بالكثير مبلغ محدد، والباقي على الشركة',
    ];

    /**
     * @return array{0: float, 1: float} [اللي يدفعه الزبون, اللي تدفعه الشركة]
     */
    public static function split(float $fullFee, float $subtotal): array
    {
        $fullFee = max(0, round($fullFee, 2));
        $mode = (string) Options::get('delivery.subsidy_mode');
        $value = max(0, (float) Options::get('delivery.subsidy_value'));

        if ($fullFee <= 0 || $mode === 'none' || $value <= 0
            || $subtotal < (float) Options::get('delivery.subsidy_min_subtotal')) {
            return [$fullFee, 0.0];
        }

        $company = match ($mode) {
            'percent' => $fullFee * min(100, $value) / 100,
            'fixed' => $value,
            'customer_max' => $fullFee - $value,
            default => 0,
        };
        $company = round(min($fullFee, max(0, $company)), 2);

        return [round($fullFee - $company, 2), $company];
    }
}
