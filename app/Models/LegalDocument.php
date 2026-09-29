<?php

namespace App\Models;

use App\Filament\Pages\AppSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** سياسة الخصوصية والشروط — نص Markdown بسيط يتعدّل من اللوحة */
class LegalDocument extends Model
{
    protected $fillable = ['key', 'title', 'body', 'version'];

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public const DOCUMENTS = [
        'privacy' => 'سياسة الخصوصية',
        'terms_customer' => 'شروط الاستخدام',
        'terms_driver' => 'اتفاقية مندوب التوصيل المستقل',
        'terms_store' => 'اتفاقية الشريك التجاري (المتاجر)',
    ];

    /** كل تطبيق يوافق على شنو */
    public const FOR_APP = [
        'customer' => ['terms_customer', 'privacy'],
        'driver' => ['terms_driver', 'privacy'],
        'store' => ['terms_store', 'privacy'],
    ];

    public static function forApp(string $app): array
    {
        return self::FOR_APP[$app] ?? self::FOR_APP['customer'];
    }

    /** النص بعد تعبئة {app} {company} {phone} {email} {date} */
    public function rendered(): string
    {
        $about = AppSettings::values();

        return strtr($this->body, [
            '{app}' => $about['name'] ?? '',
            '{company}' => $about['company'] ?? '',
            '{phone}' => $about['phone'] ?: '—',
            '{email}' => $about['email'] ?: '—',
            '{date}' => $this->updated_at?->format('Y/m/d') ?? '',
        ]);
    }

    /** HTML بسيط للصفحة العامة (عناوين ##، نقاط -، **عريض**، > مقدمة) */
    public function html(): string
    {
        $out = '';
        $inList = false;
        foreach (preg_split('/\r\n|\n|\r/u', $this->rendered()) as $line) {
            $t = trim($line);
            $isItem = str_starts_with($t, '- ');
            if ($inList && ! $isItem) {
                $out .= '</ul>';
                $inList = false;
            }
            if ($t === '') {
                continue;
            }
            $fmt = fn ($s) => preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', e($s));
            if (str_starts_with($t, '## ')) {
                $out .= '<h2>'.$fmt(substr($t, 3)).'</h2>';
            } elseif ($isItem) {
                $out .= ($inList ? '' : '<ul>').'<li>'.$fmt(substr($t, 2)).'</li>';
                $inList = true;
            } elseif (str_starts_with($t, '> ')) {
                $out .= '<p class="lead">'.$fmt(substr($t, 2)).'</p>';
            } else {
                $out .= '<p>'.$fmt($t).'</p>';
            }
        }

        return $out.($inList ? '</ul>' : '');
    }

    public function toApp(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'version' => $this->version,
            'body' => $this->rendered(),
            'updated_at' => $this->updated_at,
            'url' => url('/legal/'.Str::of($this->key)->replace('_', '-')),
        ];
    }
}
