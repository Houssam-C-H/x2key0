<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $store['name'] }}</title>
</head>
<body style="margin:0;padding:24px;background:#f4f5fa;font-family:Arial,Helvetica,sans-serif;color:#12121a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:24px;">
                <p style="margin:0 0 4px;font-size:12px;color:#6b6f80;">X2KEY · {{ __('marketing.mail.from', ['store' => $store['name']], $locale) }}</p>
                <h1 style="margin:0 0 16px;font-size:20px;">{{ __('marketing.mail.heading.'.$type, ['store' => $store['name']], $locale) }}</h1>
                <p style="margin:0 0 16px;">{{ __('marketing.mail.hello', ['name' => $name], $locale) }}</p>

                @if ($coupon)
                    <div style="margin:0 0 16px;padding:12px;border:2px dashed #5b3df5;border-radius:8px;text-align:center;">
                        <strong style="font-size:18px;color:#5b3df5;">{{ $coupon['label'] }}</strong>
                        <div style="font-family:monospace;font-size:16px;margin-top:4px;">{{ $coupon['code'] }}</div>
                        <div style="font-size:12px;color:#6b6f80;margin-top:4px;">{{ __('marketing.mail.coupon', ['date' => $coupon['validTo'] ?? '—'], $locale) }}</div>
                    </div>
                @endif

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                    @foreach ($offers as $offer)
                        <tr>
                            <td style="padding:8px 0;border-bottom:1px solid #eee;">
                                <a href="{{ $offer['url'] }}" style="color:#12121a;text-decoration:none;font-weight:bold;">{{ $offer['title'] }}</a>
                            </td>
                            <td style="padding:8px 0;border-bottom:1px solid #eee;text-align:end;white-space:nowrap;">USD {{ $offer['price'] }}</td>
                        </tr>
                    @endforeach
                </table>

                <p style="margin:20px 0 0;">
                    <a href="{{ $store['url'] }}" style="display:inline-block;background:#5b3df5;color:#ffffff;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:bold;">{{ __('marketing.mail.visit', [], $locale) }}</a>
                </p>
                <p style="margin:20px 0 0;font-size:12px;color:#6b6f80;">{{ __('marketing.mail.safety', [], $locale) }}</p>
                <p style="margin:8px 0 0;font-size:12px;color:#6b6f80;">
                    {{ __('marketing.mail.unsubscribe', [], $locale) }} <a href="{{ $settingsUrl }}" style="color:#5b3df5;">{{ $settingsUrl }}</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
