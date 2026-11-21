<?php

namespace App\\Http\\Controllers;

use App\\Models\\CreatorProfile;
use App\\Models\\CreatorVerificationDocument;
use App\\Models\\DigitalMedia;
use App\\Models\\MediaAccessGrant;
use App\\Models\\MediaRightsDeclaration;
use App\\Models\\User;
use Illuminate\\Http\\Request;
use Illuminate\\Support\\Facades\\Auth;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Facades\\Storage;
use Illuminate\\Support\\Str;
use Illuminate\\Validation\\ValidationException;

class MarketplaceController extends Controller
{
    public function home()
    {
        $items = DigitalMedia::query()->where('status', 'published')
            ->with(['creatorProfile:id,public_name,slug', 'assets' => fn ($q) => $q->where('purpose', 'preview')->latest('id')])
            ->latest('published_at')->paginate(24);
        return view('marketplace.home', compact('items'));
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
            $pendingCases = \\App\\Models\\CreatorVerificationCase::with(['creatorProfile.user','documents'])
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
        return view('marketplace.dashboard', compact('profile','media','library'));
    }

    public function applyCreator(Request $request)
    {
        $data = $request->validate([
            'public_name' => ['required','string','min:2','max:120'],
            'bio' => ['nullable','string','max:2000'],
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
        } catch (\\Throwable $e) {
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
                    'currency'=>'EUR','status'=>'published','published_at'=>now(),
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
        } catch (\\Throwable $e) {
            Storage::disk('private')->delete([$originalPath,$previewPath]);
            throw $e;
        }
        return redirect()->route('dashboard')->with('success','Conteúdo publicado no catálogo.');
    }
}
