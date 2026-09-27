<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Settlement;
use App\Support\Options;
use App\Support\Perm;
use Illuminate\Http\Request;

/** واصل التسوية للطباعة — من اللوحة (دخول) أو من التطبيق (رابط موقّع) */
class SettlementPrintController extends Controller
{
    public function __invoke(Request $request, Settlement $settlement)
    {
        $signed = $request->hasValidSignature();
        if (! $signed) {
            $user = auth('web')->user();
            abort_unless($user && $user->canAccessPanel() && (Perm::can('settlements.view') || Perm::can('settlements.manage')), 403);
        }

        $paper = $request->query('paper') === 'a4' ? 'a4' : '80';

        return view('settlements.print', [
            's' => $settlement->load(['user', 'store', 'creator']),
            'paper' => $paper,
            'orders' => $paper === 'a4' && Options::get('settlement.show_orders')
                ? $settlement->orders()->with(['store', 'driver'])->orderBy('delivered_at')->get() : collect(),
            'logo' => BrandingSettings::logoUrl(),
            'company' => Options::get('settlement.company_name'),
            'footer' => Options::get('settlement.footer'),
            'app' => BrandingSettings::receipt()['header'],
            'auto' => ! $signed || $request->boolean('print'),
        ]);
    }
}
