@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Reserva confirmada' : 'Reservation confirmed')

@section('content')
    <h2>{{ $locale === 'es' ? '¡Reservaste tu horario!' : 'Your time is booked!' }}</h2>
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr><td style="padding: 6px 0; color: #666; width: 160px;">{{ $locale === 'es' ? 'Fecha' : 'Date' }}</td><td style="padding: 6px 0; font-weight: 600;">{{ $r->dateLabel($locale) }}</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Horario' : 'Time' }}</td><td style="padding: 6px 0; font-weight: 600;">{{ $r->startLabel() }} – {{ $r->endLabel() }} ({{ $r->hours_reserved }} h) · {{ $locale === 'es' ? 'hora de California' : 'California time' }}</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Lugar' : 'Place' }}</td><td style="padding: 6px 0;">San Diego, California</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Reserva' : 'Reservation' }}</td><td style="padding: 6px 0;">{{ $r->reservation_number }}</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Pagado hoy' : 'Paid today' }}</td><td style="padding: 6px 0;">${{ number_format($r->amount_usd, 2) }} USD</td></tr>
    </table>
    <p>
        @if($locale === 'es')
            <strong>Así se cobra:</strong> $30 USD por hora + 10% del total que se compre. La primera hora ya está pagada; al terminar te enviaremos el cobro final con las horas restantes y el 10%.
        @else
            <strong>How billing works:</strong> $30 USD per hour + 10% of the total spent. The first hour is already paid; when we finish we will send the final charge for the remaining hours and the 10%.
        @endif
    </p>
    <p>{{ $locale === 'es' ? '¿Dudas? Escríbele a tu shopper por WhatsApp' : 'Questions? Message your shopper on WhatsApp' }}: <a href="{{ $whatsapp }}">{{ $whatsapp }}</a></p>
    <div style="text-align: center; margin: 30px 0;"><a href="{{ $url }}" class="button">{{ $locale === 'es' ? 'Ver mi reserva' : 'View my reservation' }}</a></div>
@endsection
