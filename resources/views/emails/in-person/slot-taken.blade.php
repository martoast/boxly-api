@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Horario ya reservado' : 'Time already booked')

@section('content')
    <h2>{{ $locale === 'es' ? 'Ese horario ya estaba reservado' : 'That time was already booked' }}</h2>
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>
    <p>
        @if($locale === 'es')
            Otra persona pagó unos instantes antes que tú el horario del {{ $r->dateLabel('es') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }} (reserva {{ $r->reservation_number }}). Lo sentimos mucho: tus ${{ number_format($r->amount_usd, 2) }} USD van en camino de regreso a tu tarjeta. Puedes elegir otro horario cuando quieras.
        @else
            Someone else paid just before you for {{ $r->dateLabel('en') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }} (reservation {{ $r->reservation_number }}). We are very sorry: your ${{ number_format($r->amount_usd, 2) }} USD is on its way back to your card. You can pick another time whenever you like.
        @endif
    </p>
    <p>{{ $locale === 'es' ? '¿Dudas? Escríbele a tu shopper por WhatsApp' : 'Questions? Message your shopper on WhatsApp' }}: <a href="{{ $whatsapp }}">{{ $whatsapp }}</a></p>
    <div style="text-align: center; margin: 30px 0;"><a href="{{ $url }}" class="button">{{ $locale === 'es' ? 'Elegir otro horario' : 'Pick another time' }}</a></div>
@endsection
