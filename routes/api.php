<?php

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