<?php

declare(strict_types=1);

namespace App\Entity\Booking\Helper;

use App\Entity\Booking\PassengerBooking;
use App\Entity\Passenger\Passenger;

class AllowToEditPersonalData
{
    public static function validate(array $data)
    {
        $errors = [];

        $editor = \Auth::user();
        $passenger = Passenger::findOrFail($data['passenger']);
        $booking = PassengerBooking::whereHas('passenger', function ($q) use ($passenger) {
                $q->where('id', $passenger->id);
            })
            ->first();

        if ($editor->id !== $booking->user_id && !$editor->hasRole('admin')) {
            $errors[] = ('The current user is not entitled to this operation.');
        }

        if ($errorMessage = $booking->canNotBeModifiedByOwner()) {
            $errors[] = $errorMessage;
        }

        return $errors;
    }
}
