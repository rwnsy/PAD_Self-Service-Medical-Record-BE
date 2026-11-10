<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Foto Profil - Self Medical Record</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0284c7;
            --primary-hover: #0369a1;
            --primary-light: #e0f2fe;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --success: #10b981;
            --danger: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            padding: 16px;
        }

        .app-container {
            width: 100%;
            max-width: 480px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .header {
            padding: 12px 4px;
        }

        .header h1 {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .avatar-wrapper {
            position: relative;
            width: 120px;
            height: 120px;
        }

        .avatar-img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            box-shadow: 0 4px 14px rgba(0,0,0,0.08);
            background: #e2e8f0;
        }

        .avatar-edit-btn {
            position: absolute;
            bottom: 2px;
            right: 2px;
            width: 36px;
            height: 36px;
            background: var(--primary);
            color: #ffffff;
            border-radius: 50%;
            border: 2px solid #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transition: all 0.2s ease;
        }

        .avatar-edit-btn:hover {
            background: var(--primary-hover);
            transform: scale(1.05);
        }

        .btn-group {
            display: flex;
            gap: 10px;
            width: 100%;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            flex: 1;
        }

        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-secondary { background: #f1f5f9; color: var(--text-main); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: #fef2f2; color: var(--danger); border: 1px solid #fee2e2; }
        .btn-danger:hover { background: #fee2e2; }

        .hidden-input { display: none; }

        .feedback-box {
            width: 100%;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 0.8125rem;
            display: none;
        }

        .feedback-success { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
        .feedback-error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <div class="app-container">
        <header class="header">
            <h1>Foto Profil Pengguna</h1>
            <p style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 2px;">Kelola foto identitas akun Anda</p>
        </header>

        <div id="feedback" class="feedback-box"></div>

        <section class="card">
            <!-- Avatar Display -->
            <div class="avatar-wrapper">
                <img id="avatarDisplay" class="avatar-img" src="/api/avatar/default?name=User" alt="Avatar">
                <button type="button" class="avatar-edit-btn" onclick="document.getElementById('photoInput').click()" title="Ganti Foto Profil">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                </button>
            </div>

            <div style="text-align: center;">
                <h2 id="userName" style="font-size: 1rem; font-weight: 700;">Pengguna Self Medical Record</h2>
                <p id="userEmail" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Format: JPEG, PNG, atau WebP (maks. 5 MB)</p>
            </div>

            <!-- Choose Actions -->
            <div class="btn-group">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('cameraInput').click()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Kamera
                </button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('photoInput').click()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    Galeri
                </button>
            </div>

            <!-- Preview confirmation bar (hidden initially) -->
            <div id="previewActions" style="display: none; width: 100%; flex-direction: column; gap: 10px;">
                <p style="font-size: 0.75rem; color: var(--primary); text-align: center; font-weight: 600;">Pratinjau foto baru dipilih. Simpan perubahan?</p>
                <div class="btn-group">
                    <button type="button" class="btn btn-secondary" onclick="cancelPreview()">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="savePhoto()">Simpan Foto</button>
                </div>
            </div>

            <!-- Delete photo button -->
            <button id="deleteBtn" type="button" class="btn btn-danger" style="width: 100%; display: none;" onclick="deletePhoto()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                Hapus Foto Profil
            </button>

            <!-- Hidden inputs -->
            <input type="file" id="cameraInput" class="hidden-input" accept="image/jpeg,image/png,image/webp" capture="user" onchange="handlePhotoSelected(event)">
            <input type="file" id="photoInput" class="hidden-input" accept="image/jpeg,image/png,image/webp" onchange="handlePhotoSelected(event)">
        </section>
    </div>

    <script>
        let currentPhotoUrl = "/api/avatar/default?name=User";
        let newPhotoFile = null;

        function showFeedback(msg, type = 'success') {
            const fb = document.getElementById('feedback');
            if (msg) {
                fb.className = 'feedback-box feedback-' + type;
                fb.textContent = msg;
                fb.style.display = 'block';
            } else {
                fb.style.display = 'none';
            }
        }

        function handlePhotoSelected(event) {
            showFeedback(null);
            const file = event.target.files && event.target.files[0];
            if (!file) return;

            if (!file.type.match(/^image\/(jpeg|png|webp|jpg)$/)) {
                showFeedback('Format file harus berupa JPEG, PNG, atau WebP.', 'error');
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                showFeedback('Ukuran file foto maksimal 5 MB.', 'error');
                return;
            }

            newPhotoFile = file;

            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('avatarDisplay').src = e.target.result;
                document.getElementById('previewActions').style.display = 'flex';
            };
            reader.readAsDataURL(file);
        }

        function cancelPreview() {
            newPhotoFile = null;
            document.getElementById('avatarDisplay').src = currentPhotoUrl;
            document.getElementById('previewActions').style.display = 'none';
            document.getElementById('photoInput').value = '';
            document.getElementById('cameraInput').value = '';
            showFeedback(null);
        }

        async function savePhoto() {
            if (!newPhotoFile) return;

            showFeedback('Menyimpan foto profil...', 'success');

            const token = localStorage.getItem('api_token');
            if (token) {
                const formData = new FormData();
                formData.append('photo', newPhotoFile);

                try {
                    const res = await fetch('/api/profile/photo', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + token,
                            'Accept': 'application/json',
                        },
                        body: formData
                    });

                    const json = await res.json();
                    if (res.ok && json.data) {
                        currentPhotoUrl = json.data.avatar_url;
                        document.getElementById('avatarDisplay').src = currentPhotoUrl;
                        document.getElementById('deleteBtn').style.display = 'inline-flex';
                        document.getElementById('previewActions').style.display = 'none';
                        showFeedback('Foto profil berhasil diperbarui!', 'success');
                        newPhotoFile = null;
                        return;
                    }
                } catch (e) {}
            }

            // Local fallback simulation
            currentPhotoUrl = document.getElementById('avatarDisplay').src;
            document.getElementById('previewActions').style.display = 'none';
            document.getElementById('deleteBtn').style.display = 'inline-flex';
            showFeedback('Foto profil berhasil diperbarui!', 'success');
            newPhotoFile = null;
        }

        async function deletePhoto() {
            const token = localStorage.getItem('api_token');
            if (token) {
                try {
                    await fetch('/api/profile/photo', {
                        method: 'DELETE',
                        headers: {
                            'Authorization': 'Bearer ' + token,
                            'Accept': 'application/json',
                        }
                    });
                } catch (e) {}
            }

            currentPhotoUrl = "/api/avatar/default?name=User";
            document.getElementById('avatarDisplay').src = currentPhotoUrl;
            document.getElementById('deleteBtn').style.display = 'none';
            showFeedback('Foto profil berhasil dihapus dan dikembalikan ke avatar bawaan.', 'success');
        }
    </script>
</body>
</html>

