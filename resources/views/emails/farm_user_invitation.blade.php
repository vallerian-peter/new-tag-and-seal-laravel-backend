@php
    /** @var \App\Models\FarmUser $farmUser */
    $fullName = trim(($farmUser->firstName ?? '') . ' ' . ($farmUser->lastName ?? ''));
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Farm User Invitation</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background-color: #f5f5f5; padding: 24px;">
<table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td align="center">
            <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 12px; padding: 24px;">
                <tr>
                    <td style="text-align: left;">
                        <h2 style="margin: 0 0 12px 0; color: #0b3948;">
                            Hello {{ $fullName ?: 'Farm User' }},
                        </h2>
                        <p style="margin: 0 0 16px 0; color: #333333;">
                            You have been invited to use
                            <strong>Livestock - Tag and Seal</strong>
                            as a farm user.
                        </p>

                        <p style="margin: 0 0 12px 0; color: #333333;">
                            You can log in using the following credentials:
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


