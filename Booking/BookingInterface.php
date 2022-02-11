<?php

declare(strict_types=1);

namespace App\Entity\Booking;

use App\Entity\Payment\Order\Passenger\Payment;

interface BookingInterface
{
    /**
     * @param array $data
     * @return BookingInterface
     */
    public static function add(array $data);

    /**
     * @return boolean
     */
    public function canBeBooked();
}
