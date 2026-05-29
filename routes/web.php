<?php

use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Search\ProductSearchController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health/live', [HealthCheckController::class, 'live']);
Route::get('/health/ready', [HealthCheckController::class, 'ready']);

Route::get('/api/catalog/products', [ProductController::class, 'index']);
Route::get('/api/catalog/products/{slug}', [ProductController::class, 'show']);
Route::get('/api/catalog/categories/tree', [CategoryController::class, 'tree']);
Route::get('/api/search/products', ProductSearchController::class);
