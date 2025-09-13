<!DOCTYPE html>
<html lang="{{ $order->user->preferred_language ?? 'es' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('subject')</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
            background-color: #ffffff;
            -webkit-text-size-adjust: 100%;
            -ms-text-size-adjust: 100%;
        }
        .container {
            background-color: white;
            padding: 20px;
        }
        .header {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            margin-bottom: 30px;
        }
        h2 {
            font-size: 20px;
            margin-bottom: 16px;
            font-weight: 600;
            color: #333;
        }
        p {
            margin-bottom: 16px;
            color: #333;
        }
        .button {
            display: inline-block;
            padding: 12px 28px;
            background-color: #2E6BB7;
            color: white !important;
            text-decoration: none;
            font-weight: 500;
            margin: 20px 0;
            border-radius: 4px;
        }
        .button:hover {
            background-color: #245591;
        }
        .info-box {
            background-color: #f8f9fa;
            padding: 16px;
            margin: 20px 0;
            border-left: 3px solid #2E6BB7;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            font-size: 14px;
            color: #666;
        }
        .footer a {
            color: #2E6BB7;
            text-decoration: none;
        }
        strong {
            font-weight: 600;
            color: #333;
        }
        @media only screen and (max-width: 600px) {
            body {
                padding: 10px !important;
            }
            .container {
                padding: 15px !important;
            }
        }
    </style>
</head>
<body>
    <div class="container"> 
        <div class="content">
            @yield('content')
        </div>
        
        <div class="footer">
            @php
                $locale = $order->user->preferred_language ?? 'es';
            @endphp
            <p>
                {{ __('emails.footer.questions', [], $locale) }}
                <a href="mailto:contact@boxly.mx">contact@boxly.mx</a>
            </p>
            <p style="font-size: 12px;">
                {{ __('emails.footer.copyright', ['year' => date('Y'), 'app_name' => config('app.name')], $locale) }}
            </p>
        </div>
    </div>
</body>
</html>