@extends('layouts.app')
@section('title','Área USER — Marketplace')
@section('content')
<div class="wrap page">
    <div class="eyebrow">Área USER · Comprador</div>
    <h1 class="page-title">Olá, {{ auth()->user()->name }}.</h1>
    <p class="muted">Explora criadores, guarda conteúdos gratuitos e consulta as tuas aquisições.</p>
    <p><a class="btn accent" href="{{ route('home') }}">Explorar conteúdos</a> <a class="btn secondary" href="{{ route('purchases.index') }}">Histórico de compras</a></p>
    <section>
        <div class="section-head"><div><div class="eyebrow">Biblioteca pessoal</div><h2>Os meus conteúdos</h2></div></div>
        @if($library->count())
            <div class="grid">
                @foreach($library as $grant)
                    <div class="product">
                        <div class="placeholder">{{ strtoupper($grant->digitalMedia->type) }}</div>
                        <div class="product-info">
                            <h3>{{ $grant->digitalMedia->title }}</h3>
                            <div class="muted">{{ $grant->digitalMedia->creatorProfile->public_name }}</div>
                            <a class="btn secondary" style="margin-top:14px" href="{{ route('library.download',$grant->digital_media_id) }}">Transferir ficheiro</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="empty">Ainda não tens conteúdos na tua biblioteca. Explora o catálogo para descobrir conteúdos gratuitos e pagos.</div>
        @endif
    </section>
</div>
@endsection
