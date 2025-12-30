<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus Notification Digest</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background-color: #7c3aed;
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .content {
            background-color: #f9fafb;
            padding: 30px;
            border-radius: 0 0 8px 8px;
        }
        .notification-item {
            background-color: white;
            border-left: 4px solid #7c3aed;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .notification-title {
            font-weight: 600;
            color: #7c3aed;
            margin-bottom: 5px;
        }
        .notification-message {
            color: #4b5563;
        }
        .notification-time {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 5px;
        }
        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 12px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📬 Your Nexus Notifications</h1>
    </div>
    <div class="content">
        <p>You have <strong>{{ $notifications->count() }}</strong> new notification{{ $notifications->count() === 1 ? '' : 's' }}:</p>
        
        @foreach($notifications as $notification)
            <div class="notification-item">
                <div class="notification-title">{{ $notification->title }}</div>
                <div class="notification-message">{{ $notification->message }}</div>
                <div class="notification-time">{{ $notification->created_at->diffForHumans() }}</div>
            </div>
        @endforeach
    </div>
    <div class="footer">
        <p>This is an automated digest from Nexus. To change your notification preferences, log in to your account.</p>
        <p>Sent {{ now()->format('F j, Y \a\t g:i A') }}</p>
    </div>
</body>
</html>
