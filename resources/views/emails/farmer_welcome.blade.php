@php
    /** @var \App\Models\Farmer $farmer */
    $fullName = trim(($farmer->firstName ?? '') . ' ' . ($farmer->middleName ?? '') . ' ' . ($farmer->surname ?? ''));
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Welcome to Livestock - Tag and Seal</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background-color: #f5f5f5; padding: 24px;">
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; padding: 24px;">
                <tr>
                    <td style="text-align: left;">
                        <h2 style="margin: 0 0 12px 0; color: #0b3948;">
                            Hello {{ $fullName ?: 'Farmer' }},
                        </h2>
                        <p style="margin: 0 0 16px 0; color: #333333;">
                            Welcome to <strong>Livestock - Tag and Seal</strong>!
                        </p>

                        <p style="margin: 0 0 12px 0; color: #333333;">
                            Your account has been successfully registered. You can log in using the following credentials:
                        </p>

                        <table cellpadding="0" cellspacing="0" style="margin: 0 0 16px 0;">
                            <tr>
                                <td style="padding: 4px 8px; color: #555555;">Email (username):</td>
                                <td style="padding: 4px 8px; font-weight: 600; color: #111111;">
                                    {{ $email }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding: 4px 8px; color: #555555;">Password:</td>
                                <td style="padding: 4px 8px; font-weight: 600; color: #111111;">
                                    {{ $password }}
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 0 0 8px 0; color: #666666; font-size: 14px;">
                            For security, we recommend that you log in and change your password
                            immediately after your first login.
                        </p>

                        <p style="margin: 24px 0 0 0; color: #999999; font-size: 12px;">
                            Thank you for joining us!
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
