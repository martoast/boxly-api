@extends('emails.layout')

@section('subject', __('emails.order.status_changed.subject.' . $order->status, ['order_number' => $order->order_number], $order->user->preferred_language ?? 'es'))

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp
    
    <p>{{ __('emails.order.status_changed.hello', ['name' => $order->user->name]) }}</p>
    
    @if($order->status === \App\Models\Order::STATUS_AWAITING_PACKAGES)
        <p>{{ __('emails.order.status_changed.awaiting_packages_detail') }}</p>
    @elseif($order->status === \App\Models\Order::STATUS_PACKAGES_COMPLETE)
        <p>{{ __('emails.order.status_changed.packages_complete_detail') }}</p>
    @elseif($order->status === \App\Models\Order::STATUS_SHIPPED)
        <p>{{ __('emails.order.status_changed.shipped_detail') }}</p>
        @if($order->estimated_delivery_date)
            <p><strong>{{ __('emails.order.status_changed.estimated_delivery') }}:</strong> {{ $order->estimated_delivery_date->format($locale === 'es' ? 'd/m/Y' : 'm/d/Y') }}</p>
        @endif
    @elseif($order->status === \App\Models\Order::STATUS_DELIVERED)
        <p>{{ __('emails.order.status_changed.delivered_detail') }}</p>
    @endif
    
    @if($order->tracking_number)
        <p><strong>{{ __('emails.order.created.tracking_number') }}:</strong> {{ $order->tracking_number }}</p>
    @endif
    
    <div style="text-align: center;">
        <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" class="button">
            {{ __('emails.order.status_changed.view_details') }}
        </a>
    </div>
@endsection