<?php

namespace App\Http\Controllers\Api;

use App\Filament\Pages\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

/** محتوى تطبيق الزبون اللي يتدار من لوحة التحكم: الإعلانات و«عن التطبيق» */
class AppContentController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'banners' => Banner::live()->get()->map->toApp()->values(),
            'about'   => AppSettings::values(),
        ]);
    }
}
