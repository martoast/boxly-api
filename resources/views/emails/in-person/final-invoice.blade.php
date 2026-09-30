@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Tu factura final' : 'Your final invoice')

@section('content')
    <h2>{{ $locale === 'es' ? 'Tu factura final de compra personal' : 'Your personal shopping final invoice' }}</h2>
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>
    <p>
        @if($locale === 'es')
            Terminamos tu reserva {{ $r->reservation_number }} ({{ $r->dateLabel('es') }}). Este es el desglose:
        @else
            We finished your reservation {{ $r->reservation_number }} ({{ $r->dateLabel('en') }}). Here is the breakdown:
        @endif
    </p>
    <table style="width: 100%; border-collapse: collapse;">
        <tr><td>{{ $locale === 'es' ? 'Horas trabajadas' : 'Hours worked' }} ({{ $final['hours_worked'] + 0 }} h)</td><td style="text-align: right;">${{ number_format($final['hours_fee_usd'], 2) }}</td></tr>
        <tr><td>{{ $locale === 'es' ? 'Comisión' : 'Commission' }} ({{ $final['commission_percent'] + 0 }}% {{ $locale === 'es' ? 'de' : 'of' }} ${{ number_format($final['amount_spent_usd'], 2) }})</td><td style="text-align: right;">${{ number_format($final['commission_usd'], 2) }}</td></tr>
        <tr><td>{{ $locale === 'es' ? 'Reserva ya pagada' : 'Reservation already paid' }}</td><td style="text-align: right;">-${{ number_format($final['credit_usd'], 2) }}</td></tr>
        <tr><td><strong>Total</strong></td><td style="text-align: right;"><strong>${{ number_format($final['total_usd'], 2) }} USD</strong></td></tr>
    </table>
    <div style="text-align: center; margin: 30px 0;"><a href="{{ $url }}" class="button">{{ $locale === 'es' ? 'Pagar factura' : 'Pay invoice' }}</a></div>
    <p>{{ $locale === 'es' ? '¿Dudas? Escríbele a tu shopper por WhatsApp' : 'Questions? Message your shopper on WhatsApp' }}: <a href="{{ $whatsapp }}">{{ $whatsapp }}</a></p>
@endsection
