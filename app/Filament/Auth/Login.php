<?php

namespace App\Filament\Auth;

use App\Support\Recaptcha;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

/** دخول لوحة التحكم + reCAPTCHA (لو المفاتيح موجودة في .env) */
class Login extends BaseLogin
{
    public ?string $captcha = null;

    public function form(Schema $schema): Schema
    {
        $components = [
            $this->getEmailFormComponent(),
            $this->getPasswordFormComponent(),
            $this->getRememberFormComponent(),
        ];

        if (Recaptcha::adminEnabled()) {
            $components[] = Html::make(new HtmlString($this->widget()));
        }

        return $schema->components($components);
    }

    private function widget(): string
    {
        $key = e(Recaptcha::siteKey());

        return <<<HTML
<div wire:ignore style="display:flex;justify-content:center;min-height:78px"
     x-data="{ id: null }"
     x-init="
        const draw = () => {
            if (! window.grecaptcha || ! window.grecaptcha.render) { return setTimeout(draw, 200) }
            id = grecaptcha.render(\$el.firstElementChild, {
                sitekey: '{$key}',
                callback: t => \$wire.set('captcha', t, false),
                'expired-callback': () => \$wire.set('captcha', null, false),
            })
        };
        if (! document.getElementById('rc-api')) {
            const s = document.createElement('script');
            s.id = 'rc-api'; s.async = true; s.src = 'https://www.google.com/recaptcha/api.js?render=explicit&hl=ar';
            document.head.appendChild(s);
        }
        draw();
     "
     x-on:recaptcha-reset.window="if (id !== null) { grecaptcha.reset(id) }">
    <div></div>
</div>
HTML;
    }

    public function authenticate(): ?LoginResponse
    {
        if (! Recaptcha::adminEnabled()) {
            return parent::authenticate();
        }

        $token = $this->captcha;
        $this->captcha = null;
        // الرمز يستعمل مرة وحدة — بعد أي محاولة نجددوه
        $this->dispatch('recaptcha-reset');

        if (! Recaptcha::verify($token, request()->ip())) {
            throw ValidationException::withMessages([
                'data.email' => 'أكّد إنك مش روبوت (علّم على مربع reCAPTCHA).',
            ]);
        }

        return parent::authenticate();
    }
}
