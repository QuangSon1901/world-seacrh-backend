<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SematicController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/keyphrase', [SematicController::class, 'index']);
Route::get('/check-query', [SematicController::class, 'check_query']);

Route::get('/t-node', [SematicController::class, 't_node']);
Route::get('/keyword', [SematicController::class, 'search_keyword']);
Route::get('/syntax', [SematicController::class, 'search_syntax']);

Route::get('/parent-node', [SematicController::class, 'get_parent_node']);
Route::post('/add-node', [SematicController::class, 'add_node']);

// Route::get('/test', [SematicController::class, 'test']);

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);


Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user-info', [AuthController::class, 'getUser']);

    Route::get('/get-history-search', [SearchController::class, 'getHistorySearch']);
    Route::delete('/delete-all-history', [SearchController::class, 'deleteAllHistory']);
});
