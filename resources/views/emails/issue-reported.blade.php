<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $issueSubject }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.5;">
    <h2 style="margin: 0 0 16px;">New issue reported in Biome4Pets Portal</h2>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse;">
        <tr>
            <td style="font-weight: bold; vertical-align: top;">Subject</td>
            <td>{{ $issueSubject }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold; vertical-align: top;">Category</td>
            <td>{{ $category ?: 'Not specified' }}</td>
        </tr>
        <tr>
            <td style="font-weight: bold; vertical-align: top;">Reported by</td>
            <td>{{ $reporterName }} &lt;{{ $reporterEmail }}&gt;</td>
        </tr>
        <tr>
            <td style="font-weight: bold; vertical-align: top;">Reported at</td>
            <td>{{ $reportedAt }}</td>
        </tr>
    </table>

    <h3 style="margin: 24px 0 8px;">Description</h3>
    <p style="white-space: pre-wrap; margin: 0;">{{ $description }}</p>

    <hr style="margin: 24px 0; border: none; border-top: 1px solid #e5e7eb;">
    <p style="font-size: 12px; color: #6b7280; margin: 0;">
        You can reply directly to this email to reach {{ $reporterName }}.
    </p>
</body>
</html>
