<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\Order_itemController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

// ========== Public routes (no token needed) ==========

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// OTP
Route::post('/otp/send', [OtpController::class, 'send']);
Route::post('/otp/verify', [OtpController::class, 'verify']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Google OAuth
Route::get('/auth/google', [GoogleAuthController::class, 'redirect']);
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// Browse products & categories
Route::get('/products', [ProductController::class, 'index']);
// Must stay above /products/{id}, otherwise "trending" is matched as an id.
Route::get('/products/trending', [ProductController::class, 'trending']);
Route::get('/products/{id}/related', [ProductController::class, 'related']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{id}', [CategoryController::class, 'show']);
Route::get('/categories/{id}/products', [CategoryController::class, 'products']);

// ========== Protected routes (Bearer token required) ==========

Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('/user', [AuthController::class, 'user']);
    // POST is the one that can carry the multipart avatar upload: PHP only
    // parses multipart/form-data bodies for POST, so PATCH/PUT arrive with an
    // empty $_POST and an empty $_FILES. PATCH/PUT remain for JSON-only edits.
    Route::post('/user/profile', [AuthController::class, 'updateProfile']);
    Route::put('/user/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/user/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Admin: product & category management
    Route::middleware('role:admin')->group(function () {
        Route::post('/products', [ProductController::class, 'store']);
        // POST is the one that can carry the multipart image upload: PHP only
        // parses multipart/form-data bodies for POST, so a raw PUT/PATCH arrives
        // with an empty $_POST and $_FILES and the new image is silently ignored.
        // Send `_method=PUT` with the file to keep using the PUT/PATCH verbs.
        Route::post('/products/{id}', [ProductController::class, 'update']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::patch('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        Route::post('/categories', [CategoryController::class, 'store']);
        // POST is the one that can carry the multipart image upload: PHP only
        // parses multipart/form-data bodies for POST, so a raw PUT/PATCH arrives
        // with an empty $_FILES and the new image is silently ignored.
        Route::post('/categories/{id}', [CategoryController::class, 'update']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::patch('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
    });

    // Admin: dashboard statistics
    Route::get('/admin/dashboard', [DashboardController::class, 'index']);

    // Admin: user management
    Route::get('/users', [UsersController::class, 'index']);
    Route::post('/users', [UsersController::class, 'store']);
    Route::get('/users/{id}', [UsersController::class, 'show']);
    Route::put('/users/{id}', [UsersController::class, 'update']);
    Route::patch('/users/{id}', [UsersController::class, 'update']);
    Route::delete('/users/{id}', [UsersController::class, 'destroy']);

    // Order management
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::patch('/orders/{id}/cancel', [OrderController::class, 'cancel']);

    // Changing an order's status is an admin-only action (a customer may only
    // cancel their own pending order through /cancel above).
    Route::patch('/orders/{id}/status', [OrderController::class, 'updateStatus'])
        ->middleware('role:admin');

    // Order items (read-only)
    Route::get('/order-items', [Order_itemController::class, 'index']);
    Route::get('/order-items/{id}', [Order_itemController::class, 'show']);

    // Payment management (create = record payment, admin manages rest)
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/payments', [PaymentController::class, 'store']);
    Route::get('/payments/{id}', [PaymentController::class, 'show']);
    Route::patch('/payments/{id}', [PaymentController::class, 'update']);
    Route::delete('/payments/{id}', [PaymentController::class, 'destroy']);
});
