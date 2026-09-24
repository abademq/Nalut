<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * إعدادات بوابة دفع — المفاتيح مشفّرة في قاعدة البيانات
 * وتتعدّل من لوحة التحكم مش من ملف .env
 */
class PaymentGateway extends Model
{
    /** البوابات اللي تشتغل بتدفّق OTP داخل التطبيق */
    public const OTP_GATEWAYS = ['sadad', 'adfali'];

    /** البوابات اللي تفتح صفحة دفع خارجية */
    public const REDIRECT_GATEWAYS = ['localbankcards', 'mpgs', 'tlync'];

    protected $fillable = [
        'key', 'name', 'provider', 'mode', 'credentials',
        'enabled_for_topup', 'enabled_for_order',
        'min_amount', 'max_amount', 'sort',
    ];

    protected function casts(): array
    {
        return [
            // التشفير يحمي المفاتيح حتى لو تسربت نسخة من قاعدة البيانات
            'credentials'       => 'encrypted:array',
            'enabled_for_topup' => 'boolean',
            'enabled_for_order' => 'boolean',
            'min_amount'        => 'float',
            'max_amount'        => 'float',
        ];
    }

    public function apiKey(): ?string
    {
        return $this->credentials['api_key'] ?? null;
    }

    public function accessToken(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }

    public function secretKey(): ?string
    {
        return $this->credentials['secret_key'] ?? null;
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey()) && filled($this->accessToken());
    }

    public function isOtpFlow(): bool
    {
        return in_array($this->key, self::OTP_GATEWAYS, true);
    }

    public function isRedirectFlow(): bool
    {
        return in_array($this->key, self::REDIRECT_GATEWAYS, true);
    }

    public function modeLabel(): string
    {
        return $this->mode === 'live' ? 'مباشر' : 'تجريبي';
    }

    /**
     * البوابات المتاحة للزبون حسب الغرض.
     *
     * بدون كاش عن قصد: تخزين كائنات Eloquent في الكاش الملفي
     * يفشل عند إعادة البناء، والجدول 5 صفوف فالاستعلام رخيص.
     */
    public static function availableFor(string $purpose)
    {
        $column = $purpose === 'order' ? 'enabled_for_order' : 'enabled_for_topup';

        return self::where($column, true)
            ->orderBy('sort')
            ->get()
            ->filter(fn ($g) => $g->isConfigured())
            ->values();
    }
}
