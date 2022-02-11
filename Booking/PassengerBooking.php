<?php

declare(strict_types=1);

namespace App\Entity\Booking;

use App\Entity\Acquiring\Test\UpcAcquiring;
use App\Entity\CurrencyRatio;
use App\Entity\Passenger\Passenger;
use App\Entity\Payment\Booking\Passenger\Payment as BookingPayment;
use App\Entity\Payment\Order\Passenger\Payment;
use App\Entity\AdditionalServices\AdditionalServices;
use App\Entity\Port;
use App\Entity\Agent\Agent;
use App\Entity\Rebooking\PassengerBooking as Rebooking;
use App\Entity\Tag\Tag;
use App\Entity\Tariff\Passenger as PassengerTariff;
use App\Entity\Tariff\AdditionalServices\Tariff as AdditionalServicesTariff;
use App\Entity\Tariff\AdditionalServices\UseCase as AdditionalTariffUseCase;
use App\Entity\Tariff\Vehicle\Personal as PersonalVehicleTariff;
use App\Entity\User\User;
use App\Entity\Vehicle\Personal;
use App\Entity\Voyage\Voyage;
use App\Entity\Settings;
use App\Entity\Voyage\VoyagesPortsPivot;
use App\ReadModel\Booking\PassengerBookingFetcher;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PassengerBooking extends Booking implements BookingInterface
{
    public const PERSONAL_VEHICLE_SOURCE_TYPE = Personal::class;
    public const PASSENGER_SOURCE_TYPE = Passenger::class;
    public const ADDITIONAL_SERVICE_TYPE = AdditionalServices::class;


    public static function getAdditionalBookingConfirmationEmails()
    {
        return env('ADDITIONAL_BOOKING_CONFIRMATION_EMAILS', 'rma@xxxxxxx.com');
    }


    public function passenger()
    {
        return $this->hasMany(Passenger::class, 'booking_id', 'id')
            ->where('status', Passenger::ACTIVE_STATUS);
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function bookinggable()
    {
        return $this->morphTo();
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function upcAcquiring()
    {
        return $this->hasMany(UpcAcquiring::class, 'booking_id');
    }

    public function boarding()
    {
        return $this->belongsTo(Port::class, 'boarding_port');
    }

    public function disembarking()
    {
        return $this->belongsTo(Port::class, 'disembarking_port');
    }

    public function voyage()
    {
        return $this->belongsTo(Voyage::class, 'voyage_id')->with('ship', 'start', 'finish');
    }

    public function vehicle()
    {
        return $this->hasMany(Personal::class, 'booking_id', 'id');
    }

    public function additionalService()
    {
        return $this->hasMany(AdditionalServices::class, 'booking_id', 'id')->with('type');
    }

    public static function generateCabinBindNumber()
    {
        return  \Str::random(8);
    }


    public function delete()
    {
        $this->vehicle()->delete();
        $this->passenger()->delete();
        $this->additionalService()->delete();
        return parent::delete();
    }

    public static function getByOrder(string $orderBatch)
    {
        $booking = self::where('order_batch', $orderBatch)->first();

        return $booking;
    }

    public static function isStatusAsOccupiedPlaces($status) {
        return in_array($status, [self::ACTIVE_STATUS]);
    }

    public static function existsWithCabin(int $cabin, int $voyage)
    {
        return self::where('voyage_id', $voyage)
            ->where('type', Booking::PASSENGER_BOOKING_TYPE)
            ->where('status', Booking::ACTIVE_STATUS)
            ->whereExists(function ($q) use ($cabin) {
                $q->select(\DB::raw(1))
                    ->from('passengers')
                    ->whereRaw('bookings.id = passengers.booking_id')
                    ->where('passengers.cabin_id', $cabin);
            })
            ->get()
            ->isNotEmpty();
    }

    public static function generate_string($strength = 6) {
        $permitted_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $input_length = strlen($permitted_chars);
        $random_string = '';
        for($i = 0; $i < $strength; $i++) {
            $random_character = $permitted_chars[mt_rand(0, $input_length - 1)];
            $random_string .= $random_character;
        }

        return $random_string;
    }

    private static function generateNumber()
    {
        $tryNumber = self::generate_string(5);
        if (self::where('number', $tryNumber)->first()) {
            return self::generate_string(5);
        }
        return $tryNumber;
    }

    public static function createEmptyOnBased(int $voyage, PassengerBooking $based)
    {
        return self::add([
            'status' => self::ACTIVE_STATUS,
            'type' => self::PASSENGER_BOOKING_TYPE,
            'number' => self::generateNumber(),
            'user_id' => $based->user_id,
            'order_batch' => $based->order_batch,
            'voyage_id' => $voyage,
            'boarding_port' => $based->boarding_port,
            'disembarking_port' => $based->disembarking_port,
        ]);
    }

    public static function createByOrder(array $order)
    {
        return self::add([
            'status' => self::UNINITIALIZED_STATUS,
            'type' => self::PASSENGER_BOOKING_TYPE,
            'number' => self::generateNumber(),
            'user_id' => \Auth::user()->id,
            'order_batch' => $order['batch'],
            'voyage_id' => $order['voyage']['id'],
            'boarding_port' => $order['boarding_id'],
            'disembarking_port' => $order['disembarking_id'],
        ]);
    }

    public static function add($data)
    {
        $booking = self::create([
            'status' => $data['status'],
            'type' => $data['type'],
            'number' => $data['number'],
            'user_id' => $data['user_id'],
            'order_batch' => $data['order_batch'],
            'voyage_id' => $data['voyage_id'],
            'boarding_port' => $data['boarding_port'],
            'disembarking_port' => $data['disembarking_port'],
        ]);

        $booking->save();

        return $booking;
    }

    public function bindPersonalVehicle(array $data)
    {
        $driverPassenger = Passenger::where('id', $data['driver'])
            ->where('booking_id', $this->id)->first();

        if ($driverPassenger->hasPersonalVehicleOnVoyage($this->voyage_id)) {
            throw new \DomainException('This passenger already has personal vehicle.');
        }

        $command = new PersonalVehicleTariff\UseCase\Calculate\Command(
            [
                'voyage' => $this->voyage_id,
                'ship_id' => $this->voyage->ship->id,
                'driver_booking_number' => $data['booking_number'],
                'vehicle_type' => $data['vehicle_type'],
                'length' => $data['length'],
                'weight' => $data['weight'],
                'passenger_id' => $data['driver'],
                'currency' => $data['currency']
            ]
        );

        $handler = new PersonalVehicleTariff\UseCase\Calculate\Handler();
        $tariff = $handler->handle($command);

        $personalVehicle = [
            'status' => Personal::STATUS_ACTIVE,
            'sales_method' => empty($data['sales_method']) ? 'online' : $data['sales_method'],
            'booking_id' => $this->id,
            'vehicle_id' => $data['vehicle_type'],
            'length' => $data['length'],
            'weight' => $data['weight'],
            'vehicle_make' => $data['vehicle_make'],
            'vehicle_model' => $data['vehicle_model'],
            'date_issue' => $data['date_issue'],
            'vin' => $data['vin'],
            'registration_number' => $data['reg_number'],
            'driver' => $data['driver'],
            'proprietor' => $data['proprietor'],
            'tariff' => $tariff['amount'],
            'currency' => $data['currency'],
            'base_currency' => PassengerTariff\Tariff::BASE_CURRENCY,
            'ratio_to_base_currency' => CurrencyRatio::getCurrencyRatio($data['currency'], PassengerTariff\Tariff::BASE_CURRENCY),
            'discounts' => $tariff['discountAmount'],
            'base_tariff_id' => $tariff['base_tariff_id']
        ];
        $personalVehicle = Personal::create($personalVehicle);
        $personalVehicle->save();
    }

    public function rebookAdditionalServices(
        \Illuminate\Support\Collection $services,
        array $rebookedPassengers,
        self $sourceBooking,
        string $comment
    )
    {
        $rebookedServices = [];
        foreach($services as $item){

            $item->update([
                'status' => AdditionalServices::ACTIVE_STATUS,
                'booking_id' => $this->id,
                'cabin_bind' => $rebookedPassengers[0]['cabin_bind'],
                'service_id' => $item['service_id'],
                'tariff' =>  $item['tariff'],
                'discounts' => $item['discounts'],
                'base_tariff_id' => $item['base_tariff_id'],
                'currency' => $item['currency'],
                'base_currency' => $item['base_currency'],
                'ratio_to_base_currency' => $item['ratio_to_base_currency'],
            ]);
            $item->save();

            Rebooking::create([
                'model' => AdditionalServices::class,
                'voyage_source' => $sourceBooking->voyage_id,
                'booking_source' => $sourceBooking->id,
                'original_source' => $item->id,
                'voyage_dest' => $this->voyage_id,
                'booking_dest' => $this->id,
                'original_dest' => $item->id,
                'user_id' => \Auth::user()->id,
                'comment' => $comment,
            ]);

            $rebookedServices[] = $item;
        }

        return $rebookedServices;
    }

    public function rebookPersonalVehicle(
        \Illuminate\Support\Collection $personalVehicle,
        array $rebookedPassengers,
        self $sourceBooking,
        string $comment
    )
    {
        $rebookedVehicles = [];
        foreach($personalVehicle as $item){
            /*$newDriver = array_filter($rebookedPassengers, function ($pass) use($item) {
                return $pass['id'] === $item['driver'];
            })[0];*/

            $item->update([
                'status' => Personal::STATUS_ACTIVE,
                'driver' => $item['driver'],
                'sales_method' => $item['sales_method'],
                'booking_id' => $this->id,
                'vehicle_id' => $item['vehicle_id'],
                'length' => $item['length'],
                'weight' => $item['weight'],
                'vehicle_make' => $item['vehicle_make'],
                'vehicle_model' => $item['vehicle_model'],
                'date_issue' => $item['date_issue'],
                'vin' => $item['vin'],
                'registration_number' => $item['registration_number'],
                'proprietor' => $item['proprietor'],
                'tariff' => $item['tariff'],
                'currency' => $item['currency'],
                'base_currency' =>$item['base_currency'],
                'ratio_to_base_currency' => $item['ratio_to_base_currency'],
                'discounts' => $item['discounts'],
                'base_tariff_id' => $item['base_tariff_id']
                ]);

            $item->save();

            Rebooking::create([
                'model' => Personal::class,
                'voyage_source' => $sourceBooking->voyage_id,
                'booking_source' => $sourceBooking->id,
                'original_source' => $item->id,
                'voyage_dest' => $this->voyage_id,
                'booking_dest' => $this->id,
                'original_dest' => $item->id,
                'user_id' => \Auth::user()->id,
                'comment' => $comment,
            ]);

            $rebookedVehicles[] = $item;
        }

        return $rebookedVehicles;
    }

    public function rebookPassengers(
        \Illuminate\Support\Collection $passengers,
        $sourceBooking,
        int $destinationCabin,
        string $destinationTariffType,
        string $comment): array
    {

        $rebookedPassengers = [];
        $cabinBind = self::generateCabinBindNumber();
        foreach($passengers as $passengerSource){
            $passenger = Passenger::find($passengerSource->id);
            $passenger->update([
                'status' => Passenger::ACTIVE_STATUS,
                'booking_id' => $this->id,
                'cabin_id' => $destinationCabin,
                'sales_method' => $passengerSource['sales_method'],
                'tariff_type' => $destinationTariffType,
                'cabin_bind' => $cabinBind,
                'accommodation' => $passengers->count(),
                'seat_type' => $passengerSource['seat_type'],
                'travel_way' => $passengerSource['travel_way'],
                'tariff' => (int)$passengerSource['tariff'],
                'discounts' => 0,
                'base_tariff_id' => PassengerTariff\Tariff::REBOOKING_TARIFF_ID,
                'currency' => $passengerSource['currency'],
                'base_currency' => $passengerSource['base_currency'],
                'ratio_to_base_currency' => $passengerSource['ratio_to_base_currency'],
                'first_name' => $passengerSource['first_name'],
                'last_name' => $passengerSource['last_name'],
                'birth_date' => $passengerSource['birth_date'],
                'citizenship' => $passengerSource['citizenship'],
                'passport_id' => $passengerSource['passport_id'],
                'pin' => $passengerSource['pin'],
                'sex' => $passengerSource['sex'],
            ]);
            $passenger->save();

            Rebooking::create([
                'model' => Passenger::class,
                'voyage_source' => $sourceBooking->voyage_id,
                'booking_source' => $sourceBooking->id,
                'original_source' => $passengerSource->id,
                'voyage_dest' => $this->voyage_id,
                'booking_dest' => $this->id,
                'original_dest' => $passenger->id,
                'user_id' => \Auth::user()->id,
                'comment' => $comment,
            ]);

            $rebookedPassengers[] = $passenger;
        }

        return $rebookedPassengers;
    }

    public function bindPassengers(array $order)
    {
        $cabinsPassengers = array_reduce($order['cabins'], function ($passengers, $cabin) {
            return array_merge($passengers, call_user_func_array(
                'array_merge',
                count($cabin['passengers']) ? [$cabin['passengers']] : []
            ));
        }, []);

        foreach($cabinsPassengers as $PassengerData) {
                $command = new PassengerTariff\UseCase\Calculate\Command(
                    [
                        'voyage' => $order['voyage']['id'],
                        'ship_id' => $order['voyage']['ship']['id'],
                        'line_from' => $order['voyage']['start'][0]['id'],
                        'line_to' => $order['voyage']['finish'][0]['id'],
                        'boarding' => $order['boarding_id'],
                        'departure' => Carbon::createFromFormat('d.m.Y H:i:s', $order['departure'])->format('Y-m-d H:i'),
                        'disembarking' => $order['disembarking_id'],
                        'type' => $PassengerData['tariff_type'],
                        'cabin' => $PassengerData['cabin_id'],
                        'travelWay' => $PassengerData['travel_way'],
                        'accommodation' => $PassengerData['accommodation'],
                        'currency' => $PassengerData['currency'],
                    ]
                );

                $handler = new PassengerTariff\UseCase\Calculate\Handler();
                $tariff = $handler->handle($command);

                $passenger = [
                    'status' => Passenger::ACTIVE_STATUS,
                    'booking_id' => $this->id,
                    'cabin_id' => $PassengerData['cabin_id'],
                    'sales_method' => $PassengerData['sales_method'],
                    'tariff_type' => $PassengerData['tariff_type'],
                    'cabin_bind' => $PassengerData['cabin_bind'],
                    'accommodation' => $PassengerData['accommodation'],
                    'seat_type' => $PassengerData['seat_type'],
                    'travel_way' => $PassengerData['travel_way'],
                    'tariff' => (int)$tariff['amount'],
                    //'tariff' => $PassengerData['tariff'],
                    'discounts' => $PassengerData['discounts'],
                    'base_tariff_id' => $PassengerData['base_tariff_id'],
                    'currency' => $PassengerData['currency'],
                    'base_currency' => PassengerTariff\Tariff::BASE_CURRENCY,
                    'ratio_to_base_currency' => CurrencyRatio::getCurrencyRatio($PassengerData['currency'], PassengerTariff\Tariff::BASE_CURRENCY),
                    'first_name' => $PassengerData['first_name'],
                    'last_name' => $PassengerData['last_name'],
                    'birth_date' => $PassengerData['birth_date'],
                    'citizenship' => $PassengerData['citizenship'],
                    'passport_id' => $PassengerData['passport_id'],
                    'pin' => $PassengerData['pin'],
                    'sex' => $PassengerData['sex'],
                ];

                $passenger = Passenger::create($passenger);
                $passenger->save();

                if (!$PassengerData['vehicle']) { continue;}

                $this->bindPersonalVehicle([
                    'status' => Personal::STATUS_ACTIVE,
                    'sales_method' => 'online',
                    'booking_number' => $this->number,
                    'vehicle_type' => $PassengerData['vehicle'],
                    'length' => $PassengerData['vehicle_length'],
                    'weight' => $PassengerData['vehicle_weight'],
                    'vehicle_make' => $PassengerData['vehicle_make'],
                    'vehicle_model' => $PassengerData['vehicle_model'],
                    'date_issue' => $PassengerData['date_issue'],
                    'proprietor' => $PassengerData['proprietor'],
                    'vin' => $PassengerData['vin'],
                    'reg_number' => $PassengerData['registration_number'],
                    'driver' => $passenger->id,
                    'currency' => $PassengerData['currency'],
                ]);
            }
    }

    public function bindAdditionalServices(array $order)
    {
        $cabinsAdditionalServices = array_reduce($order['cabins'], function ($additionalServices, $cabin) {
            if(count($cabin['additionalServices']) <= 0) {
                return $additionalServices;
            }
            return array_merge($additionalServices, call_user_func_array(
                'array_merge',
                count($cabin['additionalServices']) ? [$cabin['additionalServices']] : []
            ));
        }, []);

        try {
            foreach($cabinsAdditionalServices as $additionalServiceData) {

                $command = new AdditionalTariffUseCase\Calculate\Command([
                    'voyage' => $order['voyage']['id'],
                    'ship_id' => $order['voyage']['ship']['id'],
                    'service' => $additionalServiceData['slug'],
                    'currency' => $additionalServiceData['currency'],
                ]);

                $handler = new AdditionalTariffUseCase\Calculate\Handler();
                $tariff = $handler->handle($command);

                $service = [
                    'status' => AdditionalServices::ACTIVE_STATUS,
                    'booking_id' => $this->id,
                    'cabin_bind' => $additionalServiceData['cabin_bind'],
                    'service_id' => $additionalServiceData['service_id'],
                    'tariff' =>  $tariff['amount'],
                    'discounts' => $additionalServiceData['discounts'],
                    'base_tariff_id' => $additionalServiceData['base_tariff_id'],
                    'currency' => $additionalServiceData['currency'],
                    'base_currency' => AdditionalServicesTariff::BASE_CURRENCY,
                    'ratio_to_base_currency' => CurrencyRatio::getCurrencyRatio(
                        $additionalServiceData['currency'], AdditionalServicesTariff::BASE_CURRENCY),
                ];

                $service = AdditionalServices::create($service);
                $service->save();
            }
        } catch (\Exception $e) {
            \Log::channel('daily')->debug($e->getMessage());
            throw new \DomainException('Failed to add additional services to the booking! Error: ' . $e->getMessage());
        }
    }

    public static function currency($booking)
    {
        $currencyPassColl = Passenger::where('booking_id', $booking)
            ->where('status', Passenger::ACTIVE_STATUS)
            ->select('currency')
            ->get();

        if ($currencyPassColl->isEmpty()) {
            return null;
        }
        $passengerCurrency = $currencyPassColl->first()->currency;
        foreach ($currencyPassColl as $item) {
            if ($item->currency !== $passengerCurrency) { $passengerCurrency = null; }
        }

        $currencyPersonalVehColl = Personal::where('booking_id', $booking)
            ->where('status', Personal::STATUS_ACTIVE)
            ->select('currency')
            ->get();
        if ($currencyPersonalVehColl->isEmpty()) {
            return $passengerCurrency;
        }
        $personalVehicleCurrency = $currencyPersonalVehColl->first()->currency;
        foreach ($currencyPersonalVehColl as $item) {
            if ($item->currency !== $personalVehicleCurrency) { $currency = null; }
        }

        if ($personalVehicleCurrency !== $passengerCurrency) {
            $currency = null;
        } else {
            $currency = $passengerCurrency = $personalVehicleCurrency;
        }

        return $currency;
    }

    public function getCurrency()
    {
        $currency = null;
        try {
            $currencyPassColl = Passenger::where('booking_id', $this->id)
                ->select('currency')
                ->get();

            if (!$currencyPassColl->isEmpty()) {
                $currency = $currencyPassColl->first()->currency;
                foreach ($currencyPassColl as $item) {
                    if ($item->currency !== $currency) {
                        throw new \DomainException('Different currencies in the booking: ' . $this->id);
                    }
                }
            }

            $currencyPersonalVehColl = Personal::where('booking_id', $this->id)
                ->where('status', Personal::STATUS_ACTIVE)
                ->select('currency')
                ->get();

            if (!$currencyPersonalVehColl->isEmpty()) {
                $personalVehicleCurrency = $currencyPersonalVehColl->first()->currency;
                foreach ($currencyPersonalVehColl as $item) {
                    if ($item->currency !== $personalVehicleCurrency) {
                        throw new \DomainException('Different currencies in the booking: ' . $this->id);
                    }
                }
                if ($currency && ($currency !== $personalVehicleCurrency)) {
                    throw new \DomainException('Different currencies in the booking: ' . $this->id);
                }
            }

            $currencyAddServicesColl = AdditionalServices::where('booking_id', $this->id)
                ->where('status', AdditionalServices::ACTIVE_STATUS)
                ->select('currency')
                ->get();

            if (!$currencyAddServicesColl->isEmpty()) {
                $addServicesCurrency = $currencyAddServicesColl->first()->currency;
                foreach ($currencyAddServicesColl as $item) {
                    if ($item->currency !== $addServicesCurrency) {
                        throw new \DomainException('Different currencies in the booking: ' . $this->id);
                    }
                }
                if ($currency && ($currency !== $addServicesCurrency)) {
                    throw new \DomainException('Different currencies in the booking: ' . $this->id);
                }
            }

            $paymentsColl = BookingPayment::where('booking_id', $this->id)
                ->where('status', BookingPayment::ACCEPTED_PAYMENT_STATUS)
                ->select('currency')
                ->get();

            if (!$paymentsColl->isEmpty()) {
                $paymentsCurrency = $paymentsColl->first()->currency;
                foreach ($paymentsColl as $item) {
                    if ($item->currency !== $paymentsCurrency) {
                        throw new \DomainException('Different currencies in the booking: ' . $this->id);
                    }
                }
                if ($currency && ($currency !== $paymentsCurrency)) {
                    throw new \DomainException('Different currencies in the booking: ' . $this->id);
                }

                $currency = $paymentsCurrency;
            }

            return $currency;

        } catch(\Exception $e) {
            throw new \DomainException($e->getMessage());
        }




        return $currency;
    }

    public static function totalAmount($booking)
    {
        $bookingTotalInvoice = 0;
        $passengerTariffColl = Passenger::where('booking_id', $booking)
            ->where('status', Passenger::ACTIVE_STATUS)
            ->select('tariff')
            ->get();
        if ($passengerTariffColl->isNotEmpty()) {
            $bookingTotalInvoice += $passengerTariffColl->sum('tariff');
        }

        $personalVehicleTariffColl = Personal::where('booking_id', $booking)
            ->where('status', Personal::STATUS_ACTIVE)
            ->select('tariff')
            ->get();
        if ($personalVehicleTariffColl->isNotEmpty()) {
            $bookingTotalInvoice += $personalVehicleTariffColl->sum('tariff');
        }

        $additionalServiceColl = AdditionalServices::where('booking_id', $booking)
            ->where('status', AdditionalServices::ACTIVE_STATUS)
            ->select('tariff')
            ->get();
        if ($additionalServiceColl->isNotEmpty()) {
            $bookingTotalInvoice += $additionalServiceColl->sum('tariff');
        }

        return $bookingTotalInvoice;
    }

    public function getTotalAmount()
    {
        $bookingTotalInvoice = 0;
        $passengerTariffColl = Passenger::where('booking_id', $this->id)
            ->where('status', Passenger::ACTIVE_STATUS)
            ->select('tariff')
            ->get();
        if ($passengerTariffColl->isNotEmpty()) {
            $bookingTotalInvoice += $passengerTariffColl->sum('tariff');
        }

        $personalVehicleTariffColl = Personal::where('booking_id', $this->id)
            ->where('status', Personal::STATUS_ACTIVE)
            ->select('tariff')
            ->get();
        if ($personalVehicleTariffColl->isNotEmpty()) {
            $bookingTotalInvoice += $personalVehicleTariffColl->sum('tariff');
        }

        $additionalServiceColl = AdditionalServices::where('booking_id', $this->id)
            ->where('status', AdditionalServices::ACTIVE_STATUS)
            ->select('tariff')
            ->get();
        if ($additionalServiceColl->isNotEmpty()) {
            $bookingTotalInvoice += $additionalServiceColl->sum('tariff');
        }

        return $bookingTotalInvoice;
    }

    public function getTransferPayments(string $currency)
    {
        if (!$currency = $this->getCurrency()) {
            throw new \DomainException('Booking Currency Error!');
        }

        $transferPayments = \DB::table('booking_payments as bp')
            ->select('bp.*')
            ->where('bp.order_batch', $this->order_batch)
            ->where('bp.booking_id', $this->id)
            ->where('bp.currency', $currency)
            ->where('bp.transaction_type', Payment::PAYMENT_TRANSACTION_TYPE)
            ->where('bp.payment_method', Payment::TRANSFER_PAYMENT_METHOD)
            ->whereExists(function ($q) {
                $q->select(\DB::raw(1))
                    ->from('booking_payments')
                    ->whereRaw('booking_payments.transferred = bp.booking_id')
                    ->whereRaw('booking_payments.transaction_type = \'' . \App\Entity\Payment\Booking\Passenger\Payment::TRANSFER_TRANSACTION_TYPE . '\'')
                    ->whereRaw('booking_payments.amount = bp.amount')
                    ->whereRaw('booking_payments.currency = bp.currency');
            })
            ->get()
            ->sum('amount');

        return $transferPayments;
    }

    public function getTransfers(string $currency)
    {
        if (!$currency = $this->getCurrency()) {
            throw new \DomainException('Booking Currency Error!');
        }

        $transfers = \DB::table('booking_payments as bp')
            ->select('bp.*')
            ->where('bp.order_batch', $this->order_batch)
            ->where('bp.source', $this->id)
            ->where('bp.currency', $currency)
            ->where('bp.transaction_type', Payment::TRANSFER_PAYMENT_METHOD)
            ->whereExists(function ($q) {
                $q->select(\DB::raw(1))
                    ->from('booking_payments')
                    ->whereRaw('booking_payments.booking_id = bp.transferred')
                    ->whereRaw('booking_payments.transaction_type = \'' . \App\Entity\Payment\Booking\Passenger\Payment::PAYMENT_TRANSACTION_TYPE . '\'')
                    ->whereRaw('booking_payments.amount = bp.amount')
                    ->whereRaw('booking_payments.payment_method = \'' . \App\Entity\Payment\Booking\Passenger\Payment::TRANSFER_PAYMENT_METHOD . '\'')
                    ->whereRaw('booking_payments.currency = bp.currency');
            })
            ->get()->sum('amount');

        return $transfers;
    }

    public function getDirectPayments(string $currency)
    {
        return \DB::table('booking_payments')
            ->where('booking_id', $this->id)
            ->where('currency', $currency)
            ->where('transaction_type', Payment::PAYMENT_TRANSACTION_TYPE)
            ->whereIn('payment_method', [
                Payment::AGENT_CREDIT_PAYMENT_METHOD,
                Payment::CARD_PAYMENT_METHOD,
            ])
            ->get()
            ->sum('amount');
    }

    public function getRefunds(string $currency)
    {
        return \DB::table('booking_payments')
            //->where('order_batch', $this->order_batch)
            ->where('booking_id', $this->id)
            ->where('transaction_type', Payment::REFUND_TRANSACTION_TYPE)
            ->where('currency', $currency)
            //->where('payment_method', Payment::MANAGER_REFUNDING_PAYMENT_METHOD)
            ->get()
            ->sum('amount');
    }

    public function getTransferPaymentBalance($currency)
    {
        $transferPayments = $this->getTransferPayments($currency);
        $transfers = $this->getTransfers($currency);

        return $transferPayments - $transfers;
    }

    public static function paidByOrder($order) {
        $payments = Payment::where('order_batch', $order)
            ->select(\DB::raw('booking_id'))
            ->groupBy('booking_id')
            ->get();

        if (!$payments->count()) {
            return 0;
        }

        if (count($payments->toArray()) > 1) {
            throw new \DomainException('FATAL! ERROR! Order ' . $order . ' belongs more than one booking!');
        }

        $booking = PassengerBooking::find($payments->first()->toArray()['booking_id']);
        return $booking->getPaymentBalance();
    }

    public static function paidByBooking($booking) {
        $booking = PassengerBooking::find($booking);
        return $booking->getPaymentBalance();
    }

    public function getPaymentBalance()
    {
        try {
            $currency = $this->getCurrency();
            if ($currency === null) {
                return 0;
            }
            return $this->getTransferPaymentBalance($currency) + $this->getDirectPayments($currency) - $this->getRefunds($currency);
        } catch (\Exception $e) {
            throw new \DomainException($e->getMessage());
        }
    }

    public function canBeBooked()
    {
        $bookingPaymentBalance = $this->getPaymentBalance();

        $bookingNeedAmount = $this->getTotalAmount();
        if ($bookingPaymentBalance < $bookingNeedAmount * (self::ALLOWABLE_ORDER_BALANCE_PERCENT_FOR_CREATE / 100)) {
            return false;
        }
        return true;
    }

    public function canNotBeModifiedByOwner()
    {
        $allowableInterval = Settings\Booking::where('group_id', Settings\Booking::EXPIRING_EDIT_INTERVAL_TYPE)
            ->where('model_id', $this->boarding_port)
            ->first()->value;
        $departure = \DB::table('voyages_ports')
            ->where('voyage_id', $this->voyage_id)
            ->where('port_id', $this->boarding_port)
            ->first()->departure;

        if (Carbon::now()->addMinutes($allowableInterval)->gt(Carbon::createFromFormat('Y-m-d H:i:s', $departure))) {
            return __('error.Time for corrections expired');
        }
        if ($this->number_edit_attempts >= self::ALLOWABLE_NUMBER_EDIT_ATTEMPTS) {
            return __('error.Attempt limit exceeded') . ' (max 3)';
        }

        return false;
    }

    public function canNotUploadOrChangeVehicleDocuments()
    {
        $allowableInterval = Settings\Booking::where('group_id', Settings\Booking::EXPIRING_EDIT_INTERVAL_TYPE)
            ->where('model_id', $this->boarding_port)
            ->first()->value;
        $departure = \DB::table('voyages_ports')
            ->where('voyage_id', $this->voyage_id)
            ->where('port_id', $this->boarding_port)
            ->first()->departure;

        if (Carbon::now()->addMinutes($allowableInterval)->gt(Carbon::createFromFormat('Y-m-d H:i:s', $departure))) {
            return __('error.Time for corrections expired');
        }

        return false;
    }

    public function getPDFCustomerConfirmation()
    {
        $fetcher = new PassengerBookingFetcher();
        $booking = $fetcher->byId($this->id, false);
        $user = User::where('id', $booking['user_id'])->first();
        $agent = Agent::getRequiredAgent($booking['boarding_id'], $booking['disembarking_id']);
        return \PDF::loadView('pdf.booking_confirmation.customer', ['user' => $user, 'booking' => $booking, 'agent' => $agent]);
    }

    public function getPDFInternalPurposesConfirmation()
    {
        $fetcher = new PassengerBookingFetcher();
        $booking = $fetcher->byId($this->id, false);
        $user = User::where('id', $booking['user_id'])->first();
        $agent = Agent::getRequiredAgent($booking['boarding_id'], $booking['disembarking_id']);
        return \PDF::loadView('pdf.booking_confirmation.internal_purposes', ['user' => $user, 'booking' => $booking, 'agent' => $agent]);
    }

    public function getCabinBinds()
    {
        $binds = $this->passenger()
            ->get()
            ->groupBy('cabin_bind')
            ->map(function(Collection $grouped, $key) {
                return $key;
            });

        return $binds->values();
    }

    public static function lastPaymentDate(int $booking)
    {
        $date = self::payments($booking)->reduce(function ($carry, $payment) {
            if (!$carry) { return $payment->created_at; }
            if (Carbon::createFromFormat('d-m-Y H:i', $payment->created_at)->gt(Carbon::createFromFormat('d-m-Y H:i', $carry))) {
                return $payment->created_at;
            }
        }, null);
        return $date;
    }

    public static function firstPaymentDate(int $booking)
    {
        $date = self::payments($booking)->reduce(function ($carry, $payment) {
            if (!$carry) { return $payment->created_at; }
            if (Carbon::createFromFormat('d-m-Y H:i', $payment->created_at)->lte(Carbon::createFromFormat('d-m-Y H:i', $carry))) {
                dump($payment->created_at);
                return $payment->created_at;
            }
            return $carry;
        }, null);

        return $date;
    }

    public static function payments($booking)
    {
        $payments = \DB::table('booking_payments')
            ->leftJoin('users_base', 'users_base.id', '=', 'booking_payments.user_id')
            ->select(
                'booking_payments.id',
                'amount',
                'source',
                'currency',
                'description',
                'payment_method',
                'transaction_type',
                'booking_payments.user_id',
                'users_base.name as user_name',
                'booking_payments.created_at')
            ->where('booking_payments.booking_id', '=', $booking)
            ->where('booking_payments.transaction_type', '=', \App\Entity\Payment\Booking\Passenger\Payment::PAYMENT_TRANSACTION_TYPE)
            ->where(function ($q) {
                $q->where(function ($q) {
                    $q->where('booking_payments.status', \App\Entity\Payment\Booking\Passenger\Payment::ACCEPTED_PAYMENT_STATUS);
                })
                ->orWhere(function ($q) {
                    $q->whereExists(function ($q) {
                        $q->select(\DB::raw(1))
                            ->from('booking_payments as bp')
                            ->whereRaw('bp.transferred = booking_payments.booking_id')
                            ->whereRaw('bp.transaction_type = \'' . \App\Entity\Payment\Booking\Passenger\Payment::TRANSFER_TRANSACTION_TYPE . '\'')
                            ->whereRaw('bp.amount = booking_payments.amount')
                            ->whereRaw('bp.currency = booking_payments.currency');
                    });
                });
            })
            ->get();
        $payments->map(function ($payment) {
            $payment->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $payment->created_at, 'UTC')
                ->setTimezone('Europe/Kiev')->format('d-m-Y H:i');
            return $payment;
        });
        return $payments;
    }

    public static function transfers($booking)
    {
        $transfers = \DB::table('booking_payments')
            ->leftJoin('users_base', 'users_base.id', '=', 'booking_payments.user_id')
            ->select(
                'booking_payments.id',
                'booking_payments.source as source',
                'booking_payments.transferred as destination',
                'amount',
                'currency',
                'description',
                'payment_method',
                'transaction_type',
                'booking_payments.user_id',
                'users_base.name as user_name',
                'booking_payments.created_at')
            ->where('booking_payments.source', '=', $booking)
            ->where('booking_payments.transaction_type', '=', \App\Entity\Payment\Booking\Passenger\Payment::TRANSFER_TRANSACTION_TYPE)
            ->get();
        $transfers->map(function ($transfer) {
            $transfer->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $transfer->created_at, 'UTC')
                ->setTimezone('Europe/Kiev')->format('d-m-Y H:i');
            return $transfer;
        });
        return $transfers;
    }

    public static function refunds($booking)
    {
        $refunds = \DB::table('booking_refunds')
            ->leftJoin('users_base', 'users_base.id', '=', 'booking_refunds.user_id')
            ->select(
                'booking_refunds.id',
                'amount', 'currency',
                'users_base.name as user_name',
                'comment',
                'booking_refunds.status',
                'booking_refunds.created_at',
                'booking_refunds.user_id')
            ->where('booking_refunds.booking_id', '=', $booking)
            ->get();

        $refunds->map(function ($refund) {
            $refund->created_at = Carbon::createFromFormat('Y-m-d H:i:s', $refund->created_at, 'UTC')
                ->setTimezone('Europe/Kiev')->format('d-m-Y H:i');
            return $refund;
        });
        return $refunds;
    }

    public static function getTroubles(int $booking)
    {
        $troubles = [];
        $invoice = self::totalAmount($booking);
        $paid = self::paidByBooking($booking);
        if ($toPay = $invoice - $paid) {
            $troubles[] = 'bad_payment_balance';
        }

        return $troubles;
    }

    public function updateVoyage(int $newVoyage)
    {
        $this->voyage_id = $newVoyage;
        $this->save();
    }

    public function removePassenger(int $passenger)
    {
        $passenger = Passenger::where('id', $passenger)
            ->where('booking_id', $this->id)
            ->first();
        if (!$passenger) {
            throw new \DomainException('there is no passenger with this ID:' . $passenger);
        }

        $passenger->update(['status' => Passenger::COMPANY_CANCELED_STATUS]);
        $passenger->save();
    }

    public function removePersonalVehicle(int $vehicle, string $reason = Personal::STATUS_CANCELED_BY_COMPANY)
    {
        $vehicle = Personal::where('id', $vehicle)
            ->where('booking_id', $this->id)
            ->first();

        if (!$vehicle) {
            throw new \DomainException('there is no Personal Vehicle with this ID:' . $vehicle);
        }

        if (!$vehicle->isCancellable()) {
            throw new \DomainException('Vehicle status is not cancellable: ' . $vehicle->status);
        }

        $vehicle->update(['status' => $reason]);
    }
    public function removeAdditionalService(int $service)
    {
        $vehicle = AdditionalServices::where('id', $service)
            ->where('booking_id', $this->id)
            ->first();

        if (!$service) {
            throw new \DomainException('there is no Additional Service with this ID:' . $service);
        }

        $service->update(['status' => AdditionalServices::COMPANY_CANCELED_STATUS]);
    }

    public function cancel()
    {
        try {

            $additionalServices = $this->additionalService;
            $personalVehicles = $this->vehicle;
            $passengers = $this->passenger;



            if ($additionalServices) {
                foreach ($additionalServices as $service) {
                    $this->removeAdditionalService($service->id);
                }
            }

            if ($personalVehicles) {
                foreach ($personalVehicles as $vehicle) {
                    $this->removePersonalVehicle($vehicle->id);
                }
            }

            if ($passengers) {
                foreach ($passengers as $passenger) {
                    $this->removePassenger($passenger->id);
                }
            }

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            \Log::channel('admin_errors')->debug('Try cancellation booking:' . $this->id . '. Error: ' . mb_substr($e->getTraceAsString(), 0, 1000));

            return response()->json([
                'message' => $e->getMessage(),
            ], 406);
        }
    }
}
