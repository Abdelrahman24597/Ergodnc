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

// Auth TODO:need testing.
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/refresh', [AuthController::class, 'refresh']);
Route::post('/logout', [AuthController::class, 'logout']);

// Tags
Route::get('/tags', TagController::class)->name('tags.index');

// Offices
Route::apiResource('/offices', OfficeController::class);

// Office Images
Route::post('/offices/{office}/images', [OfficeImageController::class, 'store'])->name('offices.images.store');
Route::delete('/offices/{office}/images/{image:id}', [OfficeImageController::class, 'destroy'])->name('offices.images.delete');

// Visitor Reservation
Route::get('/reservations', [VisitorReservationController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('visitor.reservations.index');

// Host Reservation TODO:need testing.
Route::get('/host/reservations', [HostReservationController::class, 'index'])->name('host.reservations.index');
