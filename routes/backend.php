<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Support\Facades\Route;
Route::prefix('api/v1')->name('backend.')->group(function () {
    Route::get('csrf-token',fn () => response()->json(['csrf_token'=>csrf_token()]))->name('csrf');
    Route::middleware(['guest','throttle:login'])->group(function () {
        Route::post('register',[AuthController::class,'register'])->name('register');
        Route::post('login',[AuthController::class,'login'])->name('login');
    });
    Route::middleware(['auth',EnsureActiveUser::class,'throttle:support'])->group(function () {
        Route::get('me',[AuthController::class,'me'])->name('me');
        Route::post('logout',[AuthController::class,'logout'])->name('logout');
        Route::get('categories',[AdminController::class,'categories'])->name('categories');
        Route::get('dashboard',DashboardController::class)->name('dashboard');
        Route::get('tickets',[TicketController::class,'index'])->name('tickets.index');
        Route::post('tickets',[TicketController::class,'store'])->name('tickets.store');
        Route::get('tickets/{ticket}',[TicketController::class,'show'])->name('tickets.show');
        Route::patch('tickets/{ticket}',[TicketController::class,'update'])->name('tickets.update');
        foreach (['claim','assign','resolve','close','status'] as $action) {
            Route::post('tickets/{ticket}/'.$action,[TicketController::class,$action])->name('tickets.'.$action);
        }
        Route::get('tickets/{ticket}/activities',[TicketController::class,'activities'])->name('tickets.activities');
        Route::get('tickets/{ticket}/messages',[ConversationController::class,'index'])->name('messages.index');
        Route::post('tickets/{ticket}/messages',[ConversationController::class,'store'])->name('messages.store');
        Route::get('tickets/{ticket}/attachments',[AttachmentController::class,'index'])->name('attachments.index');
        Route::post('tickets/{ticket}/attachments',[AttachmentController::class,'store'])->name('attachments.store');
        Route::get('attachments/{attachment}/download',[AttachmentController::class,'download'])->name('attachments.download');
        Route::get('notifications',fn (\Illuminate\Http\Request $request) => response()->json($request->user()->notifications()->latest()->paginate(25)))->name('notifications.index');
        Route::patch('notifications/{id}/read',function (\Illuminate\Http\Request $request,string $id) {
            $request->user()->notifications()->findOrFail($id)->markAsRead();
            return response()->json(['message'=>'Marked as read.']);
        })->name('notifications.read');
        Route::prefix('admin')->middleware('can:administer-support')->name('admin.')->group(function () {
            Route::post('categories',[AdminController::class,'saveCategory'])->name('categories.store');
            Route::patch('categories/{category}',[AdminController::class,'saveCategory'])->name('categories.update');
            Route::get('agents',[AdminController::class,'agents'])->name('agents.index');
            Route::post('agents',[AdminController::class,'createAgent'])->name('agents.store');
            Route::patch('agents/{agent}',[AdminController::class,'updateAgent'])->name('agents.update');
            Route::get('sla-policies',[AdminController::class,'policies'])->name('sla.index');
            Route::post('sla-policies',[AdminController::class,'createPolicy'])->name('sla.store');
            Route::get('imports',[ImportController::class,'index'])->name('imports.index');
            Route::post('imports',[ImportController::class,'store'])->middleware('throttle:imports')->name('imports.store');
            Route::get('imports/{import}',[ImportController::class,'show'])->name('imports.show');
            Route::get('imports/{import}/errors',[ImportController::class,'errors'])->name('imports.errors');
        });
    });
});