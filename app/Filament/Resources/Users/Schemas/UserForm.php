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

                // نفس الرقم ممكن يكون زبون وسائق ومتجر وإدارة مع بعض
                CheckboxList::make('roles')
                    ->label('الأدوار')
                    // دور «إدارة» يظهر للمدير الكامل بس — منع تصعيد الصلاحيات
                    ->options(fn () => collect(UserRole::cases())
                        ->reject(fn ($r) => $r === UserRole::Admin && ! \App\Support\Perm::isSuper())
                        ->mapWithKeys(fn ($r) => [$r->value => $r->label()])
                        ->all())
                    ->columns(4)
                    ->required()
                    ->minItems(1)
                    ->live()
                    ->default([UserRole::Customer->value])
                    ->helperText('اختار أكثر من دور لنفس الرقم لو يحتاج (مثلاً زبون + سائق). كل تطبيق يدخل بالدور متاعه.')
                    ->columnSpanFull(),

                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required(fn ($get) => in_array(UserRole::Admin->value, (array) $get('roles'), true))
                    ->helperText('مطلوب لمستخدمي النظام — بيه يدخلو للوحة'),

                TextInput::make('password')
                    ->label('كلمة المرور')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn ($state) => filled($state))
                    ->required(fn ($get, $record) => in_array(UserRole::Admin->value, (array) $get('roles'), true) && ! $record)
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
                    // فاضية = صلاحية كاملة (null) — مش مصفوفة فاضية تقفل كل شي
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? array_values($state) : null)
                    ->visible(fn ($get) => in_array(UserRole::Admin->value, (array) $get('roles'), true) && \App\Support\Perm::isSuper())
                    ->helperText('لو ما اخترت ولا وحدة، الحساب ياخذ صلاحية كاملة. '
                        .'اختار صلاحيات محددة باش تقيّده.'),
            ]);
    }
}
