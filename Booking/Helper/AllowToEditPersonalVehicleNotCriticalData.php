<?php

declare(strict_types=1);

namespace App\Entity\Booking\Helper;

use App\Entity\Booking\PassengerBooking;
use App\Entity\Vehicle\Personal;

class AllowToEditPersonalVehicleNotCriticalData
{
    public static function validate(array $data)
    {
        $errors = [];

        $editor = \Auth::user();
        $vehicle = Personal::findOrFail($data['vehicle']);
        $vehicleType = \App\Entity\VehicleTypes\Personal::find($vehicle->vehicle_id);
        $booking = PassengerBooking::findOrFail($vehicle->booking_id);

        if ($editor->id !== $booking->user_id && !$editor->hasRole('admin')) {
            $errors[] = ('The current user is not entitled to this operation.');
        }

        if (!$vehicleType) {
            $errors[] = ('This vehicle type not exists(' . $vehicle->vehicle_id . ').');
        }

        if ($data['length'] > $vehicleType->length) {
            $errors[] = ('length must be less or equal ' . $vehicleType->length . ' cm.');
        }

        if ($data['weight'] > $vehicleType->weight) {
            $errors[] = ('weight must be less or equal ' . $vehicleType->weight . ' Kg.');
        }

        if (!$editor->hasRole('admin')) {
            if ($errorMessage = $vehicle->canNotBeModifiedWithData($data)) {
                $errors[] = $errorMessage;
            }

            if ($errorMessage = $booking->canNotBeModifiedByOwner()) {
                $errors[] = $errorMessage;
            }
        }

        return $errors;
    }
}
