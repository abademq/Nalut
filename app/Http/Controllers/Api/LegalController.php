<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LegalDocument;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** الشروط والخصوصية داخل التطبيقات (نافذة منبثقة) + الموافقة الصريحة */
class LegalController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    private function app(Request $request): string
    {
        $app = $request->header('X-App') ?: $request->input('app');

        return in_array($app, User::APPS, true) ? $app : 'customer';
    }

    /** بدون تسجيل دخول: الوثائق اللي تخص التطبيق (بدون النص) */
    public function index(Request $request): JsonResponse
    {
        $docs = LegalDocument::whereIn('key', LegalDocument::forApp($this->app($request)))->get()
            ->sortBy(fn ($d) => array_search($d->key, LegalDocument::forApp($this->app($request)), true))
            ->map(fn ($d) => ['key' => $d->key, 'title' => $d->title, 'version' => $d->version])->values();

        return response()->json(['data' => $docs]);
    }

    public function show(string $key): JsonResponse
    {
        $doc = LegalDocument::where('key', str_replace('-', '_', $key))->firstOrFail();

        return response()->json(['data' => $doc->toApp()]);
    }

    /** اللي لازم يوافق عليه + اختياره للتسويق */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'needed' => $this->consents->needed($user, $this->app($request)),
            'marketing_opt_in' => ! $user->marketing_opt_out,
            // ما اختارش بنفسه لسه — التطبيق يسأله (مربع مش معلّم)
            'marketing_asked' => $user->marketing_choice_at !== null,
        ]);
    }

    public function accept(Request $request): JsonResponse
    {
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1'],
            'documents.*' => ['string', 'max:30'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();
        $this->consents->accept($user, $this->app($request), $data['documents'], $request);
        if (array_key_exists('marketing_opt_in', $data) && $data['marketing_opt_in'] !== null) {
            $this->consents->setMarketing($user, (bool) $data['marketing_opt_in']);
        }

        return response()->json(['message' => 'شكراً — تم تسجيل موافقتك.', 'needed' => []]);
    }

    public function marketing(Request $request): JsonResponse
    {
        $data = $request->validate(['opt_in' => ['required', 'boolean']]);
        $this->consents->setMarketing($request->user(), (bool) $data['opt_in']);

        return response()->json([
            'marketing_opt_in' => (bool) $data['opt_in'],
            'message' => $data['opt_in'] ? 'بتوصلك العروض والتخفيضات.' : 'وقفنا الرسائل التسويقية. إشعارات طلباتك تقعد توصلك عادي.',
        ]);
    }
}
