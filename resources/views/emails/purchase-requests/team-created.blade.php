@extends('emails.layout')

@section('subject', 'Nueva solicitud de compra')

@section('content')
    @php $isInPerson = $request->source === \App\Models\PurchaseRequest::SOURCE_IN_PERSON; @endphp

    <h2 style="margin: 0 0 8px;">
        {{ $isInPerson ? '🛍️' : '📥' }}
        Nueva solicitud — {{ $request->request_number }}
        @if($isInPerson)
            <span style="font-size: 13px; color: #6366f1; background: #eef2ff; padding: 3px 8px; border-radius: 4px; vertical-align: middle;">EN PERSONA</span>
        @endif
    </h2>
    <p style="color: #666; margin: 0 0 20px;">
        @if($isInPerson)
            Un cliente agendó una compra en persona en Las Américas. Planéala antes de la fecha de la visita.
        @else
            Un cliente acaba de crear una solicitud de compra asistida. Revísala y mándale una cotización.
        @endif
    </p>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="padding: 6px 0; color: #666; width: 140px;">Cliente</td>
            <td style="padding: 6px 0; font-weight: 600;">{{ $request->user->name }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">Email</td>
            <td style="padding: 6px 0;">{{ $request->user->email }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">Items</td>
            <td style="padding: 6px 0;">{{ $request->items->count() }}</td>
        </tr>
        @if($isInPerson && $request->shoppingTrip)
        <tr>
            <td style="padding: 6px 0; color: #666;">Visita</td>
            <td style="padding: 6px 0;">{{ $request->shoppingTrip->location }} — {{ $request->shoppingTrip->trip_date->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">Presupuesto</td>
            <td style="padding: 6px 0;">${{ number_format($request->minimum_budget_usd ?? 0, 2) }} USD</td>
        </tr>
        @if($request->customer_notes)
        <tr>
            <td style="padding: 6px 0; color: #666; vertical-align: top;">Notas del cliente</td>
            <td style="padding: 6px 0; white-space: pre-wrap;">{{ $request->customer_notes }}</td>
        </tr>
        @endif
        @else
        <tr>
            <td style="padding: 6px 0; color: #666;">Moneda</td>
            <td style="padding: 6px 0;">{{ strtoupper($request->currency ?? 'usd') }}</td>
        </tr>
        @endif
    </table>

    @if($isInPerson)
        <h3 style="margin: 16px 0 8px; font-size: 14px;">Tiendas y categorías</h3>
        @foreach($request->inPersonStoreBreakdown() as $row)
            <div style="margin: 0 0 8px; padding: 8px 10px; background: #f8f9fa; border-left: 3px solid #f59e0b; border-radius: 4px;">
                <div style="font-weight: 600; font-size: 13px;">{{ $row['store']->name }}</div>
                <div style="font-size: 12px; color: #666; margin-top: 2px;">
                    @if(count($row['category_names']) > 0)
                        {{ implode(' · ', $row['category_names']) }}
                    @else
                        <em>Cualquier categoría</em>
                    @endif
                </div>
            </div>
        @endforeach
    @endif

    <h3 style="margin: 20px 0 8px; font-size: 16px;">Productos solicitados:</h3>

    @foreach($request->items as $item)
        <div style="margin: 0 0 12px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #f59e0b; border-radius: 4px;">
            <div style="font-weight: 600; margin-bottom: 4px;">
                {{ $item->product_name }}
                <span style="color: #666; font-weight: normal;">× {{ $item->quantity }}</span>
            </div>
            <div style="color: #666; font-size: 13px;">
                Precio estimado: ${{ number_format($item->price, 2) }}
            </div>
            @if($item->notes)
                <div style="color: #666; font-size: 13px; font-style: italic; margin-top: 4px;">"{{ $item->notes }}"</div>
            @endif
            @if($item->product_url)
                <div style="font-size: 13px; margin-top: 6px;">
                    <a href="{{ $item->product_url }}" style="color: #4f46e5; text-decoration: none;">→ Ver en tienda fuente</a>
                </div>
            @endif
        </div>
    @endforeach

    <div style="margin-top: 24px;">
        <a href="{{ $admin_url }}" style="display: inline-block; padding: 12px 24px; background: #4f46e5; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600;">
            Revisar y cotizar →
        </a>
    </div>

    <p style="color: #999; font-size: 12px; margin-top: 30px;">
        Una vez revises la solicitud, mándale una cotización al cliente desde el detalle. Cuando paguen, recibirás otro correo para que vayas a comprar los productos.
    </p>
@endsection
