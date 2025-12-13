<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Farm Invitation - Extension Officer</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background-color: #f5f5f5; padding: 24px;">
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; padding: 24px;">
                <tr>
                    <td style="text-align: left;">
                        <h2 style="margin: 0 0 12px 0; color: #0b3948;">
                            Hello {{ $extensionOfficerName }},
                        </h2>
                        <p style="margin: 0 0 16px 0; color: #333333;">
                            You have been invited to join
                            @if($farmName)
                                <strong>{{ $farmName }}</strong>
                            @else
                                a farm
                            @endif
                            by <strong>{{ $farmerName }}</strong>.
                        </p>

                        <div style="background-color: #f8f9fa; padding: 16px; border-radius: 8px; margin: 16px 0;">
                            <p style="margin: 0 0 8px 0; color: #333333; font-weight: 600;">
                                Access Code:
                            </p>
                            <p style="margin: 0; color: #0b3948; font-size: 18px; font-weight: bold; font-family: monospace;">
                                {{ $accessCode }}
                            </p>
                        </div>

                        <p style="margin: 0 0 12px 0; color: #333333;">
                            You can log in using the following credentials:
                        </p>

                        <table cellpadding="0" cellspacing="0" style="margin: 0 0 16px 0;">
                            <tr>
                                <td style="padding: 4px 8px; color: #555555;">Email (username):</td>
                                <td style="padding: 4px 8px; font-weight: 600; color: #111111;">
                                    {{ $extensionOfficer->email }}
                                </td>
                            </tr>
                            @if($extensionOfficer->password)
                            <tr>
                                <td style="padding: 4px 8px; color: #555555;">Password:</td>
                                <td style="padding: 4px 8px; font-weight: 600; color: #111111;">
                                    {{ $extensionOfficer->password }}
                                </td>
                            </tr>
                            @endif
                        </table>

                        <div style="background-color: #e3f2fd; padding: 16px; border-radius: 8px; margin: 16px 0;">
                            <p style="margin: 0 0 8px 0; color: #333333; font-weight: 600;">
                                Farm Owner Contact:
                            </p>
                            @if($farmer->phone1)
                            <p style="margin: 4px 0; color: #555555;">
                                Phone: {{ $farmer->phone1 }}
                            </p>
                            @endif
                            @if($farmer->email)
                            <p style="margin: 4px 0; color: #555555;">
                                Email: {{ $farmer->email }}
                            </p>
                            @endif
                        </div>

                        <p style="margin: 0 0 8px 0; color: #666666; font-size: 14px;">
                            For security, we recommend that you log in and change your password
                            immediately after your first login.
                        </p>

                        <p style="margin: 24px 0 0 0; color: #999999; font-size: 12px;">
                            If you did not expect this invitation, you can ignore this email.
                        </p>

                        <p style="margin: 8px 0 0 0; color: #999999; font-size: 12px;">
                            &mdash; Livestock - Tag and Seal Team
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>

