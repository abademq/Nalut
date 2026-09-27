<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Settlements\Pages\ViewSettlement;
use App\Models\Settlement;
use App\Models\User;
use App\Services\SettlementService;
use App\Support\Perm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** حسابات المتاجر والسائقين: مين عليه ومين له، كشف مفصّل، وتسوية بواصل */
class SettlementDesk extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'التسويات';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settlement-desk';

    protected string $view = 'filament.settlements.desk';

    #[Url]
    public string $party = 'store';

    #[Url(as: 'account')]
    public ?int $userId = null;

    public string $search = '';

    /** only = عرض اللي عليهم أو لهم رصيد بس */
    public bool $onlyDue = true;

    public static function canAccess(): bool
    {
        return Perm::can('settlements.view') || Perm::can('settlements.manage');
    }

    public static function getNavigationLabel(): string
    {
        return 'حسابات المتاجر والسائقين';
    }

    public function getTitle(): string
    {
        return 'حسابات المتاجر والسائقين';
    }

    public function setParty(string $party): void
    {
        $this->party = in_array($party, ['store', 'driver'], true) ? $party : 'store';
        $this->userId = null;
    }

    public function selectAccount(int $id): void
    {
        $this->userId = $id;
    }

    protected function getViewData(): array
    {
        $service = app(SettlementService::class);
        $accounts = $service->accounts($this->party);

        $totals = [
            'pay' => round($accounts->where('balance', '>', 0)->sum('balance'), 2),
            'receive' => round(abs($accounts->where('balance', '<', 0)->sum('balance')), 2),
        ];

        if ($this->onlyDue) {
            $accounts = $accounts->filter(fn ($a) => abs($a['balance']) >= 0.01 || $a['orders'] > 0 || $a['id'] === $this->userId);
        }
        if (($q = trim($this->search)) !== '') {
            $accounts = $accounts->filter(fn ($a) => str_contains($a['name'].' '.$a['sub'], $q));
        }

        $user = $this->userId ? User::find($this->userId) : null;

        return [
            'accounts' => $accounts->sortByDesc(fn ($a) => abs($a['balance']))->values(),
            'totals' => $totals,
            'account' => $user,
            'statement' => $user ? $service->statement($user, $this->party) : null,
            'canManage' => Perm::can('settlements.manage'),
        ];
    }

    public function settleAction(): Action
    {
        return Action::make('settle')
            ->label('تسوية وطباعة واصل')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn () => $this->userId && Perm::can('settlements.manage'))
            ->modalHeading(fn () => 'تسوية: '.($this->userId ? User::find($this->userId)?->name : ''))
            ->fillForm(function () {
                $st = app(SettlementService::class)->statement(User::findOrFail($this->userId), $this->party);

                return [
                    'direction' => $st['direction'] === 'receive' ? 'receive' : 'pay',
                    'amount' => $st['due'] ?: null,
                    'method' => 'cash',
                ];
            })
            ->schema([
                Radio::make('direction')->label('العملية')->required()->inline()
                    ->options(fn () => $this->party === 'store'
                        ? ['pay' => 'صرفنا للمتجر مستحقاته', 'receive' => 'استلمنا من المتجر']
                        : ['receive' => 'استلمنا من السائق الكاش', 'pay' => 'صرفنا للسائق مستحقاته']),
                TextInput::make('amount')->label('المبلغ (د.ل)')->numeric()->required()->minValue(0.01)
                    ->helperText(function () {
                        $st = app(SettlementService::class)->statement(User::findOrFail($this->userId), $this->party);

                        return match ($st['direction']) {
                            'pay' => 'المستحق له: '.number_format($st['due'], 2).' د.ل — تقدر تصرف جزء بس.',
                            'receive' => 'اللي عليه: '.number_format($st['due'], 2).' د.ل — تقدر تستلم جزء بس.',
                            default => 'الحساب متسوّي (الرصيد صفر).',
                        };
                    }),
                Select::make('method')->label('طريقة الدفع')->options(Settlement::METHODS)->required()->native(false),
                TextInput::make('reference')->label('رقم مرجعي')->placeholder('رقم الحوالة أو الصك (اختياري)')->maxLength(100),
                TextInput::make('note')->label('ملاحظة')->maxLength(300),
            ])
            ->action(function (array $data) {
                $s = app(SettlementService::class)->create(
                    User::findOrFail($this->userId), $this->party, (float) $data['amount'], $data['method'],
                    $data['reference'] ?? null, $data['note'] ?? null, auth()->user(), $data['direction'],
                );

                Notification::make()->title("تمت التسوية {$s->number}")->success()
                    ->body('الرصيد الجديد: '.number_format($s->balance_after, 2).' د.ل')->send();

                $this->redirect(ViewSettlement::getUrl(['record' => $s]));
            });
    }
}
