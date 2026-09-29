<?php

namespace App\Services;

use App\Models\LegalDocument;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * الموافقة الصريحة: كل تطبيق يوافق على وثائقه (الشروط/الاتفاقية + الخصوصية).
 * لو الإدارة نشرت نسخة جديدة تتطلب موافقة، يرجع يطلبها.
 */
class ConsentService
{
    /** @return list<array{key:string,title:string,version:int}> */
    public function needed(User $user, string $app): array
    {
        $docs = LegalDocument::whereIn('key', LegalDocument::forApp($app))->get()->keyBy('key');
        $accepted = UserConsent::where('user_id', $user->id)
            ->whereIn('document', $docs->keys())
            ->selectRaw('document, MAX(version) as v')->groupBy('document')->pluck('v', 'document');

        $out = [];
        foreach (LegalDocument::forApp($app) as $key) {
            $doc = $docs->get($key);
            if ($doc && (int) ($accepted[$key] ?? 0) < $doc->version) {
                $out[] = ['key' => $key, 'title' => $doc->title, 'version' => $doc->version];
            }
        }

        return $out;
    }

    public function accept(User $user, string $app, array $keys, Request $request): void
    {
        $required = LegalDocument::forApp($app);
        $missing = array_diff(array_column($this->needed($user, $app), 'key'), $keys);
        if ($missing) {
            throw ValidationException::withMessages(['documents' => 'لازم توافق على الشروط وسياسة الخصوصية باش تكمّل.']);
        }

        foreach (LegalDocument::whereIn('key', array_intersect($keys, $required))->get() as $doc) {
            UserConsent::create([
                'user_id' => $user->id,
                'document' => $doc->key,
                'version' => $doc->version,
                'app' => $app,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]);
        }
    }

    /** الرسائل التسويقية: اختيار صريح (Apple وGoogle يطلبوه) */
    public function setMarketing(User $user, bool $optIn): void
    {
        $user->forceFill(['marketing_opt_out' => ! $optIn, 'marketing_choice_at' => now()])->save();
    }
}
