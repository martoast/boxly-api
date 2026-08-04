{{-- drop-off-receipt.blade.php --}}
@extends('emails.layout')

@section('subject', __('emails.drop_off.receipt.subject', ['receipt_number' => $receipt->receipt_number], $user->preferred_language ?? 'es'))

@section('content')
    @php
        $locale = $user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>{{ __('emails.drop_off.receipt.title') }}</h2>

    <p>{{ __('emails.drop_off.receipt.hello', ['name' => $user->name]) }}</p>

    <p>{{ __('emails.drop_off.receipt.intro') }}</p>

    <p>
        {{ __('emails.drop_off.receipt.receipt_number') }}: <strong>{{ $receipt->receipt_number }}</strong>
        <br>{{ __('emails.drop_off.receipt.date') }}: {{ $receipt->dropped_off_at->format('d/m/Y') }}
    </p>

    <p>
        <strong>{{ __('emails.drop_off.receipt.contents') }}</strong>
        <br>{!! nl2br(e($receipt->description)) !!}
    </p>

    @if(count($images) > 0)
        <p><strong>{{ __('emails.drop_off.receipt.photos') }}</strong></p>
        @foreach($images as $image)
            <img src="{{ $image['url'] }}" alt="" style="width: 100%; max-width: 280px; border-radius: 6px; margin: 0 0 12px 0; display: block;">
        @endforeach
    @endif

    <p>{{ __('emails.drop_off.receipt.closing') }}</p>
@endsection
