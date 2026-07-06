<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #075865;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
        }
        .content {
            background-color: #f9fafb;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .message {
            background-color: white;
            padding: 20px;
            border-radius: 6px;
            border-left: 4px solid #075865;
            margin: 20px 0;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 14px;
        }
        h1 {
            margin: 0;
            font-size: 24px;
        }
        .timestamp {
            color: #6b7280;
            font-size: 14px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
    </div>
    
    <div class="content">
        <div class="message">
            <p>{{ $message }}</p>
        </div>
        
        @if($data && isset($data['amount_cents']))
            <p><strong>Amount:</strong> GH₵ {{ number_format($data['amount_cents'] / 100, 2) }}</p>
        @endif
        
        @if($data && isset($data['due_date']))
            <p><strong>Due Date:</strong> {{ \Carbon\Carbon::parse($data['due_date'])->format('F j, Y') }}</p>
        @endif
        
        @if($data && isset($data['billing_period']))
            <p><strong>Billing Period:</strong> {{ $data['billing_period'] }}</p>
        @endif
        
        <div class="timestamp">
            <em>Sent {{ $created_at->format('F j, Y \a\t g:i A') }}</em>
        </div>
    </div>
    
    <div class="footer">
        <p>This is an automated notification from {{ config('brand.display_name') }}.</p>
        <p>Please do not reply to this email.</p>
    </div>
</body>
</html>
