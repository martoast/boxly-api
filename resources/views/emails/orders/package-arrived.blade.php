{{-- orders/package-arrived.blade.php --}}
@extends('emails.layout')

@section('subject', __('emails.order.package_arrived.subject', ['product_name' => $item->product_name], $order->user->preferred_language ?? 'es'))

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp
    
    <h2>{{ __('emails.order.package_arrived.title') }}</h2>
    
    <p>{{ __('emails.order.package_arrived.hello', ['name' => $order->user->name]) }}</p>
    
    <p>{{ __('emails.order.package_arrived.intro') }}</p>
    
    <p>
        {{ __('emails.order.package_arrived.product') }}: {{ $item->product_name }}
        @if($item->tracking_number)
            <br>{{ __('emails.order.package_arrived.tracking_number') }}: {{ $item->tracking_number }}
        @endif
    </p>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" class="button">
            {{ __('emails.order.package_arrived.view_order') }}
        </a>
    </div>
@endsection