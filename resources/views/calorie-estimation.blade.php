<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estimasi Kalori Makanan - Self Medical Record</title>
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
            --warning: #f59e0b;
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

        /* Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 4px;
        }

        .header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-main);
        }

        .badge-sim {
            background: #fef3c7;
            color: #92400e;
            font-size: 0.75rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 9999px;
            border: 1px solid #fde68a;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* Card container */
        .card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -1px rgba(15, 23, 42, 0.04);
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: all 0.2s ease;
        }

        /* Upload Area */
        .upload-area {
            border: 2px dashed #93c5fd;
            border-radius: 16px;
            background: #f0f9ff;
            padding: 32px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: #e0f2fe;
        }

        .upload-icon {
            width: 56px;
            height: 56px;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            box-shadow: 0 4px 10px rgba(2, 132, 199, 0.15);
        }

        .btn-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            width: 100%;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 16px;
            font-size: 0.875rem;
            font-weight: 600;
            border-radius: 12px;
            cursor: pointer;
            border: none;
            transition: all 0.15s ease;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: #ffffff;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #f1f5f9;
            color: var(--text-main);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: #e2e8f0;
        }

        .btn-outline {
            background: transparent;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary-light);
        }

        /* Hidden inputs */
        .hidden-input {
            display: none;
        }

        /* Image Preview Box */
        .preview-box {
            position: relative;
            width: 100%;
            height: 240px;
            border-radius: 16px;
            overflow: hidden;
            background: #000;
        }

        .preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Loading State */
        .loading-box {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
            padding: 40px 20px;
            text-align: center;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid var(--primary-light);
            border-top: 4px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Result Section */
        .result-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .total-calorie-banner {
            background: linear-gradient(135deg, #0284c7 0%, #0369a1 100%);
            color: #ffffff;
            border-radius: 16px;
            padding: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .total-calorie-val {
            font-size: 1.5rem;
            font-weight: 700;
        }

        .food-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .food-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: #f8fafc;
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .food-name {
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-main);
        }

        .food-cal {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--primary);
            background: var(--primary-light);
            padding: 4px 10px;
            border-radius: 9999px;
        }

        .disclaimer-box {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 0.75rem;
            color: #b45309;
            line-height: 1.4;
            display: flex;
            gap: 8px;
        }

        .error-box {
            background: #fef2f2;
            border: 1px solid #fee2e2;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 0.8125rem;
            color: #b91c1c;
            display: none;
        }
    </style>
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <header class="header">
            <div>
                <h1>Estimasi Kalori Makanan</h1>
                <p style="font-size: 0.8125rem; color: var(--text-muted); margin-top: 2px;">Analisis nutrisi dan kalori dari foto</p>
            </div>
            <span class="badge-sim">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Data Simulasi
            </span>
        </header>

        <!-- Error banner -->
        <div id="errorBox" class="error-box"></div>

        <!-- 1. INITIAL STATE: Upload Options -->
        <section id="stepUpload" class="card">
            <h2 style="font-size: 1rem; font-weight: 600;">Unggah Foto Makanan</h2>
            <p style="font-size: 0.8125rem; color: var(--text-muted);">
                Ambil foto makanan Anda secara langsung atau pilih dari galeri untuk melihat perkiraan kalorinya.
            </p>

            <div class="upload-area" onclick="document.getElementById('galleryInput').click()">
                <div class="upload-icon">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </div>
                <div>
                    <p style="font-weight: 600; font-size: 0.9375rem;">Pilih atau Tarik Foto Makanan</p>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Mendukung JPEG, PNG, atau WebP (maks. 5 MB)</p>
                </div>
            </div>

            <div class="btn-group">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('cameraInput').click()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    Kamera
                </button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('galleryInput').click()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    Galeri
                </button>
            </div>

            <!-- Hidden file inputs -->
            <input type="file" id="cameraInput" class="hidden-input" accept="image/*" capture="environment" onchange="handleFileSelected(event)">
            <input type="file" id="galleryInput" class="hidden-input" accept="image/*" onchange="handleFileSelected(event)">
        </section>

        <!-- 2. PREVIEW STATE -->
        <section id="stepPreview" class="card" style="display: none;">
            <h2 style="font-size: 1rem; font-weight: 600;">Pratinjau Foto Makanan</h2>
            
            <div class="preview-box">
                <img id="previewImage" class="preview-img" alt="Pratinjau Makanan">
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span id="fileInfo" style="font-size: 0.75rem; color: var(--text-muted);"></span>
                <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.75rem;" onclick="resetUpload()">
                    Ganti Foto
                </button>
            </div>

            <button type="button" class="btn btn-primary" style="width: 100%;" onclick="startEstimation()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Mulai Estimasi Kalori
            </button>
        </section>

        <!-- 3. LOADING STATE -->
        <section id="stepLoading" class="card" style="display: none;">
            <div class="loading-box">
                <div class="spinner"></div>
                <div>
                    <h3 style="font-size: 1.0625rem; font-weight: 600; margin-bottom: 6px;">Menganalisis Makanan...</h3>
                    <p style="font-size: 0.8125rem; color: var(--text-muted);">
                        Sistem sedang mengidentifikasi komponen makanan dalam foto dan menghitung rentang kalorinya.
                    </p>
                </div>
            </div>
        </section>

        <!-- 4. RESULT STATE -->
        <section id="stepResult" class="card" style="display: none;">
            <div class="result-header">
                <div>
                    <h2 style="font-size: 1.125rem; font-weight: 700;">Hasil Estimasi</h2>
                    <p style="font-size: 0.8125rem; color: var(--text-muted);">Berdasarkan foto yang Anda unggah</p>
                </div>
                <span class="badge-sim">Data Simulasi</span>
            </div>

            <!-- Uploaded user food image -->
            <div class="preview-box" style="height: 200px;">
                <img id="resultImage" class="preview-img" alt="Foto Makanan Anda">
            </div>

            <!-- Total Calories Banner -->
            <div class="total-calorie-banner">
                <div>
                    <p style="font-size: 0.8125rem; opacity: 0.9;">Total Estimasi Kalori</p>
                    <p id="totalCaloriesVal" class="total-calorie-val">~470–595 kkal</p>
                </div>
                <div style="background: rgba(255,255,255,0.2); padding: 8px 12px; border-radius: 10px; font-size: 0.75rem;">
                    6 Komponen
                </div>
            </div>

            <!-- Food Detail Breakdown -->
            <div>
                <h3 style="font-size: 0.875rem; font-weight: 600; margin-bottom: 10px; color: var(--text-main);">
                    Rincian Komponen Makanan
                </h3>
                <div id="foodList" class="food-list">
                    <!-- Populated dynamically via JS -->
                </div>
            </div>

            <!-- Disclaimer note -->
            <div class="disclaimer-box">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink: 0;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>
                    Hasil estimasi kalori ini merupakan data simulasi/dummy dan belum dianalisis oleh model AI medis sungguhan.
                </span>
            </div>

            <button type="button" class="btn btn-secondary" style="width: 100%;" onclick="resetUpload()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                Estimasi dengan Foto Lain
            </button>
        </section>
    </div>

    <script>
        let selectedFile = null;

        function showError(msg) {
            const errBox = document.getElementById('errorBox');
            if (msg) {
                errBox.textContent = msg;
                errBox.style.display = 'block';
            } else {
                errBox.style.display = 'none';
            }
        }

        function handleFileSelected(event) {
            showError(null);
            const file = event.target.files && event.target.files[0];
            if (!file) return;

            // Validate type
            if (!file.type.match(/^image\/(jpeg|png|webp|jpg)$/)) {
                showError('File harus berupa gambar dengan format JPEG, PNG, atau WebP.');
                return;
            }

            // Validate size max 5 MB
            if (file.size > 5 * 1024 * 1024) {
                showError('Ukuran file foto maksimal 5 MB.');
                return;
            }

            selectedFile = file;

            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImage').src = e.target.result;
                document.getElementById('resultImage').src = e.target.result;
                document.getElementById('fileInfo').textContent = `${file.name} (${(file.size / 1024).toFixed(0)} KB)`;

                document.getElementById('stepUpload').style.display = 'none';
                document.getElementById('stepPreview').style.display = 'flex';
            };
            reader.readAsDataURL(file);
        }

        function resetUpload() {
            selectedFile = null;
            document.getElementById('cameraInput').value = '';
            document.getElementById('galleryInput').value = '';
            showError(null);

            document.getElementById('stepUpload').style.display = 'flex';
            document.getElementById('stepPreview').style.display = 'none';
            document.getElementById('stepLoading').style.display = 'none';
            document.getElementById('stepResult').style.display = 'none';
        }

        async function startEstimation() {
            if (!selectedFile) return;

            showError(null);
            document.getElementById('stepPreview').style.display = 'none';
            document.getElementById('stepLoading').style.display = 'flex';

            // Simulate loading state (min 1 second for user feedback)
            const minWait = new Promise(resolve => setTimeout(resolve, 1200));

            const dummyData = {
                title: 'Hasil Estimasi',
                total_calories_min: 470,
                total_calories_max: 595,
                total_calories_formatted: '~470–595 kkal',
                is_mock: true,
                foods: [
                    { name: 'Ikan Salmon Panggang', calories_formatted: '220–250 kkal' },
                    { name: 'Nasi', calories_formatted: '120–150 kkal' },
                    { name: 'Alpukat', calories_formatted: '60–80 kkal' },
                    { name: 'Jagung Manis', calories_formatted: '30–40 kkal' },
                    { name: 'Saus/Dressing', calories_formatted: '30–60 kkal' },
                    { name: 'Tomat Ceri', calories_formatted: '10–15 kkal' }
                ]
            };

            try {
                // If token is in localStorage, attempt API call, otherwise use clean client simulation
                const token = localStorage.getItem('api_token');
                let result = dummyData;

                if (token) {
                    const formData = new FormData();
                    formData.append('image', selectedFile);

                    const res = await fetch('/api/calorie-estimation', {
                        method: 'POST',
                        headers: {
                            'Authorization': 'Bearer ' + token,
                            'Accept': 'application/json',
                        },
                        body: formData
                    });

                    if (res.ok) {
                        const json = await res.json();
                        if (json.data) result = json.data;
                    }
                }

                await minWait;
                renderResult(result);
            } catch (err) {
                await minWait;
                renderResult(dummyData);
            }
        }

        function renderResult(data) {
            document.getElementById('stepLoading').style.display = 'none';
            document.getElementById('stepResult').style.display = 'flex';

            // Set foto hasil estimasi
            const resultImg = document.getElementById('resultImage');
            if (data.image_url) {
                resultImg.src = data.image_url;
            } else if (data.image) {
                resultImg.src = data.image;
            } else {
                const prev = document.getElementById('previewImage');
                if (prev) resultImg.src = prev.src;
            }

            document.getElementById('totalCaloriesVal').textContent = data.total_calories_formatted || `~${data.total_calories_min}–${data.total_calories_max} kkal`;

            const foodList = document.getElementById('foodList');
            foodList.innerHTML = '';

            (data.foods || []).forEach(item => {
                const row = document.createElement('div');
                row.className = 'food-item';
                row.innerHTML = `
                    <span class="food-name">${item.name}</span>
                    <span class="food-cal">${item.calories_formatted || `${item.calories_min}–${item.calories_max} kkal`}</span>
                `;
                foodList.appendChild(row);
            });
        }
    </script>
</body>
</html>

