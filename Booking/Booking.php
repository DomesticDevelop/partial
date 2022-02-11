<?php

declare(strict_types=1);

namespace App\Entity\Booking;

use App\Entity\Payment\Order\Passenger\Payment;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $table = 'bookings';
    protected $fillable = [
        'id', 'status', 'type', 'number', 'user_id', 'order_batch', 'voyage_id', 'boarding_port', 'disembarking_port',
        'number_edit_attempts', 'bookinggable_id', 'bookinggable_type',
    ];

    public const UNPAID_STATUS = 'unpaid';
    public const UNINITIALIZED_STATUS = 'uninitialized';
    public const CANCELLED_STATUS = 'cancelled';
    public const REBOOKING_STATUS = 'rebooking';
    public const ACTIVE_STATUS = 'active';

    public const PASSENGER_BOOKING_TYPE = 'passenger';

    public const ALLOWABLE_ORDER_BALANCE_PERCENT_FOR_CREATE = 50;

    public const ALLOWABLE_NUMBER_EDIT_ATTEMPTS = 3;
}
