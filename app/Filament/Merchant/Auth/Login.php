<?php

namespace App\Filament\Merchant\Auth;

use App\Filament\Auth\Login as AdminLogin;
use App\Support\Merchant;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** دخول صاحب المتجر: رقم الهاتف وكلمة المرور (نفس اللي في تطبيق المتجر) + reCAPTCHA */
class Login extends AdminLogin
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('phone')
            ->label('رقم الهاتف')
            ->tel()
            ->placeholder('09XXXXXXXX')
            ->required()
            ->regex('/^09[1-6][0-9]{7}$/')
            ->validationMessages(['regex' => 'اكتب الرقم بالشكل 09XXXXXXXX'])
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['dir' => 'ltr', 'inputmode' => 'numeric']);
    }

    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'phone' => $data['phone'],
            'password' => $data['password'],
        ];
    }

    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.phone' => Merchant::enabled()
                ? 'الرقم أو كلمة المرور غلط، أو الحساب مش مربوط بمتجر.'
                : 'لوحة المتاجر موقوفة حالياً — استعمل تطبيق المتجر.',
        ]);
    }

    protected function captchaErrorField(): string
    {
        return 'phone';
    }

    public function getHeading(): string|Htmlable
    {
        return 'لوحة المتجر';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return Merchant::enabled()
            ? 'ادخل بنفس رقم الهاتف وكلمة المرور اللي تستعملهم في تطبيق المتجر.'
            : 'لوحة المتاجر موقوفة حالياً.';
    }
}
