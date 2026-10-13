<?php
namespace App\Http\Controllers;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
class AuthController extends Controller {
    public function register(Request $request): JsonResponse {
        $data = $request->validate(['name'=>['required','string','max:255'],'email'=>['required','email','max:255','unique:users'],'password'=>['required','confirmed','min:12'],'role'=>['prohibited']]);
        $user = User::create($data + ['role'=>'customer']);
        Auth::login($user); $request->session()->regenerate();
        return response()->json(['data'=>$user],201);
    }
    public function login(Request $request): JsonResponse {
        $data = $request->validate(['email'=>['required','email'],'password'=>['required','string']]);
        if (! Auth::attempt($data + ['is_active'=>true])) { throw ValidationException::withMessages(['email'=>'Invalid credentials.']); }
        $request->session()->regenerate();
        return response()->json(['data'=>$request->user()]);
    }
    public function logout(Request $request): JsonResponse {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->json(['message'=>'Logged out.']);
    }
    public function me(Request $request): JsonResponse { return response()->json(['data'=>$request->user()]); }
}