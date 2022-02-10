<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReservationPolicy
{
    use HandlesAuthorization;

    public function visitorIndex(User $user)
    {
        return $user->can('reservation.index');
    }

    public function hostIndex(User $user)
    {
        return $user->can('reservation.index');
    }
}
