
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Google - Self Medical Record</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            display: grid;
            place-items: center;
            min-height: 100vh;
            margin: 0;
        }

        .card {
            background: white;
            width: min(360px, 85vw);
            padding: 32px;
            border-radius: 16px;
            box-shadow: 0 8px 30px #00000012;
            text-align: center;
        }

        #googleButton {
            display: flex;
            justify-content: center;
            margin: 24px 0;
        }

        #result {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font-size: 14px;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="card">
        <h2>Self Medical Record</h2>
        <p>Masuk menggunakan akun Google kamu.</p>

        <div id="googleButton"></div>
        <div id="result" role="status"></div>
    </div>

    <script>
        const GOOGLE_CLIENT_ID =
            '853095053870-hhkli0k3e73797i7bbcjsjpffs2vqjtg.apps.googleusercontent.com';

        function initializeGoogleButton() {
            google.accounts.id.initialize({
                client_id: GOOGLE_CLIENT_ID,
                callback: handleGoogleLogin
            });

            google.accounts.id.renderButton(
                document.getElementById('googleButton'),
                {
                    theme: 'outline',
                    size: 'large',
                    text: 'continue_with',
                    width: 280
                }
            );
        }

        async function handleGoogleLogin(response) {
            const result = document.getElementById('result');
            result.textContent = 'Sedang memverifikasi akun Google...';

            try {
                const res = await fetch('/api/auth/google', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        id_token: response.credential
                    })
                });

                const payload = await res.json();

                if (!res.ok || payload.success === false) {
                    throw new Error(
                        payload.message || `HTTP ${res.status}`
                    );
                }

                const user = payload.data?.user;

                result.textContent =
                    'Login berhasil!\n' +
                    'Nama: ' + (user?.name ?? '-') + '\n' +
                    'Email: ' + (user?.email ?? '-') + '\n' +
                    'Token Sanctum diterima: ' +
                    Boolean(payload.data?.token);

            } catch (error) {
                result.textContent = 'Login gagal: ' + error.message;
            }
        }
    </script>

    <script
        src="https://accounts.google.com/gsi/client"
        async
        defer
        onload="initializeGoogleButton()">
    </script>
</body>
</html>
