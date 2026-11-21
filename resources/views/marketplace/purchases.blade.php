@extends('layouts.app')
@section('title','Histórico de compras — Marketplace')
@section('content')
<div class="wrap page">
    <div class="eyebrow">Área do comprador</div>
    <h1 class="page-title">Histórico de compras</h1>
    <p class="muted">Consulta os teus pedidos, pagamentos e conteúdos adquiridos.</p>
    @if($orders->count())
        <div class="panel" style="padding:0;overflow:auto">
            <table class="table">
                <thead><tr><th>Pedido</th><th>Conteúdo</th><th>Data</th><th>Valor</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                @foreach($orders as $order)
                    <tr>
                        <td><strong>{{ $order->order_reference }}</strong></td>
                        <td>{{ $order->item?->title_snapshot ?? 'Conteúdo digital' }}<div class="muted">{{ $order->item?->creatorProfile?->public_name ?? 'Criador' }}</div></td>
                        <td>{{ $order->created_at?->format('d/m/Y H:i') }}</td>
                        <td>{{ number_format($order->gross_minor / 100, 2, ',', '.') }} {{ $order->currency }}</td>
                        <td><span class="pill">{{ match($order->status) {'captured'=>'Pago','refunded'=>'Reembolsado','cancelled'=>'Cancelado','awaiting_approval'=>'A aguardar pagamento',default=>'Em processamento'} }}</span></td>
                        <td><a class="btn secondary" href="{{ route('purchases.show',$order->order_reference) }}">Ver pedido</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @else
        <div class="empty">Ainda não tens compras. Quando adquirires um conteúdo, o pedido ficará disponível aqui.</div>
    @endif
    <p><a class="btn secondary" href="{{ route('home') }}">Explorar conteúdos</a></p>
</div>
@endsection
