<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    Auth\AuthController,
    HostReservationController,
    OfficeController,
    OfficeImageController,
    TagController,
    VisitorReservationController,
};

// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::post('/logout', [AuthController::class, 'logout']);
Route::get('/user', [AuthController::class, 'user']);

// Tags
Route::get('/tags', TagController::class)->name('tags.index');

// Offices
Route::apiResource('/offices', OfficeController::class);

// Office Images
Route::post('/offices/{office}/images', [OfficeImageController::class, 'store'])->name('offices.images.store');
Route::delete('/offices/{office}/images/{image:id}', [OfficeImageController::class, 'destroy'])->name('offices.images.delete');

// Visitor Reservation
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/reservations', [VisitorReservationController::class, 'index'])->name('visitor.reservations.index');
    Route::post('/reservations', [VisitorReservationController::class, 'store'])->name('visitor.reservations.store');
    Route::delete('/reservations/{reservation}', [VisitorReservationController::class, 'cancel'])->name('visitor.reservations.cancel');
});

// Host Reservation
Route::get('/host/reservations', [HostReservationController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('host.reservations.index');
