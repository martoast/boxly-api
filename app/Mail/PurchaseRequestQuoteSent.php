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
            Hemos preparado la cotización para tu solicitud de compra asistida {{ $request->request_number }}.
        @else
            We have prepared the quote for your assisted purchase request {{ $request->request_number }}.
        @endif
    </p>

    <p style="font-size: 18px; margin: 25px 0;">
        {{ $locale === 'es' ? 'Total a Pagar:' : 'Total to Pay:' }} ${{ number_format($request->total_amount, 2) }} USD
    </p>

    <div style="text-align: center; margin: 35px 0;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
        </a>
    </div>

    <p style="color: #666; text-align: center;">
        @if($locale === 'es')
            Si tienes alguna pregunta sobre tu cotización, por favor contáctanos.
        @else
            If you have any questions about your quote, please contact us.
        @endif
    </p>
@endsection