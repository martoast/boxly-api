{{-- purchase-requests/in-person-scheduled.blade.php --}}
@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Visita agendada' : 'Trip scheduled')

@section('content')
    @php app()->setLocale($locale); @endphp

    <h2>{{ $locale === 'es' ? '¡Tu visita al outlet está agendada!' : 'Your outlet trip is scheduled!' }}</h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Gracias por agendar tu compra en persona con Boxly. Nuestro equipo irá a <strong>{{ $trip->location }}</strong> el <strong>{{ $trip->trip_date->isoFormat('dddd D [de] MMMM') }}</strong> y haremos las compras por ti.
        @else
            Thanks for booking your in-person shopping with Boxly. Our team will visit <strong>{{ $trip->location }}</strong> on <strong>{{ $trip->trip_date->isoFormat('dddd, MMMM D') }}</strong> and shop for you.
        @endif
    </p>

    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="padding: 6px 0; color: #666; width: 160px;">{{ $locale === 'es' ? 'Solicitud' : 'Request' }}</td>
            <td style="padding: 6px 0; font-weight: 600;">{{ $request->request_number }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">{{ $locale === 'es' ? 'Presupuesto mínimo' : 'Minimum budget' }}</td>
            <td style="padding: 6px 0;">${{ number_format($request->minimum_budget_usd, 2) }} USD</td>
        </tr>
    </table>

    <h3 style="margin: 24px 0 8px; font-size: 16px;">{{ $locale === 'es' ? 'Tiendas a visitar y categorías:' : 'Stores to visit and categories:' }}</h3>
    @foreach($storeBreakdown as $row)
        <div style="margin: 0 0 10px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #4f46e5; border-radius: 4px;">
            <div style="font-weight: 600; color: #1a202c;">{{ $row['store']->name }}</div>
            @if(count($row['category_names']) > 0)
                <div style="margin-top: 4px; font-size: 13px; color: #4f46e5;">
                    {{ implode(' · ', $row['category_names']) }}
                </div>
            @else
                <div style="margin-top: 4px; font-size: 12px; color: #999; font-style: italic;">
                    {{ $locale === 'es' ? 'Cualquier categoría' : 'Any category' }}
                </div>
            @endif
        </div>
    @endforeach

    @if($request->items->isNotEmpty())
        <h3 style="margin: 24px 0 8px; font-size: 16px;">{{ $locale === 'es' ? 'Tu lista de deseos:' : 'Your wishlist:' }}</h3>
        @foreach($request->items as $item)
            <div style="margin: 0 0 10px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #6366f1; border-radius: 4px;">
                <div style="font-weight: 600;">{{ $item->product_name }} <span style="color: #666; font-weight: normal;">× {{ $item->quantity }}</span></div>
                @if($item->notes)
                    <div style="color: #666; font-size: 13px; font-style: italic; margin-top: 4px;">"{{ $item->notes }}"</div>
                @endif
                @if($item->product_url)
                    <div style="font-size: 13px; margin-top: 6px;">
                        <a href="{{ $item->product_url }}" style="color: #4f46e5; text-decoration: none;">→ {{ $locale === 'es' ? 'Referencia' : 'Reference' }}</a>
                    </div>
                @endif
            </div>
        @endforeach
    @endif

    @if($request->customer_notes)
        <h3 style="margin: 24px 0 8px; font-size: 16px;">{{ $locale === 'es' ? 'Tus notas:' : 'Your notes:' }}</h3>
        <div style="padding: 10px 12px; background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 4px; white-space: pre-wrap;">{{ $request->customer_notes }}</div>
    @endif

    <p style="margin-top: 24px;">
        @if($locale === 'es')
            <strong>No hay pago en este momento.</strong> Después de la visita, te enviaremos una cotización con los productos que conseguimos, el costo total y nuestra cuota de servicio. Apenas la pagues, mandamos tu paquete al almacén para reenviarlo a México.
        @else
            <strong>No payment is due right now.</strong> After the trip we'll send you a quote with what we found, the total cost, and our service fee. Once you pay, your package ships to the warehouse for forwarding to Mexico.
        @endif
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Ver mi solicitud' : 'View my request' }}
        </a>
    </div>
@endsection
