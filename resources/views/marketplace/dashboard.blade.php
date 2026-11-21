@extends('layouts.app')
@section('title','A minha área — Marketplace')
@section('content')
<div class="wrap page">
    <div class="eyebrow">Área pessoal</div>
    <h1 class="page-title">Olá, {{ auth()->user()->name }}.</h1>
    <p class="muted">O teu espaço de gestão de criador.</p>

    <section class="panel">
        <div class="row">
            <div>
                <div class="eyebrow">Perfil de criador</div>
                <h2 style="font:27px Georgia,serif;margin:8px 0">{{ $profile?->public_name ?? 'Ainda não tens perfil de criador' }}</h2>
                <span class="pill">{{ $profile?->status ?? 'não iniciado' }}</span>
            </div>
            @if(!$profile || $profile->status === 'rejected')
                <a href="#candidatura" class="btn accent">Candidatar-me como criador</a>
            @endif
        </div>

        @if($profile && $profile->status !== 'approved')
            <div class="notice">A candidatura está {{ $profile->status === 'pending' ? 'pendente de validação' : 'em preparação' }}. A publicação de conteúdos fica disponível após aprovação administrativa.</div>
        @endif

        @if($profile && $profile->status === 'approved')
            <div class="stats" style="margin:24px 0">
                <div class="stat"><strong>{{ $creatorStats['sales'] }}</strong><span>Vendas confirmadas</span></div>
                <div class="stat"><strong>{{ number_format($creatorStats['gross_minor']/100,2,',','.') }} €</strong><span>Volume de vendas</span></div>
                <div class="stat"><strong>{{ number_format($creatorStats['net_minor']/100,2,',','.') }} €</strong><span>Valor líquido atribuído</span></div>
                <div class="stat"><strong>{{ number_format(config('marketplace.commission_bps', 1000) / 100, 1, ',', '.') }}%</strong><span>Comissão da plataforma</span></div>
            </div>
            <p class="muted">Os valores apresentados correspondem a vendas confirmadas. Não representam necessariamente saldo já disponível para levantamento; os fundos podem estar sujeitos ao período de retenção.</p>
            <div class="panel" style="margin:18px 0">
                <div class="eyebrow">Conta de criador</div>
                <h3 style="font:22px Georgia,serif;margin:8px 0 16px">Resumo financeiro</h3>
                <div class="stats">
                    <div class="stat"><strong>{{ number_format($creatorFinance['pending_minor']/100,2,',','.') }} €</strong><span>Saldo pendente, excluindo retenções</span></div>
                    <div class="stat"><strong>{{ number_format($creatorFinance['held_minor']/100,2,',','.') }} €</strong><span>Em período de retenção</span></div>
                    <div class="stat"><strong>{{ number_format($creatorFinance['available_minor']/100,2,',','.') }} €</strong><span>Saldo disponível</span></div>
                    <div class="stat"><strong>{{ number_format($creatorFinance['payout_reserved_minor']/100,2,',','.') }} €</strong><span>Pagamento em processamento</span></div>
                </div>
                <p class="muted" style="margin-top:14px">O saldo disponível pode ser solicitado quando atinge o mínimo configurado e existe um endereço PayPal válido. Os montantes pedidos ficam reservados até confirmação do PayPal.</p>
            </div>
            <p style="margin-top:14px"><a class="btn secondary" href="{{ route('creator.show',$profile->slug) }}">Ver perfil público</a></p>
            <div class="section-head" style="margin-top:34px">
                <div><div class="eyebrow">Atividade comercial</div><h2>Vendas recentes</h2></div>
                <span class="muted">Últimas 20 vendas confirmadas</span>
            </div>
            @if($creatorSales->count())
                <div class="panel" style="padding:0;overflow:auto">
                    <table class="table">
                        <thead><tr><th>Pedido</th><th>Conteúdo</th><th>Data</th><th>Valor</th><th>Valor líquido</th></tr></thead>
                        <tbody>
                        @foreach($creatorSales as $sale)
                            <tr>
                                <td>{{ $sale->order?->order_reference ?? '—' }}</td>
                                <td>{{ $sale->title_snapshot }}</td>
                                <td>{{ $sale->created_at?->format('d/m/Y H:i') }}</td>
                                <td>{{ number_format($sale->unit_price_minor/100,2,',','.') }} {{ $sale->currency }}</td>
                                <td><strong>{{ number_format($sale->creator_net_minor/100,2,',','.') }} {{ $sale->currency }}</strong></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="empty">Ainda não existem vendas confirmadas.</div>
            @endif
            <div class="panel" style="margin:20px 0">
                <div class="eyebrow">Liquidação</div>
                <h3 style="font:22px Georgia,serif;margin:8px 0">Pagamentos ao criador</h3>
                <p class="muted">O pedido utiliza o saldo disponível integral, desde que atinja o mínimo configurado. O valor fica reservado até o PayPal confirmar o pagamento; se falhar, regressa ao saldo disponível.</p>
                <p><strong>Conta PayPal:</strong> {{ $profile->payout_email ?: 'Ainda não definida' }}</p>
                @if($creatorFinance['available_minor'] >= (int) config('marketplace.payout_minimum_minor',5000) && $profile->payout_email)
                    <form method="post" action="{{ route('creator.payouts.store') }}" onsubmit="return confirm('Pretendes solicitar o pagamento de todo o saldo disponível?')">
                        @csrf
                        <button class="btn accent">Solicitar pagamento de {{ number_format($creatorFinance['available_minor']/100,2,',','.') }} €</button>
                    </form>
                @else
                    <p class="muted">Para solicitar um pagamento, define primeiro o email PayPal e aguarda que o saldo disponível atinja {{ number_format(config('marketplace.payout_minimum_minor',5000)/100,2,',','.') }} €.</p>
                @endif
                @if($creatorPayouts->count())
                    <h4>Pedidos recentes</h4>
                    <div style="overflow:auto"><table class="table">
                        <thead><tr><th>Data</th><th>Montante</th><th>Destino</th><th>Estado</th></tr></thead>
                        <tbody>
                        @foreach($creatorPayouts as $payout)
                            <tr><td>{{ $payout->requested_at?->format('d/m/Y H:i') }}</td><td>{{ number_format($payout->amount_minor/100,2,',','.') }} {{ $payout->currency }}</td><td>{{ $payout->recipient_email }}</td><td>{{ match($payout->status) {'requested'=>'Em validação','processing'=>'Em processamento','submission_unknown'=>'A confirmar com PayPal','paid'=>'Pago','returned'=>'Devolvido','failed'=>'Falhou',default=>$payout->status} }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </div>
            <h3 style="font:22px Georgia,serif;margin-top:28px">Identidade pública</h3>
            <form method="post" action="{{ route('creator.profile.update') }}" enctype="multipart/form-data">
                @csrf
                @if($profile->profile_photo_path)<p><img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($profile->profile_photo_path) }}" alt="Fotografia de perfil atual" style="width:76px;height:76px;border-radius:50%;object-fit:cover"></p>@endif
                <div class="field"><label>Fotografia de perfil</label><input type="file" name="profile_photo" accept=".jpg,.jpeg,.png,.webp"></div>
                <div class="field"><label>Nome público</label><input name="public_name" maxlength="120" required value="{{ old('public_name',$profile->public_name) }}"></div>
                <div class="field"><label>Apresentação curta</label><input name="presentation" maxlength="180" value="{{ old('presentation',$profile->presentation) }}" placeholder="Uma frase de apresentação"></div>
                <div class="field"><label>Biografia curta</label><textarea name="bio" maxlength="280">{{ old('bio',$profile->bio) }}</textarea></div>
                <div class="field"><label>Email da conta PayPal para receber pagamentos</label><input type="email" name="payout_email" maxlength="255" value="{{ old('payout_email',$profile->payout_email) }}" placeholder="email@exemplo.com"></div>
                <p class="muted">Confirma que este endereço corresponde à conta PayPal onde pretendes receber os pagamentos.</p>
                <button class="btn secondary">Guardar identidade pública</button>
            </form>
            <h3 style="font:22px Georgia,serif;margin-top:28px">A minha biblioteca de conteúdos</h3>
            <div class="row"><span class="muted">{{ $media->count() }} conteúdos</span><a href="#novo-conteudo" class="btn">Adicionar à biblioteca</a></div>
            @if($media->count())
                <table class="table">
                    <thead><tr><th>Título</th><th>Formato</th><th>Estado</th><th>Visibilidade</th></tr></thead>
                    <tbody>
                    @foreach($media as $item)
                        <tr>
                            <td>{{ $item->title }}</td><td>{{ $item->type }}</td><td>{{ $item->status === 'draft' ? 'Na biblioteca' : 'Publicado' }}</td>
                            <td>
                                @if($item->status === 'draft')
                                    <form method="post" action="{{ route('creator.media.offer',$item->id) }}" class="library-offer">@csrf
                                        <select name="visibility"><option value="free" @selected($item->visibility==='free')>Gratuito</option><option value="paid" @selected($item->visibility==='paid')>Pago</option></select>
                                        <input type="number" name="price_eur" min="0.01" step="0.01" placeholder="Preço €" value="{{ $item->price_minor ? number_format($item->price_minor/100,2,'.','') : '' }}">
                                        <button class="btn secondary">Guardar oferta</button>
                                    </form>
                                    <form method="post" action="{{ route('creator.media.publish',$item->id) }}" style="margin-top:7px">@csrf<button class="btn accent">Publicar no perfil</button></form>
                                @else
                                    <span>{{ $item->visibility==='free'?'Gratuito':'Pago' }}</span>
                                    <form method="post" action="{{ route('creator.media.unpublish',$item->id) }}" style="margin-top:7px">@csrf<button class="btn secondary">Retirar do perfil</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @else
                <div class="empty">Ainda não publicaste conteúdos.</div>
            @endif
        @endif
    </section>

    @if(!$profile || $profile->status === 'rejected')
        <section class="panel" id="candidatura">
            <div class="eyebrow">Tornar-me criador</div>
            <h2 style="font:27px Georgia,serif">Candidatura de criador</h2>
            <p class="muted">Preenche os dados e envia um documento de identificação. A equipa de administração validará a conta antes de permitir publicações.</p>
            <form method="post" action="{{ route('creator.apply') }}" enctype="multipart/form-data">
                @csrf
                <div class="form-grid">
                    <div class="field"><label>Nome público</label><input name="public_name" required maxlength="120"></div>
                    <div class="field"><label>País (código ISO de 2 letras)</label><input name="country_code" value="PT" maxlength="2" required></div>
                </div>
                <div class="field"><label>Biografia</label><textarea name="bio" maxlength="280"></textarea></div>
                <div class="field"><label>Documento de identificação (PDF, JPG ou PNG)</label><input type="file" name="identity_document" accept=".pdf,.jpg,.jpeg,.png" required></div>
                <label class="muted"><input type="checkbox" name="accept_creator_terms" value="1" required> Confirmo que os dados submetidos são verdadeiros e aceito os termos de candidatura.</label>
                <p><button class="btn accent">Enviar candidatura</button></p>
            </form>
        </section>
    @endif

    @if($profile && $profile->status === 'approved')
        <section class="panel" id="novo-conteudo">
            <div class="eyebrow">Publicação</div>
            <h2 style="font:27px Georgia,serif">Adicionar novo conteúdo</h2>
            <form method="post" action="{{ route('creator.media.store') }}" enctype="multipart/form-data">
                @csrf
                <div class="form-grid">
                    <div class="field"><label>Formato</label><select name="type"><option value="photo">Fotografia</option><option value="video">Vídeo</option></select></div>
                    <div class="field"><label>Título</label><input name="title" required maxlength="180"></div>
                </div>
                <div class="field"><label>Descrição</label><textarea name="description"></textarea></div>
                <div class="form-grid">
                    <div class="field"><label>Visibilidade</label><select name="visibility"><option value="free">Gratuito</option><option value="paid">Pago</option></select></div>
                    <div class="field"><label>Preço em euros (apenas para pago)</label><input type="number" name="price_eur" min="0.01" step="0.01"></div>
                </div>
                <div class="field"><label>Ficheiro original</label><input type="file" name="original" required></div>
                <div class="field"><label>Imagem de pré-visualização</label><input type="file" name="preview" accept=".jpg,.jpeg,.png,.webp" required></div>
                <div class="field"><label>Declaração de direitos</label><select name="rights_basis" required><option value="owner">Sou titular dos direitos</option><option value="licensed">Tenho licença para distribuir</option><option value="authorized">Tenho autorização do titular</option></select></div>
                <label class="muted"><input type="checkbox" name="accept_rights" value="1" required> Confirmo que tenho os direitos ou autorização necessários para publicar e comercializar este conteúdo.</label>
                <p><button class="btn accent">Adicionar à biblioteca privada</button></p>
            </form>
        </section>
    @endif

    {{-- A biblioteca de compras pertence exclusivamente à área USER. --}}
</div>
@endsection
