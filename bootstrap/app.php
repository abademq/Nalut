<?php

use App\Http\Middleware\EmergencyGate;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\LogApiActivity;
use App\Http\Middleware\RecordTraffic;
use App\Http\Middleware\VerifyAppCheck;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureRole::class,
            'appcheck' => VerifyAppCheck::class,
        ]);

        // حالة السيرفر: عدّاد خفيف لكل طلب (ملف نصي، بعد ما الرد يوصل)
        $middleware->append(RecordTraffic::class);

        // مركز الطوارئ: يوقف الأجزاء اللي الإدارة قفلتها (قبل الدخول وأي كود ثاني)
        $middleware->append(EmergencyGate::class);

        // سجل النشاط: كل عملية من التطبيقات
        $middleware->api(append: LogApiActivity::class);

        // ما فيش مسار باسم login — بدونه أي طلب API بدون توكن يطيح بخطأ 500 بدل 401
        $middleware->redirectGuestsTo(
            fn ($request) => $request->is('api/*') ? null : '/admin/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
