<?php

use App\Http\Controllers\Api\Auth\SessionController;
use App\Http\Controllers\Api\Catalog\CategoryController;
use App\Http\Controllers\Api\Catalog\ProductController;
use App\Http\Controllers\Api\Customers\CustomerStateController;
use App\Http\Controllers\Api\Homepage\HomepageController;
use App\Http\Controllers\Api\Inventory\ReservationController;
use App\Http\Controllers\Api\Inventory\StockController;
use App\Http\Controllers\Api\Orders\CartItemController;
use App\Http\Controllers\Api\Orders\CheckoutController;
use App\Http\Controllers\Api\Orders\DraftOrderController;
use App\Http\Controllers\Api\Orders\OrderConfirmationController;
use App\Http\Controllers\Api\Orders\OrderLifecycleController;
use App\Http\Controllers\Api\Pricing\PriceController;
use App\Http\Controllers\Api\Search\ProductSearchController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\MetricsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->away(config('stockflow.frontend_url'));
});

Route::get('/health/live', [HealthCheckController::class, 'live']);
Route::get('/health/ready', [HealthCheckController::class, 'ready']);
Route::get('/metrics', MetricsController::class);

Route::get('/api/catalog/products', [ProductController::class, 'index'])
    ->middleware('throttle:stockflow-catalog');
Route::get('/catalog/{path}', [ProductController::class, 'path'])
    ->where('path', '.*')
    ->middleware('throttle:stockflow-catalog');
Route::get('/api/catalog/products/{slug}', [ProductController::class, 'show']);
Route::get('/api/catalog/categories/tree', [CategoryController::class, 'tree']);
Route::get('/api/homepage', HomepageController::class);
Route::get('/api/session/csrf', [SessionController::class, 'csrf']);
Route::get('/api/session', [SessionController::class, 'show']);
Route::post('/api/session/register', [SessionController::class, 'register']);
Route::post('/api/session/login', [SessionController::class, 'login']);
Route::delete('/api/session', [SessionController::class, 'destroy']);
Route::middleware('auth')->group(function (): void {
    Route::get('/api/customer-state', [CustomerStateController::class, 'show']);
    Route::post('/api/customer-state/merge', [CustomerStateController::class, 'merge']);
    Route::put('/api/customer-state/cart/items/{product}', [CustomerStateController::class, 'setCartItem']);
    Route::delete('/api/customer-state/cart/items/{product}', [CustomerStateController::class, 'removeCartItem']);
    Route::put('/api/customer-state/cart/items/{product}/restore', [CustomerStateController::class, 'restoreCartItem']);
    Route::put('/api/customer-state/cart/items/{product}/selection', [CustomerStateController::class, 'selectCartItem']);
    Route::put('/api/customer-state/cart/selection', [CustomerStateController::class, 'selectAllCartItems']);
    Route::put('/api/customer-state/favorites/{product}', [CustomerStateController::class, 'addFavorite']);
    Route::delete('/api/customer-state/favorites/{product}', [CustomerStateController::class, 'removeFavorite']);
});
Route::get('/api/inventory/stock', [StockController::class, 'show']);
Route::get('/api/inventory/stock-movements', [StockController::class, 'movements']);
Route::post('/api/inventory/reservations', [ReservationController::class, 'store']);
Route::post('/api/inventory/reservations/cancel', [ReservationController::class, 'cancel']);
Route::get('/api/pricing/prices', [PriceController::class, 'index']);
Route::get('/api/search/products', ProductSearchController::class)
    ->middleware('throttle:stockflow-search');
Route::post('/api/cart/items', [CartItemController::class, 'store'])
    ->middleware('throttle:stockflow-checkout');
Route::post('/api/orders/draft', [DraftOrderController::class, 'store'])
    ->middleware('throttle:stockflow-checkout');
Route::get('/api/checkout/options', [CheckoutController::class, 'options']);
Route::get('/api/orders/{id}/checkout', [CheckoutController::class, 'show']);
Route::put('/api/orders/{id}/checkout', [CheckoutController::class, 'update'])
    ->middleware('throttle:stockflow-checkout');
Route::post('/api/orders/{id}/confirm', [OrderConfirmationController::class, 'store'])
    ->middleware('throttle:stockflow-checkout');
Route::post('/api/orders/{id}/paid', [OrderLifecycleController::class, 'paid']);
Route::post('/api/orders/{id}/cancelled', [OrderLifecycleController::class, 'cancelled']);
Route::post('/api/orders/{id}/expired', [OrderLifecycleController::class, 'expired']);
