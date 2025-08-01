@extends('emails.layout')

@section('subject', __('emails.order.created.subject', ['order_number' => $order->order_number], $order->user->preferred_language ?? 'es'))

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp
    
    <h2>{{ __('emails.order.created.greeting') }}</h2>
    
    <p>{{ __('emails.order.created.hello', ['name' => $order->user->name]) }}</p>
    <p>{{ __('emails.order.created.intro') }}</p>
    
    <p>
        <strong>{{ __('emails.order.created.tracking_number') }}:</strong> {{ $order->tracking_number }}
    </p>
    
    <div style="text-align: center;">
        <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}/add-items" class="button">
            {{ __('emails.order.created.view_order') }}
        </a>
    </div>
@endsection