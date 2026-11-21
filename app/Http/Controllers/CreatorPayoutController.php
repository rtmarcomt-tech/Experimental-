<?php

namespace App\Http\Controllers;

use App\Services\Payments\CreatorPayoutRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreatorPayoutController extends Controller
{
    public function store(Request $request, CreatorPayoutRequestService $service): RedirectResponse
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile, 403);

        $payout = $service->request($profile, $request->user()->id);

        return redirect()->route('dashboard')->with(
            'success',
            'Pedido de pagamento de '.number_format($payout->amount_minor / 100, 2, ',', '.').' '.$payout->currency.' enviado para validação administrativa.'
        );
    }
}
