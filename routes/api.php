<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BackOffice\ProductController;
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
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::prefix('back-office')->group(function () {
        Route::prefix('products')->group(function () {
            // Route to get the list of products with filters, sorting, and pagination
            Route::get('/', [ProductController::class, 'index']);
            // Route to store a new product
            Route::post('/', [ProductController::class, 'store']);
            // Route to show a specific product by ID
            Route::get('{product}', [ProductController::class, 'show']);
            // Route to update an existing product
            Route::post('{product}', [ProductController::class, 'update']);
            // Route to delete a product
            Route::delete('{product}', [ProductController::class, 'destroy']);
        });
    });
});
