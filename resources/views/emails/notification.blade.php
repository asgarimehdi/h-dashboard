<!DOCTYPE html>
<html dir="rtl" lang="fa">
<head><meta charset="utf-8"></head>
<body style="font-family: Vazirmatn, Tahoma, sans-serif; direction: rtl; background: #f9fafb; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
        <h2 style="color: #065f46; margin-bottom: 16px;">🔔 {{ $title }}</h2>
        <p style="color: #374151; line-height: 1.8;">{{ $body }}</p>
        @if($url)
            <a href="{{ $url }}" style="display: inline-block; padding: 10px 24px; background: #10b981; color: #fff; text-decoration: none; border-radius: 8px; margin-top: 16px;">مشاهده</a>
        @endif
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 24px 0 12px;">
        <p style="color: #9ca3af; font-size: 12px;">داشبورد سلامت — اعلان خودکار</p>
    </div>
</body>
</html>
