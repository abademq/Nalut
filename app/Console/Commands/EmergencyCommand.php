<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Support\Activity;
use App\Support\Emergency;
use Illuminate\Console\Command;

/**
 * مركز الطوارئ من السيرفر — لما اللوحة نفسها مش آمنة أو مش شغّالة.
 *
 *   php artisan emergency status
 *   php artisan emergency on payments wallet      (أو: apps = قفل التطبيقات الأربعة، all = كل شي)
 *   php artisan emergency off all
 *   php artisan emergency message "نص يطلع للمستخدمين"
 *   php artisan emergency logout customer         (customer|store|driver|admin|all)
 *   php artisan emergency admins-off --keep=1     (يوقف كل حسابات الإدارة إلا رقم 1)
 *   php artisan emergency admins-on
 *   php artisan emergency min-build customer 25   (تحديث إجباري: أقل رقم بناء)
 *   php artisan emergency 2fa-off                 (لو انقفلت برا لوحة التحكم بسبب التحقق بخطوتين)
 */
class EmergencyCommand extends Command
{
    protected $signature = 'emergency
        {action : status | on | off | message | logout | admins-off | admins-on | min-build | 2fa-off | 2fa-on}
        {targets?* : المفاتيح، أو الدور، أو النص}
        {--keep= : رقم حساب الإدارة اللي يبقى شغّال (admins-off)}
        {--force : بدون سؤال تأكيد}';

    protected $description = 'مركز الطوارئ: إيقاف أجزاء من المنصة فوراً (دفع، محافظ، طلبات، تطبيقات، جلسات)';

    public function handle(): int
    {
        $targets = (array) $this->argument('targets');

        return match ($this->argument('action')) {
            'status' => $this->status(),
            'on' => $this->toggle($targets, true),
            'off' => $this->toggle($targets, false),
            'message' => $this->message($targets),
            'logout' => $this->logout($targets),
            'admins-off' => $this->adminsOff(),
            'admins-on' => $this->adminsOn(),
            'min-build' => $this->minBuild($targets),
            '2fa-off', '2fa-on' => $this->twoFactor($this->argument('action') === '2fa-on'),
            default => $this->bad('أمر مش معروف. الأوامر: status, on, off, message, logout, admins-off, admins-on, min-build, 2fa-off, 2fa-on'),
        };
    }

    private function status(): int
    {
        $rows = [];
        foreach (Emergency::SWITCHES as $key => [$name]) {
            $rows[] = [$key, $name, Emergency::on($key) ? '🔴 شغّال (موقوف)' : '🟢 عادي'];
        }
        $this->table(['المفتاح', 'شن يوقف', 'الحالة'], $rows);
        $this->line('الرسالة: '.Emergency::message());
        foreach (Emergency::APPS as $app) {
            if ($b = Emergency::minBuild($app)) {
                $this->line("تحديث إجباري $app: أقل بناء $b");
            }
        }
        if ($ids = Emergency::disabledAdmins()) {
            $this->warn('حسابات إدارة موقوفة بالطوارئ: '.implode(', ', $ids));
        }

        return self::SUCCESS;
    }

    private function expand(array $targets): array
    {
        $out = [];
        foreach ($targets as $t) {
            $out = [...$out, ...match ($t) {
                'all' => array_keys(Emergency::SWITCHES),
                'apps' => ['app_customer', 'app_store', 'app_driver', 'app_admin'],
                default => [$t],
            }];
        }

        return array_values(array_unique($out));
    }

    private function toggle(array $targets, bool $on): int
    {
        $switches = $this->expand($targets);
        $bad = array_diff($switches, array_keys(Emergency::SWITCHES));
        if (! $switches || $bad) {
            $this->error($bad ? 'مفاتيح مش معروفة: '.implode(', ', $bad) : 'حدد مفتاح واحد على الأقل.');
            $this->line('المفاتيح: '.implode(', ', array_keys(Emergency::SWITCHES)).' — أو apps / all');

            return self::FAILURE;
        }

        $changed = Emergency::set($switches, $on, null, 'السيرفر (artisan)');
        $this->info($changed ? ($on ? 'اتشغّل: ' : 'اتوقف: ').implode(', ', $changed) : 'ما تغيّر شي (كانت على نفس الحالة).');

        return $this->status();
    }

    private function message(array $targets): int
    {
        $text = trim(implode(' ', $targets));
        if ($text === '') {
            return $this->bad('اكتب النص بين علامتي تنصيص.');
        }
        Emergency::setMessage($text);
        $this->info('تم: '.Emergency::message());

        return self::SUCCESS;
    }

    private function logout(array $targets): int
    {
        $role = $targets[0] ?? '';
        if (! in_array($role, ['customer', 'store', 'driver', 'admin', 'all'], true)) {
            return $this->bad('حدد: customer | store | driver | admin | all');
        }
        if (! $this->option('force') && ! $this->confirm("نطلّعو كل جلسات ($role)؟ يلزمهم يدخلو من جديد.")) {
            return self::FAILURE;
        }
        $n = Emergency::revokeTokens($role);
        $this->info("انمسحت $n جلسة.");

        return self::SUCCESS;
    }

    private function adminsOff(): int
    {
        $keep = User::find((int) $this->option('keep'));
        if (! $keep) {
            return $this->bad('حدد حسابك اللي يبقى شغّال: --keep=رقم_الحساب');
        }
        if (! $this->option('force') && ! $this->confirm("نوقفو كل حسابات الإدارة إلا {$keep->name}؟")) {
            return self::FAILURE;
        }
        $this->info('اتوقف: '.Emergency::disableOtherAdmins($keep).' حساب.');

        return self::SUCCESS;
    }

    private function adminsOn(): int
    {
        $this->info('رجع: '.Emergency::restoreAdmins().' حساب.');

        return self::SUCCESS;
    }

    private function minBuild(array $targets): int
    {
        [$app, $build] = [$targets[0] ?? '', $targets[1] ?? null];
        if (! in_array($app, Emergency::APPS, true) || ! is_numeric($build)) {
            return $this->bad('مثال: php artisan emergency min-build customer 25   (0 = بدون تحديث إجباري)');
        }
        Setting::put("emergency.min_build.$app", (string) max(0, (int) $build));
        Activity::record('emergency.min_build', "تحديث إجباري $app: $build", null, [], null, 'system');
        $this->info("تم: $app أقل بناء = ".Emergency::minBuild($app));

        return self::SUCCESS;
    }

    private function twoFactor(bool $on): int
    {
        Setting::put('opt.security.admin_2fa', $on ? '1' : '0');
        Activity::record('security.admin_2fa', 'التحقق بخطوتين للإدارة: '.($on ? 'تشغيل' : 'إيقاف').' من السيرفر', null, [], null, 'system');
        $this->info('التحقق بخطوتين للإدارة: '.($on ? 'شغّال' : 'موقوف'));

        return self::SUCCESS;
    }

    private function bad(string $msg): int
    {
        $this->error($msg);

        return self::FAILURE;
    }
}
