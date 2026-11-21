@extends('layouts.app')
@section('title','Administração — Marketplace')
@section('content')
<div class="wrap page">
    <div class="eyebrow">Back office</div>
    <h1 class="page-title">Painel de administração</h1>
    <p class="muted">Operação, criadores, catálogo, encomendas e liquidação financeira.</p>

    <div class="stats">
        <div class="stat"><strong>{{ $stats['creators'] }}</strong><span>Criadores registados</span></div>
        <div class="stat"><strong>{{ $stats['pending'] }}</strong><span>Perfis pendentes</span></div>
        <div class="stat"><strong>{{ $stats['published'] }}</strong><span>Conteúdos publicados</span></div>
        <div class="stat"><strong>{{ $stats['buyers'] }}</strong><span>Contas de utilizador</span></div>
    </div>

    <section class="panel">
        <div class="eyebrow">Estado da comunidade</div>
        <div class="stats">
            <div class="stat"><strong>{{ $stats['approved_creators'] }}</strong><span>Criadores aprovados</span></div>
            <div class="stat"><strong>{{ $stats['rejected_creators'] }}</strong><span>Candidaturas rejeitadas</span></div>
            <div class="stat"><strong>{{ $stats['active_accounts'] }}</strong><span>Contas ativas</span></div>
            <div class="stat"><strong>{{ $stats['suspended_accounts'] }}</strong><span>Contas suspensas</span></div>
        </div>
    </section>

    <section class="panel">
        <div class="eyebrow">Desempenho comercial</div>
        <h2 style="font:26px Georgia,serif;margin:8px 0 18px">Resumo financeiro</h2>
        <div class="stats">
            <div class="stat"><strong>{{ $stats['orders'] }}</strong><span>Pedidos totais</span></div>
            <div class="stat"><strong>{{ $stats['captured'] }}</strong><span>Pagamentos confirmados</span></div>
            <div class="stat"><strong>{{ number_format($stats['gross_minor']/100,2,',','.') }} €</strong><span>Volume confirmado</span></div>
            <div class="stat"><strong>{{ number_format($stats['commission_minor']/100,2,',','.') }} €</strong><span>Comissão contabilizada</span></div>
        </div>
        <div class="notice" style="margin-top:18px">Fundos em retenção: <strong>{{ number_format($stats['held_minor']/100,2,',','.') }} €</strong>. Este valor corresponde a retenções pendentes registadas no ledger, não a saldo bancário ou montante já transferido.</div>
    </section>

    <section>
        <div class="section-head">
            <div><div class="eyebrow">Transações</div><h2>Pedidos recentes</h2></div>
            <span class="muted">Últimos 12 pedidos</span>
        </div>
        @if($recentOrders->count())
            <div class="panel" style="padding:0;overflow:auto">
                <table class="table">
                    <thead><tr><th>Referência</th><th>Comprador</th><th>Conteúdo</th><th>Criador</th><th>Data</th><th>Valor</th><th>Estado</th></tr></thead>
                    <tbody>
                    @foreach($recentOrders as $order)
                        <tr>
                            <td><strong>{{ $order->order_reference }}</strong></td>
                            <td>{{ $order->buyer?->name ?? '—' }}</td>
                            <td>{{ $order->item?->title_snapshot ?? '—' }}</td>
                            <td>{{ $order->item?->creatorProfile?->public_name ?? '—' }}</td>
                            <td>{{ $order->created_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ number_format($order->gross_minor/100,2,',','.') }} {{ $order->currency }}</td>
                            <td><span class="pill">{{ match($order->status) {'captured'=>'Pago','refunded'=>'Reembolsado','cancelled'=>'Cancelado','awaiting_approval'=>'A aguardar',default=>'Pendente'} }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="empty">Ainda não existem pedidos registados.</div>
        @endif
    </section>

    <section>
        <div class="section-head">
            <div><div class="eyebrow">Liquidação</div><h2>Pedidos de pagamento</h2></div>
            <span class="muted">{{ $adminPayoutsOpen }} em aberto · últimos 30 pedidos</span>
        </div>
        @if($adminPayouts->count())
            <div class="panel" style="padding:0;overflow:auto">
                <table class="table">
                    <thead><tr><th>Criador</th><th>Destino PayPal</th><th>Montante</th><th>Pedido</th><th>Estado</th><th>Ação</th></tr></thead>
                    <tbody>
                    @foreach($adminPayouts as $payout)
                        <tr>
                            <td>{{ $payout->creatorProfile?->public_name ?? '—' }}</td>
                            <td>{{ $payout->recipient_email }}</td>
                            <td>{{ number_format($payout->amount_minor/100,2,',','.') }} {{ $payout->currency }}</td>
                            <td>{{ $payout->requested_at?->format('d/m/Y H:i') }}</td>
                            <td>{{ match($payout->status) {'requested'=>'Por enviar','processing'=>'Em processamento','submission_unknown'=>'Envio por confirmar','paid'=>'Pago','failed'=>'Falhou',default=>$payout->status} }}</td>
                            <td>
                                @if(in_array($payout->status,['requested','submission_unknown'],true) || ($payout->status==='processing' && ! $payout->paypal_batch_id))
                                    <form method="post" action="{{ route('admin.payouts.submit',$payout->id) }}">@csrf<button class="btn accent">Enviar / repetir com segurança</button></form>
                                @elseif($payout->status==='processing')
                                    <form method="post" action="{{ route('admin.payouts.reconcile',$payout->id) }}">@csrf<button class="btn secondary">Consultar estado</button></form>
                                @endif
                            </td>
                        </tr>
                        @if($payout->failure_reason)<tr><td colspan="6" class="muted">{{ $payout->failure_reason }}</td></tr>@endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="empty">Não existem pedidos de pagamento em aberto.</div>
        @endif
    </section>

    <section>
        <div class="section-head">
            <div><div class="eyebrow">Onboarding</div><h2>Candidaturas de criadores</h2></div>
            <span class="muted">{{ $pendingCases->count() }} por analisar</span>
        </div>
        @if($pendingCases->count())
            @foreach($pendingCases as $case)
                <div class="panel">
                    <div class="row">
                        <div>
                            <h3 style="font:23px Georgia,serif;margin:0">{{ $case->creatorProfile->public_name }}</h3>
                            <div class="muted">{{ $case->creatorProfile->user->email }} · submetida {{ $case->submitted_at?->format('d/m/Y H:i') }}</div>
                        </div>
                        <span class="pill">Pendente</span>
                    </div>
                    <p class="muted">{{ $case->creatorProfile->bio }}</p>
                    <div class="row">
                        <span class="muted">{{ $case->documents->count() }} documento(s) anexado(s)</span>
                        @foreach($case->documents as $doc)
                            <a class="btn secondary" href="{{ route('admin.document',$doc->id) }}">Ver identificação</a>
                        @endforeach
                    </div>
                    <form method="post" action="{{ route('admin.creator.decide',$case->id) }}" style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
                        @csrf
                        <div class="field" style="flex:1;min-width:220px">
                            <label>Motivo (obrigatório se rejeitar)</label>
                            <input name="reason" placeholder="Observações da decisão">
                        </div>
                        <button class="btn accent" name="decision" value="approved">Aprovar criador</button>
                        <button class="btn secondary" name="decision" value="rejected">Rejeitar</button>
                    </form>
                </div>
            @endforeach
        @else
            <div class="empty">Não existem candidaturas pendentes.</div>
        @endif
    </section>
</div>
@endsection
