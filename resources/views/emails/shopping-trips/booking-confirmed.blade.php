{{-- shopping-trips/booking-confirmed.blade.php --}}
@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Tu visita está reservada' : 'Your trip is booked')

@section('content')
    @php app()->setLocale($locale); @endphp

    <h2>{{ $locale === 'es' ? '¡Tu visita está reservada! 🎉' : 'Your trip is booked! 🎉' }}</h2>

    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>

    <p>
        @if($locale === 'es')
            ¡Listo! Recibimos tu reserva. Apartamos tu fecha y nuestro equipo irá a comprar por ti.
        @else
            All set! We received your booking. Your date is reserved and our team will shop for you.
        @endif
    </p>

    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="padding: 6px 0; color: #666; width: 160px;">{{ $locale === 'es' ? 'Reserva' : 'Booking' }}</td>
            <td style="padding: 6px 0; font-weight: 600;">{{ $booking->booking_number }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Fecha' : 'Date' }}</td>
            <td style="padding: 6px 0; font-weight: 600;">
                {{ $trip ? ($locale === 'es' ? $trip->trip_date->isoFormat('dddd D [de] MMMM, YYYY') : $trip->trip_date->isoFormat('dddd, MMMM D, YYYY')) : '' }}
            </td>
        </tr>
        @if($trip && $trip->location)
        <tr>
            <td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Lugar' : 'Location' }}</td>
            <td style="padding: 6px 0;">{{ $trip->location }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Depósito pagado' : 'Deposit paid' }}</td>
            <td style="padding: 6px 0;">${{ number_format($deposit, 2) }} USD</td>
        </tr>
    </table>

    @if(count($stores))
        <h3 style="margin: 24px 0 8px; font-size: 16px;">{{ $locale === 'es' ? 'Tiendas que visitaremos:' : 'Stores we\'ll visit:' }}</h3>
        @foreach($stores as $name)
            <div style="margin: 0 0 8px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #2E6BB7; border-radius: 4px; font-weight: 600; color: #1a202c;">
                {{ $name }}
            </div>
        @endforeach
    @endif

    <div style="margin: 24px 0; padding: 14px 16px; background: #eef5ff; border-radius: 8px;">
        <p style="margin: 0;">
            @if($locale === 'es')
                📲 <strong>Nuestro equipo te contactará por WhatsApp</strong> para coordinar los detalles de tu visita (qué buscas, tallas, presupuesto). El depósito se aplica a tu compra final.
            @else
                📲 <strong>Our team will reach out on WhatsApp</strong> to coordinate the details of your trip (what you want, sizes, budget). The deposit applies to your final purchase.
            @endif
        </p>
    </div>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Ir a mi cuenta' : 'Go to my account' }}
        </a>
    </div>

    <p style="color: #666; font-size: 14px;">
        {{ $locale === 'es' ? '¿Dudas? Responde a este correo y te ayudamos.' : 'Questions? Just reply to this email and we\'ll help.' }}
    </p>
@endsection
