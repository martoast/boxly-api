@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Pago recibido - Orden ' . $order->order_number : 'Payment received - Order ' . $order->order_number)

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp
    
    <h2 style="color: #28a745;">
        {{ $locale === 'es' ? '✅ ¡Pago recibido exitosamente!' : '✅ Payment received successfully!' }}
    </h2>
    
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $order->user->name }},</p>
    
    <p>
        @if($locale === 'es')
            Hemos recibido tu pago de <strong>${{ number_format($order->amount_paid, 2) }} {{ strtoupper($order->currency) }}</strong> 
            para la orden <strong>{{ $order->order_number }}</strong>.
        @else
            We have received your payment of <strong>${{ number_format($order->amount_paid, 2) }} {{ strtoupper($order->currency) }}</strong> 
            for order <strong>{{ $order->order_number }}</strong>.
        @endif
    </p>
    
    <div style="background-color: #d4edda; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #28a745;">
        <h3 style="margin-top: 0; color: #155724;">
            {{ $locale === 'es' ? '📦 Detalles del pago' : '📦 Payment details' }}
        </h3>
        <p style="margin: 5px 0;">
            <strong>{{ $locale === 'es' ? 'Número de orden:' : 'Order number:' }}</strong> {{ $order->order_number }}<br>
            <strong>{{ $locale === 'es' ? 'Número de rastreo:' : 'Tracking number:' }}</strong> {{ $order->tracking_number }}<br>
            <strong>{{ $locale === 'es' ? 'Monto pagado:' : 'Amount paid:' }}</strong> ${{ number_format($order->amount_paid, 2) }} {{ strtoupper($order->currency) }}<br>
            <strong>{{ $locale === 'es' ? 'Fecha de pago:' : 'Payment date:' }}</strong> {{ $order->paid_at->format($locale === 'es' ? 'd/m/Y H:i' : 'm/d/Y H:i') }}<br>
            @if($order->stripe_invoice_id)
            <strong>{{ $locale === 'es' ? 'ID de factura:' : 'Invoice ID:' }}</strong> {{ $order->stripe_invoice_id }}
            @endif
        </p>
    </div>
    
    <div style="background-color: #e7f5ff; padding: 20px; margin: 20px 0; border-radius: 8px;">
        <h3 style="margin-top: 0; color: #0c5460;">
            {{ $locale === 'es' ? '🚚 ¿Qué sigue?' : '🚚 What\'s next?' }}
        </h3>
        <p style="margin: 10px 0;">
            @if($locale === 'es')
                Tu paquete consolidado será preparado y enviado a tu dirección en México dentro de las próximas 24-48 horas hábiles. 
                Te notificaremos cuando tu paquete salga de nuestro almacén con la información de rastreo actualizada.
            @else
                Your consolidated package will be prepared and shipped to your address in Mexico within the next 24-48 business hours. 
                We'll notify you when your package leaves our warehouse with updated tracking information.
            @endif
        </p>
    </div>
    
    <div style="background-color: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px;">
        <h4 style="margin-top: 0;">{{ $locale === 'es' ? '📍 Dirección de entrega' : '📍 Delivery address' }}</h4>
        <p style="margin: 5px 0; font-family: monospace; background: white; padding: 10px; border-radius: 4px;">
            {{ $order->delivery_address['street'] }} {{ $order->delivery_address['exterior_number'] }}<br>
            @if($order->delivery_address['interior_number'])
                Interior {{ $order->delivery_address['interior_number'] }}<br>
            @endif
            {{ $order->delivery_address['colonia'] }}<br>
            {{ $order->delivery_address['municipio'] }}, {{ $order->delivery_address['estado'] }}<br>
            C.P. {{ $order->delivery_address['postal_code'] }}<br>
            @if($order->delivery_address['referencias'])
                <small style="color: #6c757d;">{{ $locale === 'es' ? 'Referencias:' : 'References:' }} {{ $order->delivery_address['referencias'] }}</small>
            @endif
        </p>
    </div>
    
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" style="display: inline-block; padding: 15px 40px; background-color: #2E6BB7; color: white; text-decoration: none; font-weight: bold; font-size: 16px; border-radius: 5px;">
            {{ $locale === 'es' ? '📋 Ver Mi Orden' : '📋 View My Order' }}
        </a>
    </div>
    
    <hr style="margin: 30px 0; border: none; border-top: 1px solid #dee2e6;">
    
    <p style="color: #6c757d; font-size: 14px;">
        @if($locale === 'es')
            Si tienes alguna pregunta sobre tu envío, no dudes en contactarnos respondiendo a este correo o llamando al +52 (664) 123-4567.
        @else
            If you have any questions about your shipment, please don't hesitate to contact us by replying to this email or calling +52 (664) 123-4567.
        @endif
    </p>
    
    <p style="color: #6c757d; font-size: 14px; text-align: center;">
        <strong>{{ $locale === 'es' ? '¡Gracias por tu preferencia!' : 'Thank you for your business!' }}</strong>
    </p>
@endsection