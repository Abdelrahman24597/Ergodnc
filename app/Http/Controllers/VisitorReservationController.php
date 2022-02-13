<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Office;
use App\Models\Reservation;
use App\Notifications\NewHostReservation;
use App\Notifications\NewVisitorReservation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VisitorReservationController extends Controller
{
    public function index(): JsonResource
    {
        $this->authorize('index', Reservation::class);

        request()->validate([
            'status' => 'in:' . Reservation::STATUS_ACTIVE . ',' . Reservation::STATUS_CANCELLED,
            'office_id' => 'integer',
            'from_date' => 'date|required_with:to_date',
            'to_date' => 'date|required_with:from_date|after:from_date',
        ]);

        $reservations = Reservation::query()
            ->where('user_id', auth()->id())
            ->when(request('office_id'),
                fn($query) => $query->where('office_id', request('office_id'))
            )->when(request('status'),
                fn($query) => $query->where('status', request('status'))
            )->when(request('from_date') && request('to_date'),
                fn($query) => $query->betweenDates(request('from_date'), request('to_date'))
            )
            ->with(['office.featuredImage'])
            ->paginate(20);

        return ReservationResource::collection($reservations);
    }

    public function store(): JsonResource
    {
        $this->authorize('store', Reservation::class);

        $attributes = request()->validate([
            'office_id' => 'required|integer',
            'start_date' => 'required|date:Y-m-d|after:today',
            'end_date' => 'required|date:Y-m-d|after:start_date',
        ]);

        try {
            $office = Office::findOrFail($attributes['office_id']);
        } catch (ModelNotFoundException $e) {
            throw ValidationException::withMessages([
                'office_id' => 'Invalid office_id'
            ]);
        }

        if ($office->user_id == auth()->id()) {
            throw ValidationException::withMessages([
                'office_id' => 'You cannot make a reservation on your own office'
            ]);
        }

        if ($office->is_hidden || $office->approval_status == Office::APPROVAL_PENDING) {
            throw ValidationException::withMessages([
                'office_id' => 'You cannot make a reservation on a hidden office'
            ]);
        }

        $reservation = Cache::lock('reservations_office_' . $office->id, 10)->block(3, function () use ($attributes, $office) {
            if ($office->reservations()->activeBetween($attributes['start_date'], $attributes['end_date'])->exists()) {
                throw ValidationException::withMessages([
                    'office_id' => 'You cannot make a reservation during this time'
                ]);
            }

            $numberOfDays = Carbon::parse($attributes['end_date'])->endOfDay()->diffInDays(
                    Carbon::parse($attributes['start_date'])->startOfDay()
                ) + 1;

            $price = $numberOfDays * $office->price_per_day;

            if ($numberOfDays >= 28 && $office->monthly_discount) {
                $price = $price - ($price * $office->monthly_discount / 100);
            }

            return Reservation::create([
                'user_id' => auth()->id(),
                'office_id' => $office->id,
                'start_date' => $attributes['start_date'],
                'end_date' => $attributes['end_date'],
                'status' => Reservation::STATUS_ACTIVE,
                'price' => $price,
                'wifi_password' => Str::random()
            ]);
        });

        Notification::send(auth()->user(), new NewVisitorReservation($reservation));
        Notification::send($office->user, new NewHostReservation($reservation));

        return ReservationResource::make($reservation->load('office'));
    }

    public function cancel(Reservation $reservation): JsonResource
    {
        $this->authorize('cancel', Reservation::class);

        if ($reservation->status == Reservation::STATUS_CANCELLED || $reservation->start_date < now()->toDateString()) {
            throw ValidationException::withMessages([
                'reservation' => 'You cannot cancel this reservation'
            ]);
        }

        $reservation->update([
            'status' => Reservation::STATUS_CANCELLED
        ]);

        return ReservationResource::make($reservation->load('office'));
    }
}
