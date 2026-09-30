@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Reserva cancelada' : 'Reservation cancelled')

@section('content')
    <h2>{{ $locale === 'es' ? 'Tu reserva fue cancelada' : 'Your reservation was cancelled' }}</h2>
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>
    <p>
        @if($locale === 'es')
            Tu reserva {{ $r->reservation_number }} ({{ $r->dateLabel('es') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }}) fue cancelada por nuestro equipo.
        @else
            Your reservation {{ $r->reservation_number }} ({{ $r->dateLabel('en') }}, {{ $r->startLabel() }} – {{ $r->endLabel() }}) was cancelled by our team.
        @endif
    </p>
    @if($r->cancel_reason)
        <div style="padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #4f46e5; border-radius: 4px; white-space: pre-wrap;">{{ $r->cancel_reason }}</div>
    @endif
    <p>{{ $locale === 'es' ? '¿Dudas? Escríbele a tu shopper por WhatsApp' : 'Questions? Message your shopper on WhatsApp' }}: <a href="{{ $whatsapp }}">{{ $whatsapp }}</a></p>
    <div style="text-align: center; margin: 30px 0;"><a href="{{ $url }}" class="button">{{ $locale === 'es' ? 'Reservar otro horario' : 'Book another time' }}</a></div>
@endsection
