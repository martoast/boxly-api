@extends('emails.layout')
@section('subject', $subject)
@section('content')
@php
$locale = $order->user->preferred_language ?? 'es';
app()->setLocale($locale);
@endphp

<h1 style="color: #333; font-size: 24px; margin-bottom: 20px;">
    @switch($order->status)
    @case('collecting')
    {{ $locale === 'es' ? '📦 Tu orden está lista para recibir productos' : '📦 Your order is ready for products' }}
    @break
    @case('awaiting_packages')
    {{ $locale === 'es' ? '⏳ Esperando tus paquetes' : '⏳ Awaiting your packages' }}
    @break
    @case('packages_complete')
    {{ $locale === 'es' ? '✅ Hemos recibido todos tus paquetes' : '✅ We have received all your packages' }}
    @break
    @case('processing')
    {{ $locale === 'es' ? '⚙️ Procesando tu orden' : '⚙️ Processing your order' }}
    @break
    @case('awaiting_payment')
    {{ $locale === 'es' ? '🧾 Tu factura está lista' : '🧾 Your invoice is ready' }}
    @break
    @case('paid')
    {{ $locale === 'es' ? '✅ Pago recibido' : '✅ Payment received' }}
    @break
    @case('shipped')
    {{ $locale === 'es' ? '🛫 Tu paquete está en camino' : '🛫 Your package is on the way' }}
    @break
    @case('delivered')
    {{ $locale === 'es' ? '🎉 Tu paquete ha sido entregado' : '🎉 Your package has been delivered' }}
    @break
    @case('cancelled')
    {{ $locale === 'es' ? '❌ Orden cancelada' : '❌ Order cancelled' }}
    @break
    @endswitch
</h1>

<p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $order->user->name }},</p>

@switch($order->status)
@case('collecting')
@if($locale === 'es')
<p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido reabierta y está lista para agregar más productos.</p>
<p>Puedes continuar agregando los artículos que compraste antes de enviar la orden nuevamente.</p>
@else
<p>Your order <strong>{{ $order->tracking_number }}</strong> has been reopened and is ready to add more products.</p>
<p>You can continue adding items you've purchased before submitting the order again.</p>
@endif
@break

@case('awaiting_packages')
@if($locale === 'es')
<p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido creada exitosamente.</p>
<p>Estamos esperando que lleguen tus paquete(s) a nuestro almacén en USA.</p>
@else
<p>Your order <strong>{{ $order->tracking_number }}</strong> has been created successfully.</p>
<p>We're waiting for your package(s) to arrive at our USA warehouse.</p>
@endif
@break

@case('packages_complete')
@if($locale === 'es')
<p><strong>¡Excelentes noticias!</strong> Hemos recibido todos los paquetes de tu orden <strong>{{ $order->tracking_number }}</strong> en nuestro almacén.</p>
<p>Ahora nuestro equipo comenzará a procesar tu orden para enviarla a México.</p>
@else
<p><strong>Great news!</strong> We have received all packages for your order <strong>{{ $order->tracking_number }}</strong> at our warehouse.</p>
<p>Our team will now begin processing your order to ship it to Mexico.</p>
@endif
@break

@case('processing')
@if($locale === 'es')
<p>Tu orden <strong>{{ $order->tracking_number }}</strong> está siendo procesada por nuestro equipo.</p>
<p>Estamos consolidando tus artículos y preparando todo para el envío. Te notificaremos tan pronto como tu paquete esté en camino.</p>
@else
<p>Your order <strong>{{ $order->tracking_number }}</strong> is being processed by our team.</p>
<p>We are consolidating your items and preparing everything for shipment. We will notify you as soon as your package is on its way.</p>
@endif
@break

@case('awaiting_payment')
@if($locale === 'es')
<p>Tu paquete ha sido entregado exitosamente. Hemos preparado la factura para tu orden <strong>{{ $order->tracking_number }}</strong>.</p>
<p><strong>Total a pagar: ${{ number_format($order->quoted_amount, 2) }} MXN</strong></p>
@if($order->quote_expires_at)
<p>⏰ Por favor, realiza el pago antes del {{ $order->quote_expires_at->format('d/m/Y') }}.</p>
@endif
@else
<p>Your package has been successfully delivered. We have prepared the invoice for your order <strong>{{ $order->tracking_number }}</strong>.</p>
<p><strong>Total to pay: ${{ number_format($order->quoted_amount, 2) }} MXN</strong></p>
@if($order->quote_expires_at)
<p>⏰ Please make the payment before {{ $order->quote_expires_at->format('m/d/Y') }}.</p>
@endif
@endif

