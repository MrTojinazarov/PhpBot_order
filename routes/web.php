<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/login', [AuthController::class, 'loginPage'])->name('loginPage');
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('check')->group(function () {
    Route::resource('category', App\Http\Controllers\CategoryController::class);
    Route::resource('meal', App\Http\Controllers\MealController::class);
    Route::resource('order',App\Http\Controllers\OrdersController::class);

    Route::get('/cart', [OrderController::class, 'index'])->name('cart.index');
    Route::patch('/cart/{id}', [OrderController::class, 'update'])->name('cart.update');
    Route::delete('/cart/{id}', [OrderController::class, 'remove'])->name('cart.remove');
    Route::post('/cart/confirm', [OrderController::class, 'confirm'])->name('cart.confirm');

    Route::post('/add', [MealController::class, 'add'])->name('add');
});
