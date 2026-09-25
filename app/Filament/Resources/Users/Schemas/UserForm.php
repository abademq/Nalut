<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Support\Permissions;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(60),

                TextInput::make('phone')
                    ->label('رقم الهاتف')
                    ->required()
                    ->tel()
                    ->unique(ignoreRecord: true)
                    ->helperText('بصيغة 09XXXXXXXX'),

                Select::make('role')
                    ->label('الدور')
                    // دور «إدارة» يظهر للمدير الكامل بس — منع تصعيد الصلاحيات
                    ->options(fn () => collect(UserRole::cases())
                        ->reject(fn ($r) => $r === UserRole::Admin && ! \App\Support\Perm::isSuper())
                        ->mapWithKeys(fn ($r) => [$r->value => $r->label()])
                        ->all())
                    ->required()
                    ->live()
                    ->default(UserRole::Customer->value),

                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required(fn ($get) => $get('role') === UserRole::Admin->value)
                    ->helperText('مطلوب لمستخدمي النظام — بيه يدخلو للوحة'),

                TextInput::make('password')
                    ->label('كلمة المرور')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn ($get, $record) => $get('role') === UserRole::Admin->value && ! $record)
                    ->helperText('اتركها فاضية لو ما تبيش تغييرها'),

                Toggle::make('is_active')
                    ->label('الحساب مفعّل')
                    ->default(true)
                    ->columnSpanFull(),

                CheckboxList::make('permissions')
                    ->label('الصلاحيات')
                    ->options(Permissions::labels())
                    ->columns(2)
                    ->columnSpanFull()
                    ->bulkToggleable()
                    ->visible(fn ($get) => $get('role') === UserRole::Admin->value && \App\Support\Perm::isSuper())
                    ->helperText('لو ما اخترت ولا وحدة، الحساب ياخذ صلاحية كاملة. '
                        .'اختار صلاحيات محددة باش تقيّده.'),
            ]);
    }
}
