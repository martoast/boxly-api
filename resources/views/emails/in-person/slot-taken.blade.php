@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Horario ya reservado' : 'Time already booked')

@section('content')
    <h2>{{ $locale === 'es' ? 'Ese horario ya estaba reservado' : 'That time was already booked' }}</h2>
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>
    @php
        $hourGone = $r->slot_taken_reason === 'hour_unavailable';
        $refunded = $r->refunded_at !== null;
        $amount = number_format($r->amount_usd, 2);
    @endphp
    <p>
        @if($locale === 'es')
            @if($hourGone)
                Ese horario ya no está disponible: {{ $r->dateLabel('es') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }} (reserva {{ $r->reservation_number }}). Lo sentimos mucho.
            @else
                Otra persona pagó unos instantes antes que tú el horario del {{ $r->dateLabel('es') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }} (reserva {{ $r->reservation_number }}). Lo sentimos mucho.
            @endif
        @else
            @if($hourGone)
                That time is no longer available: {{ $r->dateLabel('en') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }} (reservation {{ $r->reservation_number }}). We are very sorry.
            @else
                Someone else paid just before you for {{ $r->dateLabel('en') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }} (reservation {{ $r->reservation_number }}). We are very sorry.
            @endif
        @endif
    </p>
    <p>
        @if($locale === 'es')
            @if($refunded)
                Ya te reembolsamos tus ${{ $amount }} USD; van en camino de regreso a tu tarjeta. Puedes elegir otro horario cuando quieras.
            @else
                Tu reembolso de ${{ $amount }} USD está en proceso; te contactaremos por WhatsApp para confirmártelo. Puedes elegir otro horario cuando quieras.
            @endif
        @else
            @if($refunded)
                We refunded your ${{ $amount }} USD; it is on its way back to your card. You can pick another time whenever you like.
            @else
                Your ${{ $amount }} USD refund is being processed; we will contact you on WhatsApp to confirm it. You can pick another time whenever you like.
            @endif
        @endif
    </p>
    <p>{{ $locale === 'es' ? '¿Dudas? Escríbele a tu shopper por WhatsApp' : 'Questions? Message your shopper on WhatsApp' }}: <a href="{{ $whatsapp }}">{{ $whatsapp }}</a></p>
    <div style="text-align: center; margin: 30px 0;"><a href="{{ $url }}" class="button">{{ $locale === 'es' ? 'Elegir otro horario' : 'Pick another time' }}</a></div>
@endsection
