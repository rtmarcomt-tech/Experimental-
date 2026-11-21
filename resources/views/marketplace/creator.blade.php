@extends('layouts.app')
@section('title', $profile->public_name.' — Marketplace')
@section('content')
<div class="wrap page creator-page">
    <section class="creator-hero">
        <div class="creator-avatar">@if($profilePhotoUrl)<img src="{{ $profilePhotoUrl }}" alt="{{ $profile->public_name }}">@else<span>{{ mb_substr($profile->public_name, 0, 1) }}</span>@endif</div>
        <div class="creator-intro">
            <div class="eyebrow">Perfil de criador</div>
            <h1 class="page-title">{{ $profile->public_name }}</h1>
            @if($profile->presentation)<p class="creator-presentation">{{ $profile->presentation }}</p>@endif
            @if($profile->bio)<p class="muted creator-bio">{{ $profile->bio }}</p>@endif
        </div>
    </section>

    <section class="creator-section">
        <div class="section-head"><div><div class="eyebrow">Acesso gratuito</div><h2>Conteúdos gratuitos</h2></div><span class="muted">{{ $freeItems->count() }} conteúdos</span></div>
        @if($freeItems->isNotEmpty())
            <div class="grid">@foreach($freeItems as $item)<a class="product" href="{{ route('media.show', $item->slug) }}">@php($preview=$item->assets->first()) @if($preview)<img src="{{ route('media.preview',$item->slug) }}" alt="{{ $item->title }}">@else<div class="placeholder">{{ strtoupper($item->type) }}</div>@endif<div class="product-info"><div class="row"><span class="pill">{{ $item->type==='photo'?'Fotografia':'Vídeo' }}</span><span class="muted">Gratuito</span></div><h3>{{ $item->title }}</h3><div class="price">Ver conteúdo</div></div></a>@endforeach</div>
        @else<div class="empty">Ainda não existem conteúdos gratuitos neste perfil.</div>@endif
    </section>

    <section class="creator-section">
        <div class="section-head"><div><div class="eyebrow">Conteúdos individuais</div><h2>Conteúdos para comprar</h2></div><span class="muted">{{ $paidItems->count() }} conteúdos</span></div>
        @if($paidItems->isNotEmpty())
            <div class="grid">@foreach($paidItems as $item)<a class="product" href="{{ route('media.show', $item->slug) }}">@php($preview=$item->assets->first()) @if($preview)<img src="{{ route('media.preview',$item->slug) }}" alt="{{ $item->title }}">@else<div class="placeholder">{{ strtoupper($item->type) }}</div>@endif<div class="product-info"><div class="row"><span class="pill">{{ $item->type==='photo'?'Fotografia':'Vídeo' }}</span><span class="muted">{{ number_format($item->price_minor/100,2,',',' ') }} €</span></div><h3>{{ $item->title }}</h3><div class="price">Ver conteúdo</div></div></a>@endforeach</div>
        @else<div class="empty">Ainda não existem conteúdos pagos neste perfil.</div>@endif
    </section>
</div>
@endsection
