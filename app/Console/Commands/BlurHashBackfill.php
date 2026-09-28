<?php

namespace App\Console\Commands;

use App\Models\AppSection;
use App\Models\Banner;
use App\Models\Product;
use App\Models\ProductOptionValue;
use App\Models\ReadyCart;
use App\Models\Store;
use App\Support\BlurHash;
use Illuminate\Console\Command;

/** يحسب BlurHash للصور القديمة اللي ما عندهاش — يتشغّل مع كل تحديث ويكمّل الناقص بس */
class BlurHashBackfill extends Command
{
    protected $signature = 'images:blurhash {--all : عاود الحساب لكل الصور}';

    protected $description = 'حساب BlurHash للصور اللي ما عندهاش';

    public function handle(): int
    {
        $all = (bool) $this->option('all');
        $n = 0;

        foreach ([Store::class, Banner::class, ProductOptionValue::class, AppSection::class, ReadyCart::class] as $model) {
            foreach ((new $model)::BLURHASH_FIELDS() as $field => $col) {
                $model::query()->whereNotNull($field)->where($field, '!=', '')
                    ->when(! $all, fn ($q) => $q->whereNull($col))
                    ->chunkById(100, function ($rows) use ($field, $col, &$n) {
                        foreach ($rows as $r) {
                            $r->{$col} = BlurHash::fromPath($r->{$field});
                            $r->saveQuietly();
                            $n++;
                        }
                    });
            }
        }

        Product::withTrashed()->whereNotNull('images')
            ->when(! $all, fn ($q) => $q->whereNull('image_hashes'))
            ->chunkById(100, function ($rows) use (&$n) {
                foreach ($rows as $p) {
                    $h = [];
                    foreach (array_values(array_filter((array) $p->images)) as $path) {
                        $h[$path] = BlurHash::fromPath($path);
                    }
                    $p->image_hashes = $h ?: null;
                    $p->saveQuietly();
                    $n++;
                }
            });

        $this->info("BlurHash: $n صورة/سجل");

        return self::SUCCESS;
    }
}
