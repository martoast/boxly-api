@extends('emails.layout')

@section('subject', 'Campaign')

@section('content')
    {!! nl2br(preg_replace('/(https?:\/\/[^\s<]+)/', '<a href="$1" style="color: #2E6BB7; text-decoration: underline;">$1</a>', e($body))) !!}

    @if($linkUrl)
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $linkUrl }}" class="button">
                {{ $linkText }}
            </a>
        </div>
    @endif

    <img src="{{ $pixelUrl }}" width="1" height="1" alt="" style="display:none;" />
@endsection
