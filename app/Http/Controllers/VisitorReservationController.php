<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use Illuminate\Http\Resources\Json\JsonResource;

class VisitorReservationController extends Controller
{
    public function index(): JsonResource
    {
        $this->authorize('visitorIndex', Reservation::class);

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
}
