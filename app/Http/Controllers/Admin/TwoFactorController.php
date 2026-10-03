<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminTwoFactor;
use App\Support\Options;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** صفحة رمز التحقق بعد دخول لوحة التحكم */
class TwoFactorController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! AdminTwoFactor::enabled() || AdminTwoFactor::passedInSession($user)) {
            return redirect()->intended(url('/admin'));
        }

        $error = null;
        // نبعتو الرمز تلقائياً أول ما تنفتح الصفحة (مرة كل دقيقة بالكثير)
        if (! session('admin_2fa_sent_at') || now()->timestamp - (int) session('admin_2fa_sent_at') > 60) {
            try {
                $r = AdminTwoFactor::send($user, $request->ip());
                session(['admin_2fa_sent_at' => now()->timestamp, 'admin_2fa_channel' => $r['channel']]);
            } catch (ValidationException $e) {
                $error = collect($e->errors())->flatten()->first();
            } catch (\Throwable $e) {
                $error = $e->getMessage() ?: 'ما قدرناش نبعتو الرمز — حاول بعد دقيقة.';
            }
        }

        return response()->view('admin-2fa', [
            'phone' => AdminTwoFactor::masked($user),
            'channel' => session('admin_2fa_channel'),
            'error' => $error ?? session('error'),
            'trustDays' => (int) Options::get('security.admin_2fa_trust_days'),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10'], 'trust' => ['nullable']]);
        $user = $request->user();

        try {
            AdminTwoFactor::verify($user, $data['code']);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        $request->session()->regenerate();
        session([AdminTwoFactor::SESSION_KEY => $user->id]);
        session()->forget(['admin_2fa_sent_at', 'admin_2fa_channel']);
        if ($request->boolean('trust')) {
            AdminTwoFactor::trust($user);
        }

        return redirect()->intended(url('/admin'));
    }

    public function resend(Request $request): RedirectResponse
    {
        session()->forget('admin_2fa_sent_at');

        return redirect()->route('admin.2fa');
    }
}
