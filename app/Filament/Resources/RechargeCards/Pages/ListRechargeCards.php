<?php

namespace App\Filament\Resources\RechargeCards\Pages;

use App\Filament\Resources\RechargeCards\RechargeCardResource;
use App\Models\RechargeCard;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRechargeCards extends ListRecords
{
    protected static string $resource = RechargeCardResource::class;

 
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('توليد كروت')
                ->icon('heroicon-o-plus')
                ->modalDescription('تتولّد أكواد عشوائية بصيغة NLT-XXXX-XXXX بدون حروف ملتبسة.')
                ->schema([
                    TextInput::make('count')
                        ->label('عدد الكروت')
                        ->numeric()->required()->default(10)->minValue(1)->maxValue(500),

                    TextInput::make('amount')
                        ->label('قيمة كل كرت (د.ل)')
                        ->numeric()->required()->default(10)->minValue(1),

                    TextInput::make('batch')
                        ->label('اسم الدفعة')
                        ->maxLength(40)
                        ->default(fn () => 'دفعة '.now()->format('d-m-Y'))
                        ->helperText('يساعدك تطبع وتتابع كل مجموعة لحالها'),

                    DatePicker::make('expires_at')
                        ->label('تاريخ الانتهاء')
                        ->helperText('اتركه فاضي = بدون انتهاء'),
                ])
                ->action(function (array $data) {
                    $count = (int) $data['count'];

                    for ($i = 0; $i < $count; $i++) {
                        RechargeCard::create([
                            'code'       => RechargeCard::generateCode(),
                            'amount'     => $data['amount'],
                            'batch'      => $data['batch'] ?? null,
                            'expires_at' => $data['expires_at'] ?? null,
                            'status'     => 'unused',
                            'created_by' => auth()->id(),
                        ]);
                    }

                    Notification::make()
                        ->title("تم توليد {$count} كرت")
                        ->body('القيمة الإجمالية: '
                            .number_format($count * (float) $data['amount'], 2).' د.ل')
                        ->success()
                        ->send();
                }),

            Action::make('exportExcel')
                ->label('تصدير Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->schema([
                    Select::make('batch')
                        ->label('الدفعة')
                        ->options(fn () => RechargeCard::query()
                            ->whereNotNull('batch')->distinct()->pluck('batch', 'batch')->all())
                        ->placeholder('كل الدفعات'),

                    Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'unused'   => 'غير مستعملة',
                            'used'     => 'مستعملة',
                            'disabled' => 'موقوفة',
                        ])
                        ->default('unused')
                        ->placeholder('كل الحالات'),
                ])
                ->action(function (array $data) {
                    $cards = self::queryCards($data)->get();

                    $fileName = 'cards-'.now()->format('Y-m-d-His').'.csv';

                    return response()->streamDownload(function () use ($cards) {
                        // BOM باش Excel يقرا العربي صح
                        echo "\xEF\xBB\xBF";
                        $out = fopen('php://output', 'w');

                        fputcsv($out, ['الكود', 'القيمة', 'الدفعة', 'الحالة', 'تاريخ الانتهاء', 'استعمله', 'تاريخ الاستعمال']);

                        foreach ($cards as $c) {
                            fputcsv($out, [
                                // مسافات بين كل 4 أرقام: Excel يقراه نص مش رقم (بدونها يطلع 1.23E+11)
                                RechargeCard::format($c->code),
                                number_format((float) $c->amount, 2, '.', ''),
                                $c->batch,
                                $c->statusLabel(),
                                $c->expires_at?->format('Y-m-d'),
                                $c->user?->name,
                                $c->used_at?->format('Y-m-d H:i'),
                            ]);
                        }

                        fclose($out);
                    }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),

            Action::make('print')
                ->label('طباعة')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->modalDescription('ينزّل ملف HTML — افتحه واضغط Ctrl+P للطباعة أو الحفظ كـ PDF.')
                ->schema([
                    Select::make('batch')
                        ->label('الدفعة')
                        ->options(fn () => RechargeCard::query()
                            ->whereNotNull('batch')->distinct()->pluck('batch', 'batch')->all())
                        ->placeholder('كل الدفعات'),

                    Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'unused'   => 'غير مستعملة',
                            'used'     => 'مستعملة',
                            'disabled' => 'موقوفة',
                        ])
                        ->default('unused')
                        ->placeholder('كل الحالات'),
                ])
                ->action(function (array $data) {
                    $cards = self::queryCards($data)->get();
                    $title = 'كروت شحن — '.($data['batch'] ?? 'كل الدفعات');

                    $html = '<!DOCTYPE html><html dir="rtl" lang="ar"><head><meta charset="utf-8">'
                        .'<title>'.e($title).'</title><style>'
                        .'body{font-family:Tahoma,Arial,sans-serif;margin:16px;background:#fff}'
                        .'h1{font-size:18px;margin:0 0 12px}'
                        .'.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}'
                        .'.card{border:2px dashed #555;border-radius:10px;padding:14px;text-align:center;page-break-inside:avoid}'
                        .'.brand{font-size:12px;color:#666;margin-bottom:6px}'
                        .'.code{font-family:Consolas,monospace;font-size:20px;font-weight:bold;letter-spacing:1px;direction:ltr}'
                        .'.amount{font-size:15px;margin-top:6px}'
                        .'.meta{font-size:10px;color:#888;margin-top:6px}'
                        .'@media print{@page{margin:10mm}}'
                        .'</style></head><body><h1>'.e($title).'</h1><div class="grid">';

                    foreach ($cards as $c) {
                        $html .= '<div class="card">'
                            .'<div class="brand">توصيل نالوت — كرت شحن</div>'
                            .'<div class="code">'.e(RechargeCard::format($c->code)).'</div>'
                            .'<div class="amount">'.number_format((float) $c->amount, 2).' د.ل</div>'
                            .'<div class="meta">'
                            .($c->expires_at ? 'ينتهي: '.$c->expires_at->format('Y-m-d') : 'بدون انتهاء')
                            .'</div></div>';
                    }

                    $html .= '</div></body></html>';

                    $fileName = 'cards-print-'.now()->format('Y-m-d-His').'.html';

                    return response()->streamDownload(
                        fn () => print ($html),
                        $fileName,
                        ['Content-Type' => 'text/html; charset=UTF-8']
                    );
                }),
        ];
    }

    private static function queryCards(array $data)
    {
        return RechargeCard::query()
            ->when($data['batch'] ?? null, fn ($q, $b) => $q->where('batch', $b))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with('user')
            ->orderBy('id');
    }
}
