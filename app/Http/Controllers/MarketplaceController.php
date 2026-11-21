<?php

namespace App\Http\Controllers;

use App\Models\CreatorProfile;
use App\Models\CreatorVerificationDocument;
use App\Models\CreatorVerificationCase;
use App\Models\MediaDownloadEvent;
use App\Models\MediaAccessGrant;
use App\Models\DigitalMedia;
use App\Models\MediaRightsDeclaration;
use App\Models\User;
use App\Models\Order;
use App\Domain\Finance\CommissionCalculator;
use App\Services\Payments\PayPalClient;
use App\Services\Payments\CaptureFinalizer;
use App\Http\Controllers\Api\OrderCheckoutController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
    public function home()
    {
        $items = DigitalMedia::query()->where('status', 'published')->whereHas('creatorProfile', fn ($query) => $query->where('status', 'approved')->whereHas('user', fn ($user) => $user->where('account_status', 'active')))
            ->with(['creatorProfile:id,public_name,slug', 'assets' => fn ($q) => $q->where('purpose', 'preview')->latest('id')])
            ->latest('published_at')->paginate(24);
        return view('marketplace.home', compact('items'));
    }

    public function showCreator(string $slug)
    {
        $profile = CreatorProfile::query()
            ->where('slug', $slug)
            ->where('status', 'approved')
            ->whereHas('user', fn ($query) => $query->where('account_status', 'active'))
            ->firstOrFail();

        $items = $profile->media()
            ->where('status', 'published')
            ->with(['assets' => fn ($query) => $query->where('purpose', 'preview')->latest('id')])
            ->latest('published_at')
            ->get();

        $freeItems = $items->where('visibility', 'free')->values();
        $paidItems = $items->where('visibility', 'paid')->values();
        $profilePhotoUrl = $profile->profile_photo_path
            ? Storage::disk('public')->url($profile->profile_photo_path)
            : null;

        return view('marketplace.creator', compact('profile', 'freeItems', 'paidItems', 'profilePhotoUrl'));
    }

    public function updateCreatorProfile(Request $request)
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403, 'A conta de criador ainda não está aprovada.');

        $data = $request->validate([
            'public_name' => ['required', 'string', 'min:2', 'max:120'],
            'presentation' => ['nullable', 'string', 'max:180'],
            'bio' => ['nullable', 'string', 'max:280'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        if ($request->hasFile('profile_photo')) {
            $newPath = $request->file('profile_photo')->store('creator-profiles/'.$profile->id, 'public');
            if ($profile->profile_photo_path) {
                Storage::disk('public')->delete($profile->profile_photo_path);
            }
            $data['profile_photo_path'] = $newPath;
        }

        unset($data['profile_photo']);
        $profile->update($data);

        return redirect()->route('creator.show', $profile->slug)->with('success', 'Perfil público atualizado.');
    }

    public function showMedia(string $slug)
    {
        $media = DigitalMedia::where('slug', $slug)->where('status', 'published')
            ->with(['creatorProfile:id,public_name,slug', 'assets' => fn ($q) => $q->where('purpose', 'preview')->latest('id')])
            ->firstOrFail();
        return view('marketplace.media', compact('media'));
    }

    public function loginForm() { return view('auth.login'); }
    public function registerForm() { return view('auth.register'); }

    public function login(Request $request)
    {
        $credentials = $request->validate(['email' => ['required','email'], 'password' => ['required','string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => ['As credenciais não são válidas.']]);
        }
        $request->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }

    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => ['required','string','max:120'],
            'email' => ['required','email','max:255','unique:users,email'],
            'password' => ['required','string','min:10','confirmed'],
        ]);
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'account_status' => 'active',
        ]);
        Auth::login($user);
        $request->session()->regenerate();
        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('home');
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        if ($user->is_admin) {
            $pendingCases = \App\Models\CreatorVerificationCase::with(['creatorProfile.user','documents'])
                ->where('status','submitted')->oldest('submitted_at')->get();
            $stats = [
                'creators' => CreatorProfile::count(),
                'pending' => CreatorProfile::where('status','pending')->count(),
                'published' => DigitalMedia::where('status','published')->count(),
                'buyers' => User::where('is_admin',false)->count(),
            ];
            return view('marketplace.admin', compact('pendingCases','stats'));
        }
        $profile = $user->creatorProfile;
        $media = $profile ? $profile->media()->with('assets')->latest()->get() : collect();
        $library = MediaAccessGrant::where('buyer_id',$user->id)->whereNull('revoked_at')
            ->with('digitalMedia.creatorProfile')->latest('granted_at')->get();
        $creatorStats = ['sales' => 0, 'gross_minor' => 0, 'net_minor' => 0];
        if ($profile && $profile->status === 'approved') {
            $totals = OrderItem::query()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('order_items.creator_profile_id', $profile->id)
                ->where('orders.status', 'captured')
                ->selectRaw('COUNT(order_items.id) as sales, COALESCE(SUM(order_items.unit_price_minor), 0) as gross_minor, COALESCE(SUM(order_items.creator_net_minor), 0) as net_minor')
                ->first();
            $creatorStats = [
                'sales' => (int) $totals->sales,
                'gross_minor' => (int) $totals->gross_minor,
                'net_minor' => (int) $totals->net_minor,
            ];
        }
        return view('marketplace.dashboard', compact('profile','media','library','creatorStats'));
    }

    public function purchaseHistory(Request $request)
    {
        $orders = Order::query()
            ->where('buyer_id', $request->user()->id)
            ->with(['item.creatorProfile:id,public_name,slug', 'paymentTransaction'])
            ->latest()
            ->paginate(20);

        return view('marketplace.purchases', compact('orders'));
    }

    public function purchaseDetail(Request $request, string $reference)
    {
        $order = Order::query()
            ->where('buyer_id', $request->user()->id)
            ->where('order_reference', $reference)
            ->with(['item.creatorProfile:id,public_name,slug', 'paymentTransaction'])
            ->firstOrFail();

        $grant = $order->paymentTransaction
            ? MediaAccessGrant::query()
                ->where('buyer_id', $request->user()->id)
                ->where('payment_transaction_id', $order->paymentTransaction->id)
                ->whereNull('revoked_at')
                ->first()
            : null;

        return view('marketplace.purchase-detail', compact('order', 'grant'));
    }

    public function applyCreator(Request $request)
    {
        $data = $request->validate([
            'public_name' => ['required','string','min:2','max:120'],
            'bio' => ['nullable','string','max:280'],
            'country_code' => ['required','string','size:2','alpha'],
            'identity_document' => ['required','file','mimes:pdf,jpg,jpeg,png','max:10240'],
            'accept_creator_terms' => ['accepted'],
        ]);
        abort_if($request->user()->creatorProfile && $request->user()->creatorProfile->status !== 'rejected', 409, 'Já existe uma candidatura.');
        $file = $request->file('identity_document');
        $path = $file->store('creator-verification/'.$request->user()->id, 'private');

        try {
            DB::transaction(function () use ($request,$data,$file,$path) {
                $user = $request->user();
                $profile = $user->creatorProfile;
                $slugBase = Str::slug($data['public_name']);
                $slug = $slugBase.'-'.Str::lower(Str::random(6));
                if ($profile) {
                    $profile->update(['public_name'=>$data['public_name'],'slug'=>$slug,'bio'=>$data['bio'] ?? null,'country_code'=>strtoupper($data['country_code']),'status'=>'pending','approved_at'=>null,'reviewed_by'=>null]);
                } else {
                    $profile = CreatorProfile::create(['user_id'=>$user->id,'public_name'=>$data['public_name'],'slug'=>$slug,'bio'=>$data['bio'] ?? null,'country_code'=>strtoupper($data['country_code']),'status'=>'pending']);
                }
                $case = $profile->verificationCases()->create([
                    'status'=>'submitted',
                    'submitted_data'=>['public_name'=>$data['public_name'],'country_code'=>strtoupper($data['country_code']),'creator_terms_accepted'=>true],
                    'submitted_at'=>now(),
                ]);
                CreatorVerificationDocument::create([
                    'creator_verification_case_id'=>$case->id,'document_type'=>'identity','disk'=>'private',
                    'object_key'=>$path,'mime_type'=>$file->getMimeType() ?: 'application/octet-stream',
                    'size_bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath()),'status'=>'submitted',
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }
        return redirect()->route('dashboard')->with('success','Candidatura submetida. A conta ficará disponível para venda após validação.');
    }

    public function uploadMedia(Request $request)
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(),403,'A conta de criador ainda não está aprovada.');
        $data = $request->validate([
            'type'=>['required','in:photo,video'],
            'title'=>['required','string','min:2','max:180'],
            'description'=>['nullable','string','max:5000'],
            'visibility'=>['required','in:free,paid'],
            'price_eur'=>['required_if:visibility,paid','nullable','numeric','min:0.01','max:99999'],
            'original'=>['required','file',$request->input('type')==='photo'?'mimes:jpg,jpeg,png,webp':'mimes:mp4,mov,webm',$request->input('type')==='photo'?'max:30720':'max:512000'],
            'preview'=>['required','file','mimes:jpg,jpeg,png,webp','max:5120'],
            'rights_basis'=>['required','in:owner,licensed,authorized'],
            'accept_rights'=>['accepted'],
        ]);
        $original=$request->file('original'); $preview=$request->file('preview');
        $originalPath=$original->store('creator-media/'.$profile->id.'/originals','private');
        $previewPath=$preview->store('creator-media/'.$profile->id.'/previews','private');
        try {
            DB::transaction(function () use ($profile,$data,$original,$preview,$originalPath,$previewPath,$request) {
                $media=$profile->media()->create([
                    'type'=>$data['type'],'title'=>$data['title'],'slug'=>Str::slug($data['title']).'-'.Str::lower(Str::random(10)),
                    'description'=>$data['description'] ?? null,'visibility'=>$data['visibility'],
                    'price_minor'=>$data['visibility']==='paid'?(int) round(((float)$data['price_eur'])*100):null,
                    'currency'=>'EUR','status'=>'draft','published_at'=>null,
                ]);
                foreach ([['original',$original,$originalPath],['preview',$preview,$previewPath]] as [$purpose,$file,$path]) {
                    $media->assets()->create([
                        'purpose'=>$purpose,'disk'=>'private','object_key'=>$path,
                        'mime_type'=>$file->getMimeType() ?: 'application/octet-stream',
                        'size_bytes'=>$file->getSize(),'sha256'=>hash_file('sha256',$file->getRealPath()),
                    ]);
                }
                $media->rightsDeclarations()->create([
                    'creator_profile_id'=>$profile->id,'accepted_by'=>$request->user()->id,
                    'rights_basis'=>$data['rights_basis'],'terms_version'=>'v1','accepted_at'=>now(),
                ]);
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete([$originalPath,$previewPath]);
            throw $e;
        }
        return redirect()->route('dashboard')->with('success','Conteúdo guardado na tua biblioteca. Define a oferta e publica-o quando estiver pronto.');
    }

    public function updateMediaOffer(Request $request, int $media)
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403);
        $item = $profile->media()->where('status', 'draft')->findOrFail($media);
        $data = $request->validate([
            'visibility' => ['required', 'in:free,paid'],
            'price_eur' => ['required_if:visibility,paid', 'nullable', 'numeric', 'min:0.01', 'max:99999'],
        ]);

        $item->update([
            'visibility' => $data['visibility'],
            'price_minor' => $data['visibility'] === 'paid' ? (int) round(((float) $data['price_eur']) * 100) : null,
        ]);

        return redirect()->route('dashboard')->with('success', 'Oferta atualizada na tua biblioteca.');
    }

    public function publishMedia(Request $request, int $media)
    {
        $profile = $request->user()->creatorProfile;
        abort_unless($profile && $profile->canSell(), 403);
        $item = $profile->media()->where('status', 'draft')->findOrFail($media);

        $hasOriginal = $item->assets()->where('purpose', 'original')->exists();
        $hasPreview = $item->assets()->where('purpose', 'preview')->exists();
        $hasRights = $item->rightsDeclarations()->exists();
        $hasValidOffer = $item->visibility === 'free'
            || ($item->visibility === 'paid' && $item->price_minor !== null && $item->price_minor > 0);

        if (! $hasOriginal || ! $hasPreview || ! $hasRights || ! $hasValidOffer) {
            throw ValidationException::withMessages([
                'publication' => ['Para publicar, confirma a oferta e garante que existem ficheiro original, pré-visualização e declaração de direitos.'],
            ]);
        }

        $item->update(['status' => 'published', 'published_at' => now()]);

        return redirect()->route('creator.show', $profile->slug)->with('success', 'Conteúdo publicado no teu perfil.');
    }

    public function checkout(Request $request, string $slug, PayPalClient $paypal, CommissionCalculator $calculator)
    {
        $request->headers->set('Idempotency-Key', (string) Str::uuid());
        $response = app(OrderCheckoutController::class)->create($request, $slug, $paypal, $calculator);
        $payload = $response->getData(true);
        if ($response->getStatusCode() >= 400 || empty($payload['approval_url'])) {
            return back()->withErrors(['checkout' => $payload['message'] ?? 'Não foi possível iniciar o pagamento.']);
        }
        return redirect()->away($payload['approval_url']);
    }

    public function paypalReturn(Request $request, PayPalClient $paypal, CaptureFinalizer $finalizer)
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 400, 'Referência PayPal inválida.');
        $order = Order::where('paypal_order_id', $token)->where('buyer_id', $request->user()->id)->firstOrFail();
        $response = app(OrderCheckoutController::class)->capture($request, $order->order_reference, $paypal, $finalizer);
        $payload = $response->getData(true);
        if ($response->getStatusCode() === 200 && ($payload['status'] ?? null) === 'captured') {
            return redirect()->route('dashboard')->with('success', 'Pagamento confirmado. O conteúdo já está disponível na tua biblioteca.');
        }
        return redirect()->route('dashboard')->withErrors(['checkout' => $payload['message'] ?? 'O pagamento ainda não foi confirmado. A biblioteca será atualizada após confirmação.']);
    }

    public function paypalCancel(Request $request)
    {
        $token = $request->query('token');
        if (is_string($token) && $token !== '') {
            Order::where('paypal_order_id', $token)->where('buyer_id', $request->user()->id)
                ->where('status', 'awaiting_approval')->update(['status' => 'cancelled']);
        }
        return redirect()->route('home')->with('success', 'O checkout foi cancelado. Não foi efetuada qualquer compra.');
    }

    public function claimFree(Request $request, string $slug)
    {
        $media = DigitalMedia::where('slug', $slug)->where('status', 'published')->where('visibility', 'free')->firstOrFail();
        DB::transaction(function () use ($request, $media) {
            $grant = MediaAccessGrant::where('buyer_id', $request->user()->id)->where('digital_media_id', $media->id)->lockForUpdate()->first();
            if ($grant) {
                if ($grant->revoked_at) {
                    $grant->update(['grant_type'=>'free','payment_transaction_id'=>null,'granted_at'=>now(),'revoked_at'=>null,'revocation_reason'=>null]);
                }
                return;
            }
            MediaAccessGrant::create(['buyer_id'=>$request->user()->id,'digital_media_id'=>$media->id,'grant_type'=>'free','granted_at'=>now()]);
        });
        return redirect()->route('dashboard')->with('success','Conteúdo adicionado à tua biblioteca.');
    }

    public function downloadLibrary(Request $request, int $media)
    {
        $grant = MediaAccessGrant::where('buyer_id',$request->user()->id)->where('digital_media_id',$media)
            ->whereNull('revoked_at')->with('digitalMedia')->firstOrFail();
        abort_unless($grant->digitalMedia && $grant->digitalMedia->status === 'published',404);
        $asset = $grant->digitalMedia->assets()->where('purpose','original')->latest('id')->firstOrFail();
        $disk = Storage::disk($asset->disk);
        abort_unless($disk->exists($asset->object_key),503,'Ficheiro temporariamente indisponível.');
        $key = (string) config('app.key');
        MediaDownloadEvent::create([
            'media_access_grant_id'=>$grant->id,'media_asset_id'=>$asset->id,'user_id'=>$request->user()->id,
            'outcome'=>'issued','ip_hash'=>$request->ip()?hash_hmac('sha256',$request->ip(),$key):null,
            'user_agent_hash'=>$request->userAgent()?hash_hmac('sha256',$request->userAgent(),$key):null,'issued_at'=>now(),
        ]);
        $extension = match ($asset->mime_type) {
            'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',
            'video/mp4'=>'mp4','video/quicktime'=>'mov','video/webm'=>'webm',default=>'bin'
        };
        return $disk->download($asset->object_key,Str::slug($grant->digitalMedia->title).'.'.$extension,[
            'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff'
        ]);
    }

    public function verificationDocument(int $document)
    {
        $doc = CreatorVerificationDocument::findOrFail($document);
        $extension = match ($doc->mime_type) {
            'application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png',default=>'bin'
        };
        return Storage::disk($doc->disk)->download($doc->object_key,'creator-verification-'.$doc->id.'.'.$extension);
    }

    public function decideCreator(Request $request, int $case)
    {
        $data = $request->validate([
            'decision'=>['required','in:approved,rejected'],
            'reason'=>['required_if:decision,rejected','nullable','string','max:2000']
        ]);
        DB::transaction(function () use ($request,$case,$data) {
            $locked = CreatorVerificationCase::whereKey($case)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'submitted',409,'Esta candidatura já foi analisada.');
            $profile = $locked->creatorProfile()->lockForUpdate()->firstOrFail();
            $approved = $data['decision'] === 'approved';
            if ($approved && !$locked->documents()->where('document_type','identity')->where('status','submitted')->exists()) {
                throw ValidationException::withMessages(['documents'=>['É obrigatório um documento de identificação submetido.']]);
            }
            $locked->update([
                'status'=>$approved?'approved':'rejected','decision_reason'=>$data['reason']??null,
                'reviewed_by'=>$request->user()->id,'reviewed_at'=>now()
            ]);
            $profile->update([
                'status'=>$approved?'approved':'rejected','approved_at'=>$approved?now():null,
                'reviewed_by'=>$request->user()->id
            ]);
        });
        return redirect()->route('dashboard')->with('success','Decisão de validação registada.');
    }

}
