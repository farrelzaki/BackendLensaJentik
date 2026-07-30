<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; background: #f4f4f4; padding: 20px;">
    <div style="max-width: 500px; margin: 0 auto; background: white; padding: 24px; border-radius: 8px;">
        <h2 style="color: #1565C0;">🔑 Reset Password LensaJentik</h2>
        <p style="color: #333; line-height: 1.6;">
            Halo, <strong>{{ $name }}</strong>!
        </p>
        <p style="color: #333; line-height: 1.6;">
            Kami menerima permintaan untuk mereset password akun LensaJentik kamu.
            Salin kode token di bawah ini dan masukkan di halaman reset password:
        </p>
        <div style="background: #E3F2FD; border: 1px solid #1565C0; border-radius: 6px; padding: 16px; text-align: center; margin: 20px 0;">
            <code style="font-size: 24px; font-weight: bold; color: #1565C0; letter-spacing: 4px;">{{ $token }}</code>
        </div>
        <p style="color: #555; font-size: 13px; line-height: 1.6;">
            Token ini berlaku selama <strong>60 menit</strong>. Jika kamu tidak meminta reset password, abaikan email ini — password kamu tidak akan berubah.
        </p>
        <p style="color: #888; font-size: 12px; margin-top: 24px;">
            Email otomatis dari LensaJentik. Jangan balas email ini.
        </p>
    </div>
</body>
</html>
