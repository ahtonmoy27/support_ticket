<?php
namespace App\Http\Controllers;
use App\Models\User;
use App\Models\Category;
use App\Models\BusinessCalendar;
use App\Models\SlaPolicy;
use App\Http\Requests\SlaPolicyRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
class AdminController extends Controller {
    public function categories(Request $request): JsonResponse {
        return response()->json(['data'=>Category::query()->when(! $request->user()->isAdmin(),fn ($q)=>$q->where('is_active',true))->orderBy('name')->get()]);
    }
    public function saveCategory(Request $request, ?Category $category = null): JsonResponse {
        $data = $request->validate(['name'=>['required','string','max:255',Rule::unique('categories')->ignore($category?->id)],'is_active'=>['sometimes','boolean']]);
        $category ??= new Category;
        $category->fill($data)->save();
        return response()->json(['data'=>$category]);
    }
    public function agents(): JsonResponse { return response()->json(User::query()->where('role','agent')->with('categories')->orderBy('id')->paginate(25)); }
    public function createAgent(Request $request): JsonResponse {
        $data = $request->validate(['name'=>['required','string','max:255'],'email'=>['required','email','max:255','unique:users'],'password'=>['required','string','min:12'],'category_ids'=>['required','array'],'category_ids.*'=>['integer','distinct','exists:categories,id']]);
        $agent = DB::transaction(function () use ($data) {
            $agent = User::create(['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['password'],'role'=>'agent']);
            $agent->categories()->sync($data['category_ids']); return $agent;
        });
        return response()->json(['data'=>$agent->load('categories')],201);
    }
    public function updateAgent(Request $request, User $agent): JsonResponse {
        abort_unless($agent->isAgent(),404);
        $data = $request->validate(['name'=>['sometimes','required','string','max:255'],'is_active'=>['sometimes','boolean'],'is_available'=>['sometimes','boolean'],'category_ids'=>['sometimes','array'],'category_ids.*'=>['integer','distinct','exists:categories,id']]);
        DB::transaction(function () use ($agent,$data) {
            DB::table('support_locks')->where('name','assignment')->lockForUpdate()->first();
            $locked = User::query()->lockForUpdate()->findOrFail($agent->id);
            $locked->fill(collect($data)->except('category_ids')->all())->save();
            if (isset($data['category_ids'])) { $locked->categories()->sync($data['category_ids']); }
        },3);
        return response()->json(['data'=>$agent->refresh()->load('categories')]);
    }
    public function policies(): JsonResponse { return response()->json(SlaPolicy::query()->with(['calendar','rules'])->orderByDesc('id')->paginate(25)); }
    public function createPolicy(SlaPolicyRequest $request): JsonResponse {
        $data = $request->validated();
        $policy = DB::transaction(function () use ($data) {
            DB::table('support_locks')->where('name','sla_policy')->lockForUpdate()->first();
            $version = (SlaPolicy::max('version') ?? 0) + 1;
            $calendar = BusinessCalendar::create(['name'=>$data['name'].' v'.$version,'timezone'=>$data['timezone'],'hours'=>$data['hours'],'exceptions'=>$data['exceptions'] ?? []]);
            SlaPolicy::query()->where('is_active',true)->update(['is_active'=>false]);
            $policy = SlaPolicy::create(['name'=>$data['name'],'version'=>$version,'business_calendar_id'=>$calendar->id,'is_active'=>true]);
            foreach ($data['rules'] as $rule) { $policy->rules()->create($rule); }
            return $policy;
        },3);
        return response()->json(['data'=>$policy->load(['calendar','rules'])],201);
    }
}