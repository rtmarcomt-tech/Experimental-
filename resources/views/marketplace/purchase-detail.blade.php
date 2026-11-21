@extends('layouts.app')
@section('title','Detalhe da compra — Marketplace')
@section('content')
<div class="wrap page">
    <a class="muted" href="{{ route('purchases.index') }}">← Histórico de compras</a>
    <div class="eyebrow" style="margin-top:24px">Detalhe do pedido</div>
    <h1 class="page-title">{{ $order->order_reference }}</h1>
    <section class="panel">
        <div class="row"><span class="muted">Estado do pedido</span><span class="pill">{{ match($order->status) {'captured'=>'Pagamento confirmado','refunded'=>'Reembolsado','cancelled'=>'Cancelado','awaiting_approval'=>'A aguardar pagamento',default=>'Em processamento'} }}</span></div>
        <div class="row" style="margin-top:18px"><span class="muted">Data</span><strong>{{ $order->created_at?->format('d/m/Y H:i') }}</strong></div>
        <div class="row" style="margin-top:18px"><span class="muted">Pagamento</span><strong>{{ strtoupper($order->payment_provider) }} · {{ $order->paymentTransaction?->status ?? 'Pendente' }}</strong></div>
        <div class="row" style="margin-top:18px"><span class="muted">Total</span><strong>{{ number_format($order->gross_minor / 100, 2, ',', '.') }} {{ $order->currency }}</strong></div>
    </section>
    <section class="panel">
        <div class="eyebrow">Conteúdo adquirido</div>
        <h2 style="font:28px Georgia,serif;margin:10px 0">{{ $order->item?->title_snapshot ?? 'Conteúdo digital' }}</h2>
        <p class="muted">Criador: {{ $order->item?->creatorProfile?->public_name ?? '—' }}</p>
        @if($order->item?->creatorProfile)
            <p><a href="{{ route('creator.show',$order->item->creatorProfile->slug) }}">Ver perfil do criador</a></p>
        @endif
        @if($grant && $order->status === 'captured' && $order->item?->digital_media_id)
            <a class="btn accent" href="{{ route('library.download',$order->item->digital_media_id) }}">Transferir conteúdo</a>
        @elseif($order->status === 'refunded')
            <div class="notice">Este pedido foi reembolsado. O acesso ao conteúdo foi revogado.</div>
        @elseif($order->status !== 'captured')
            <div class="notice">O pagamento ainda não está confirmado. O acesso será disponibilizado após confirmação.</div>
        @else
            <div class="notice">O pedido está pago, mas o acesso não está atualmente disponível. Contacta o suporte indicando a referência do pedido.</div>
        @endif
    </section>
</div>
@endsection
