<?php

namespace App\Services;

use App\Models\User;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * إشعارات Firebase Cloud Messaging — HTTP v1 API.
 *
 * يحتاج:
 *   composer require google/auth
 *   ملف حساب الخدمة في storage/app/firebase.json
 *   FCM_PROJECT_ID في ملف .env
 */
class PushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /**
     * $app: أي تطبيق يوصله الإشعار (customer|driver|store) — نفس الحساب ممكن يكون زبون وسائق.
     * بدونه ناخذو الدور الأساسي للحساب.
     */
    public static function toUser(?User $user, string $title, string $body, array $data = [], ?string $app = null): bool
    {
        if (! $user) {
            return false;
        }

        $app ??= $user->role?->value ?? 'customer';
        $token = $user->pushTokenFor($app);

        if (! $token) {
            return false;
        }

        self::sendRaw($token, $title, $body, $data, $app);

        return true;
    }

    public static function sendRaw(string $token, string $title, string $body, array $data = [], string $app = 'customer'): void
    {
        $projectId = config('services.fcm.project_id');

        if (! $projectId || ! self::credentialsPath()) {
            Log::info('FCM not configured — skipped', compact('title', 'body'));

            return;
        }

        $accessToken = self::accessToken();

        if (! $accessToken) {
            return;
        }

        // كل قيم data لازم تكون نصوص في FCM
        $stringData = array_map(fn ($v) => (string) $v, $data);

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => [
                        'token'        => $token,
                        'notification' => [
                            'title' => $title,
                            'body'  => $body,
                        ],
                        'data'    => $stringData,
                        'android' => [
                            'priority'     => 'high',
                            // القناة والنغمة حسب «أصوات الإشعارات» في لوحة التحكم
                            'notification' => \App\Support\Sounds::android($app),
                        ],
                        'apns' => [
                            'payload' => [
                                'aps' => ['sound' => 'default'],
                            ],
                        ],
                    ],
                ]);

            if ($response->failed()) {
                Log::warning('FCM send failed', [
                    'status' => $response->status(),
                    'body'   => $response->json(),
                ]);

                // التوكن صار غير صالح — نمسحه باش ما نحاولش فيه كل مرة
                if (in_array($response->status(), [400, 404], true)) {
                    User::where('fcm_token', $token)->update(['fcm_token' => null]);
                    User::whereNotNull('fcm_tokens')->whereRaw('CAST(fcm_tokens AS CHAR) LIKE ?', ['%'.$token.'%'])->get()
                        ->each(fn (User $u) => $u->forceFill([
                            'fcm_tokens' => array_filter($u->fcm_tokens ?? [], fn ($t) => $t !== $token) ?: null,
                        ])->saveQuietly());
                }
            }
        } catch (\Throwable $e) {
            Log::error('FCM exception: '.$e->getMessage());
        }
    }

    /** توكن الوصول صالح ساعة — نخزّنه 55 دقيقة */
    private static function accessToken(): ?string
    {
        return Cache::remember('fcm.access_token', now()->addMinutes(55), function () {
            try {
                $credentials = new ServiceAccountCredentials(self::SCOPE, self::credentialsPath());

                return $credentials->fetchAuthToken()['access_token'] ?? null;
            } catch (\Throwable $e) {
                Log::error('FCM auth failed: '.$e->getMessage());

                return null;
            }
        });
    }

    private static function credentialsPath(): ?string
    {
        $path = config('services.fcm.credentials');

        if (! $path) {
            return null;
        }

        $full = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)
            ? $path
            : base_path($path);

        return file_exists($full) ? $full : null;
    }
}
