<?php

namespace App\Models;

use App\Support\Texts;
use Illuminate\Database\Eloquent\Model;

/**
 * قالب رسالة معتمد عند المزوّد.
 * params: قيم المتغيرات بالترتيب — كل واحدة نص فيه {متغيرات} نعبّوها وقت الإرسال.
 *   واتساب: {{1}} {{2}} … · رسالة: $1 $2 …
 */
class MessageTemplate extends Model
{
    protected $fillable = ['name', 'channel', 'provider_ref', 'language', 'params', 'preview', 'purpose', 'is_active'];

    protected function casts(): array
    {
        return ['params' => 'array', 'is_active' => 'boolean'];
    }

    public const CHANNELS = ['whatsapp' => 'واتساب', 'sms' => 'رسالة نصية (SMS)'];

    public const PURPOSES = [
        'marketing' => 'تسويق',
        'report'    => 'تقارير',
        'alert'     => 'تنبيهات الإدارة',
        'general'   => 'عام',
    ];

    /** قيم المتغيرات بعد التعبئة */
    public function resolveParams(array $vars): array
    {
        return array_map(fn ($p) => Texts::fill((string) $p, $vars), array_values($this->params ?? []));
    }

    /** معاينة تقريبية للنص اللي بيوصل */
    public function render(array $vars): string
    {
        $text = (string) ($this->preview ?: implode(' | ', $this->params ?? []));
        $values = $this->resolveParams($vars);

        foreach ($values as $i => $v) {
            $n = $i + 1;
            $text = str_replace(['{{'.$n.'}}', '$'.$n], $v, $text);
        }

        return Texts::fill($text, $vars);
    }
}
