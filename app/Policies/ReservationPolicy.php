<?php

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReservationPolicy
{
    use HandlesAuthorization;

    public function index(User $user)
    {
        return $user->can('reservation.index');
    }

    public function store(User $user)
    {
        return $user->can('reservation.store');
    }

    public function cancel(User $user, Reservation $reservation)
    {
        return $user->id == $reservation->user_id && $user->can('reservation.cancel');
    }
}