@if($order->payment_link)
<div style="text-align: center; margin: 30px 0;">
    <a href="{{ $order->payment_link }}" style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
        {{ $locale === 'es' ? 'Pagar Factura' : 'Pay Invoice' }}
    </a>
</div>
@endif
@break

@case('paid')
@if($locale === 'es')
<p><strong>¡Gracias por tu pago!</strong></p>
<p>Hemos recibido tu pago de <strong>${{ number_format($order->amount_paid, 2) }} MXN</strong> para la orden <strong>{{ $order->tracking_number }}</strong>.</p>
<p>Agradecemos tu confianza en Boxly. ¡Esperamos verte pronto!</p>
@else
<p><strong>Thank you for your payment!</strong></p>
<p>We've received your payment of <strong>${{ number_format($order->amount_paid, 2) }} MXN</strong> for order <strong>{{ $order->tracking_number }}</strong>.</p>
<p>We appreciate you trusting Boxly. We hope to see you again soon!</p>
@endif
@break

@case('shipped')
@if($locale === 'es')
<p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido enviada.</p>
@if($order->dhl_waybill_number)
<p>Información de envío:</p>
<p style="margin-left: 20px;">
    Número de guía: <strong>{{ $order->dhl_waybill_number }}</strong><br>
    @if($order->estimated_delivery_date)
    Fecha estimada de entrega: {{ $order->estimated_delivery_date->format('d/m/Y') }}
    @endif
</p>
@endif
<p>Una vez que tu paquete sea entregado, te enviaremos la factura para el pago.</p>
@else
<p>Your order <strong>{{ $order->tracking_number }}</strong> has been shipped.</p>
@if($order->dhl_waybill_number)
<p>Shipping information:</p>
<p style="margin-left: 20px;">
    Waybill number: <strong>{{ $order->dhl_waybill_number }}</strong><br>
    @if($order->estimated_delivery_date)
    Estimated delivery date: {{ $order->estimated_delivery_date->format('m/d/Y') }}
    @endif
</p>
@endif
<p>Once your package is delivered, we will send you the invoice for payment.</p>
@endif
@break

@case('delivered')
@if($locale === 'es')
<p><strong>¡Tu paquete ha sido entregado exitosamente!</strong> 🎉</p>
<p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido entregada en la dirección registrada.</p>
<p>En breve, recibirás un correo electrónico con la factura final y el enlace para realizar tu pago.</p>
@else
<p><strong>Your package has been successfully delivered!</strong> 🎉</p>
<p>Your order <strong>{{ $order->tracking_number }}</strong> has been delivered to the registered address.</p>
<p>Shortly, you will receive an email with the final invoice and a link to make your payment.</p>
@endif
@break

@case('cancelled')
@if($locale === 'es')
<p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido cancelada.</p>
@if($order->notes)
<p><strong>Razón:</strong> {{ $order->notes }}</p>
@endif
<p>Si tienes alguna pregunta, por favor contáctanos.</p>
@else
<p>Your order <strong>{{ $order->tracking_number }}</strong> has been cancelled.</p>
@if($order->notes)
<p><strong>Reason:</strong> {{ $order->notes }}</p>
@endif
<p>If you have any questions, please contact us.</p>
@endif
@break
@endswitch

@if($order->status !== 'awaiting_payment')
<div style="text-align: center; margin: 30px 0;">
    <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
        {{ $locale === 'es' ? 'Ver Detalles de la Orden' : 'View Order Details' }}
    </a>
</div>
@endif

@if($locale === 'es')
<p style="color: #666; font-size: 14px; margin-top: 30px;">
    Si tienes alguna pregunta contáctanos en WhatsApp: +1 619 559-1920
</p>
@else
<p style="color: #666; font-size: 14px; margin-top: 30px;">
    If you have any questions, contact us on WhatsApp: +1 619 559-1920
</p>
@endif
@endsection