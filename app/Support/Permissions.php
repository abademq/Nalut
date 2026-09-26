<?php

namespace App\Support;

/**
 * صلاحيات مستخدمي النظام.
 * حساب الإدارة اللي صلاحياته null = صلاحية كاملة.
 */
class Permissions
{
    public const LIST = [
        'orders.view'      => 'عرض الطلبات',
        'orders.manage'    => 'تغيير حالة الطلبات وإنشاء طلب يدوي',
        'stores.manage'    => 'إدارة المتاجر',
        'products.manage'  => 'إدارة المنتجات',
        'users.view'       => 'عرض المستخدمين',
        'users.manage'     => 'إضافة وتعديل المستخدمين',
        'finance.view'     => 'عرض الحركات المالية',
        'finance.manage'   => 'شحن المحافظ والتسويات والصرف',
        'cards.manage'     => 'إدارة كروت الشحن',
        'coupons.manage'   => 'إدارة الكوبونات',
        'settings.manage'  => 'إعدادات المناطق وأنواع المتاجر',
        'messages.manage'  => 'الرسائل: الحملات التسويقية والتقارير والقوالب',
        'logs.view'        => 'عرض سجل النشاط',
    ];

    /** مجموعات جاهزة تسهّل الاختيار */
    public const PRESETS = [
        'operator'   => ['orders.view', 'orders.manage', 'users.view'],
        'accountant' => ['finance.view', 'finance.manage', 'cards.manage', 'orders.view'],
        'catalog'    => ['stores.manage', 'products.manage', 'coupons.manage'],
    ];

    public static function labels(): array
    {
        return self::LIST;
    }
}
