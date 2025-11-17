<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\EvaluationController;

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

// Upload endpoint
Route::post('/upload', [UploadController::class, 'upload']);

// Evaluation endpoints  
Route::post('/evaluate', [EvaluationController::class, 'evaluate']);
Route::get('/result/{id}', [EvaluationController::class, 'getResult'])->where('id', '[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}');

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');