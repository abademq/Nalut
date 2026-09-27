<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\Filter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** كل فلتر في كل جدول في اللوحة لازم يشتغل (كان فيه فلاتر تطيّح الصفحة) */
class AdminFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_table_filter_works(): void
    {
        $admin = User::create(['name' => 'م', 'phone' => '0919999999', 'email' => 'a@a.ly', 'password' => 'secret123',
            'role' => UserRole::Admin->value, 'is_active' => true]);
        $this->actingAs($admin, 'web');

        $checked = 0;
        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $index = $resource::getPages()['index'] ?? null;
            $page = $index?->getPage();
            if (! $page || ! is_subclass_of($page, ListRecords::class)) {
                continue;
            }
            $lw = Livewire::test($page)->assertOk();
            foreach ($lw->instance()->getTable()->getFilters() as $name => $filter) {
                // فلاتر «تشغيل/إيقاف» (Filter) — القوائم (SelectFilter) تحتاج قيمة
                if (get_class($filter) === Filter::class) {
                    Livewire::test($page)->filterTable($name)->assertOk();
                    $checked++;
                }
            }
        }
        $this->assertGreaterThan(3, $checked);
    }
}
