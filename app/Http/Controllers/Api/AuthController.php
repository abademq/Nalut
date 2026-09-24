<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** إرسال رمز تحقق للهاتف */
    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^(09[1-5][0-9]{7})$/'],
        ], [], ['phone' => 'رقم الهاتف']);

        $recent = DB::table('otp_codes')
            ->where('phone', $data['phone'])
            ->where('created_at', '>', now()->subMinute())
            ->count();

        if ($recent >= 2) {
            throw ValidationException::withMessages(['phone' => 'استنى دقيقة قبل ما تطلب رمز جديد.']);
        }

        $code = config('app.env') === 'production'
            ? str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)
            : '1234';

        DB::table('otp_codes')->insert([
            'phone'      => $data['phone'],
            'code'       => $code,
            'expires_at' => now()->addSeconds((int) env('OTP_TTL_SECONDS', 300)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // TODO: أرسل الرمز عبر مزوّد SMS أو واتساب

        return response()->json([
            'message' => 'تم إرسال رمز التحقق.',
            'debug_code' => env('OTP_DEV_MODE', false) ? $code : null,
        ]);
    }

    /** التحقق من الرمز وإصدار التوكن */
    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string'],
            'code'  => ['required', 'string'],
            'name'  => ['nullable', 'string', 'max:60'],
            'role'  => ['nullable', 'in:customer,store,driver'],
            'create_account' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:6', 'max:60'],
            'fcm_token' => ['nullable', 'string'],
        ]);

        $otp = DB::table('otp_codes')
            ->where('phone', $data['phone'])
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $otp || ! hash_equals($otp->code, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'الرمز غير صحيح أو منتهي.']);
        }

        DB::table('otp_codes')->where('id', $otp->id)->update(['consumed_at' => now()]);

        $user = User::where('phone', $data['phone'])->first();

        // التسجيل صريح: ما نفتحش حساب جديد لمّا يكون قاصد يدخل
        if (! $user) {
            if (! ($data['create_account'] ?? false)) {
                throw ValidationException::withMessages([
                    'phone' => 'ما فيش حساب بهذا الرقم. اختار «إنشاء حساب جديد».',
                ]);
            }

            if (blank($data['name'] ?? null)) {
                throw ValidationException::withMessages([
                    'name' => 'اكتب اسمك لإنشاء الحساب.',
                ]);
            }

            // is_active لازم تنكتب صراحةً — قيمة قاعدة البيانات الافتراضية
            // ما تنعكسش على الكائن في الذاكرة، فالفحص اللي بعدها كان يفشل
            $user = User::create([
                'name'      => $data['name'],
                'phone'     => $data['phone'],
                'role'      => $data['role'] ?? UserRole::Customer->value,
                'is_active' => true,
                'password'  => isset($data['password'])
                    ? Hash::make($data['password'])
                    : null,
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['phone' => 'الحساب موقوف، تواصل مع الإدارة.']);
        }

        $user->forceFill([
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            'last_seen_at'      => now(),
            'fcm_token'         => $data['fcm_token'] ?? $user->fcm_token,
        ])->save();

        return response()->json([
            'token' => $user->createToken('mobile')->plainTextToken,
            'user'  => $this->userPayload($user),
        ]);
    }

    /**
     * دخول بكلمة المرور — الطريق الأساسي للحسابات الموجودة.
     * رمز التحقق يضل متاح كبديل لمّا ينسى كلمة المرور.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone'     => ['required', 'string', 'regex:/^(09[1-6][0-9]{7})$/'],
            'password'  => ['required', 'string'],
            'fcm_token' => ['nullable', 'string'],
        ]);

        $user = User::where('phone', $data['phone'])->first();

        if (! $user) {
            throw ValidationException::withMessages([
                'phone' => 'ما فيش حساب بهذا الرقم.',
            ]);
        }

        if (blank($user->password)) {
            throw ValidationException::withMessages([
                'password' => 'الحساب ما عندوش كلمة مرور. ادخل برمز التحقق وبعدها اضبطها من حسابك.',
            ]);
        }

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'كلمة المرور غير صحيحة.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'phone' => 'الحساب موقوف. تواصل مع الإدارة.',
            ]);
        }

        if (! empty($data['fcm_token'])) {
            $user->update(['fcm_token' => $data['fcm_token']]);
        }

        return response()->json([
            'token' => $user->createToken('app')->plainTextToken,
            'user'  => new UserResource($user->load('store', 'driverProfile')),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => ['nullable', 'string', 'max:60'],
            'email'     => ['nullable', 'email', 'unique:users,email,'.$request->user()->id],
            'password'  => ['nullable', 'string', 'min:6', 'max:60'],
            'fcm_token' => ['nullable', 'string'],
        ]);

        // الحقول الفاضية ما تمسحش القيم الموجودة
        $request->user()->update(array_filter(
            $data,
            fn ($v) => $v !== null && $v !== ''
        ));

        return response()->json(['user' => $this->userPayload($request->user()->fresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing(['store', 'driverProfile']);

        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'phone'    => $user->phone,
            'role'     => $user->role->value,
            'avatar'   => $user->avatar ? asset('storage/'.$user->avatar) : null,
            'store_id' => $user->store?->id,
            'driver'   => $user->driverProfile ? [
                'is_approved'  => (bool) $user->driverProfile->is_approved,
                'is_online'    => (bool) $user->driverProfile->is_online,
                'cash_in_hand' => (float) $user->driverProfile->cash_in_hand,
            ] : null,
        ];
    }
}
