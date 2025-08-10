@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Orden creada - ' . $order->order_number : 'Order created - ' . $order->order_number)

@section('content')
@php
$locale = $order->user->preferred_language ?? 'es';
app()->setLocale($locale);
@endphp

<h2>{{ $locale === 'es' ? '¡Tu orden ha sido creada!' : 'Your order has been created!' }}</h2>

<p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $order->user->name }},</p>

<p>
    @if($locale === 'es')
    Tu orden <strong>{{ $order->order_number }}</strong> ha sido creada exitosamente.
    Ahora puedes comenzar a enviar tus paquetes a nuestra dirección en USA.
    @else
    Your order <strong>{{ $order->order_number }}</strong> has been created successfully.
    You can now start sending your packages to our USA address.
    @endif
</p>

<div style="background-color: #e7f5ff; padding: 20px; margin: 20px 0; border-radius: 8px;">
    <h3 style="margin-top: 0; color: #0c5460;">
        {{ $locale === 'es' ? '📦 Información de tu orden' : '📦 Your order information' }}
    </h3>
    <p style="margin: 5px 0;">
        <strong>{{ $locale === 'es' ? 'Número de orden:' : 'Order number:' }}</strong> {{ $order->order_number }}<br>
        <strong>{{ $locale === 'es' ? 'Número de rastreo:' : 'Tracking number:' }}</strong> {{ $order->tracking_number }}<br>
        <strong>{{ $locale === 'es' ? 'Entrega rural:' : 'Rural delivery:' }}</strong> {{ $order->is_rural ? ($locale === 'es' ? 'Sí' : 'Yes') : 'No' }}
    </p>
</div>

<div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #bee5eb;">
    <p style="margin: 5px 0;">
        <strong>{{ $locale === 'es' ? '📍 Dirección de entrega en México:' : '📍 Delivery address in Mexico:' }}</strong>
    </p>
    <p style="margin: 10px 0; padding-left: 10px; font-family: monospace; background: white; padding: 10px; border-radius: 4px;">
        {{ $order->delivery_address['street'] }} {{ $order->delivery_address['exterior_number'] }}
        @if(!empty($order->delivery_address['interior_number']))
        Int. {{ $order->delivery_address['interior_number'] }}
        @endif
        <br>
        {{ $order->delivery_address['colonia'] }}<br>
        {{ $order->delivery_address['municipio'] }}, {{ $order->delivery_address['estado'] }}<br>
        C.P. {{ $order->delivery_address['postal_code'] }}
        @if(!empty($order->delivery_address['referencias']))
        <br>
        <small style="color: #0c5460;">
            <em>{{ $locale === 'es' ? 'Referencias:' : 'References:' }} {{ $order->delivery_address['referencias'] }}</em>
        </small>
        @endif
    </p>
    <p style="margin: 10px 0 0 0; font-size: 13px; color: #0c5460;">
        <em>
            @if($locale === 'es')
            ⚠️ Por favor verifica que tu dirección esté correcta. Si necesitas cambiarla, puedes hacerlo desde tu cuenta antes de que lleguen los paquetes.
            @else
            ⚠️ Please verify that your address is correct. If you need to change it, you can do so from your account before packages arrive.
            @endif
        </em>
    </p>
</div>

<div style="background-color: #fff3cd; padding: 20px; margin: 20px 0; border-radius: 8px;">
    <h3 style="margin-top: 0; color: #856404;">
        {{ $locale === 'es' ? '🏢 Dirección de envío en USA' : '🏢 USA Shipping Address' }}
    </h3>
    <p style="margin: 10px 0; font-family: monospace; background: white; padding: 10px; border-radius: 4px;">
        <strong>ECTJ {{ $order->user->name }} ({{ $order->user->id }})</strong><br>
        2220 Otay Lakes Rd.<br>
        Suite 502 #95<br>
        Chula Vista, CA 91915<br>
        United States<br>
        Phone: +1 (619) 559-1920
    </p>
    <p style="margin: 0; color: #856404; font-size: 14px;">
        <strong>{{ $locale === 'es' ? '⚠️ IMPORTANTE:' : '⚠️ IMPORTANT:' }}</strong>
        @if($locale === 'es')
        Usa <strong>ECTJ {{ $order->user->name }} ({{ $order->user->id }})</strong> como nombre del destinatario al enviar tus paquetes.
        @else
        Use <strong>ECTJ {{ $order->user->name }} ({{ $order->user->id }})</strong> as the recipient name when shipping your packages.
        @endif
    </p>
</div>

<div style="background-color: #f8f9fa; padding: 20px; margin: 20px 0; border-radius: 8px;">
    <h3 style="margin-top: 0;">{{ $locale === 'es' ? '📋 Próximos pasos' : '📋 Next steps' }}</h3>
    <ol style="margin: 0; padding-left: 20px;">
        @if($locale === 'es')
        <li>Compra en tus tiendas en línea favoritas de USA</li>
        <li>Usa el nombre y dirección de arriba al hacer tus compras online</li>
        <li>Agrega cada artículo a tu orden con su número de rastreo</li>
        <li>Una vez que todos tus paquetes lleguen, te enviaremos una cotización</li>
        <li>Realiza el pago y nosotros consolidaremos y enviaremos tu paquete a México</li>
        @else
        <li>Shop from your favorite US online stores</li>
        <li>Use the address above as your shipping address</li>
        <li>Add each item to your order with its tracking number</li>
        <li>Once all your packages arrive, we'll send you a quote</li>
        <li>Make the payment and we'll consolidate and ship your package to Mexico</li>
        @endif
    </ol>
</div>

<div style="text-align: center; margin: 30px 0;">
    <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}/add-items" style="display: inline-block; padding: 15px 40px; background-color: #2E6BB7; color: white; text-decoration: none; font-weight: bold; font-size: 16px; border-radius: 5px;">
        {{ $locale === 'es' ? '➕ Agregar Artículos' : '➕ Add Items' }}
    </a>
</div>

<hr style="margin: 30px 0; border: none; border-top: 1px solid #dee2e6;">

<p style="color: #6c757d; font-size: 14px;">
    <strong>{{ $locale === 'es' ? '💡 Nota importante:' : '💡 Important note:' }}</strong>
    @if($locale === 'es')
    No se realizará ningún cargo hasta que recibamos todos tus paquetes y te enviemos la cotización final.
    El pago se realizará después de que apruebes la cotización.
    @else
    No charges will be made until we receive all your packages and send you the final quote.
    Payment will be made after you approve the quote.
    @endif
</p>
@endsection