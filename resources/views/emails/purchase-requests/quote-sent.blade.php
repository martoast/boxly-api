{{-- purchase-requests/quote-sent.blade.php --}}
@extends('emails.layout')

@section('subject', $locale === 'es' ? '💰 Tu cotización está lista' : '💰 Your quote is ready')

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Tu cotización final está lista!' : 'Your final quote is ready!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Tu paquete ha sido entregado y procesado. Hemos generado tu factura final.
        @else
            Your package has been delivered and processed. We have generated your final invoice.
        @endif
    </p>

    <p style="font-size: 18px; margin: 25px 0;">
        {{ $locale === 'es' ? 'Total a Pagar:' : 'Total to Pay:' }} ${{ number_format($quotedAmount, 2) }} MXN
        @if($quoteExpiresAt)
            <br><span style="font-size: 14px; color: #666;">{{ $locale === 'es' ? 'Vence el:' : 'Due by:' }} {{ $quoteExpiresAt->format('d/m/Y') }}</span>
        @endif
    </p>

    @if($quoteBreakdown)
        <p>{{ $locale === 'es' ? 'Desglose:' : 'Breakdown:' }}</p>
        @foreach($quoteBreakdown as $item)
            <p style="margin: 5px 0; padding-left: 10px; border-left: 2px solid #eee;">
                {{ $item['item'] ?? 'Item' }}: ${{ number_format($item['amount'] ?? 0, 2) }}
            </p>
        @endforeach
    @endif

    <div style="text-align: center; margin: 35px 0;">
        <a href="{{ $paymentLink }}" class="button">
            {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
        </a>
    </div>

    <p style="color: #666; text-align: center;">
        @if($locale === 'es')
            Si tienes alguna pregunta sobre tu factura, por favor contáctanos.
        @else
            If you have any questions about your invoice, please contact us.
        @endif
    </p>
@endsection