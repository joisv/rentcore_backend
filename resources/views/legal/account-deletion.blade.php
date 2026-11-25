<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Penghapusan Akun — {{ $appName }}</title>
    <style>
        :root {
            --bg: #F8FAFC;
            --card: #FFFFFF;
            --ink: #0F172A;
            --muted: #475569;
            --subtle: #64748B;
            --border: #E2E8F0;
            --surface-muted: #F1F5F9;
            --success-bg: #ECFDF5;
            --success-border: #A7F3D0;
            --success-ink: #065F46;
            --danger-bg: #FEF2F2;
            --danger-border: #FECACA;
            --danger-ink: #991B1B;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }
        .topbar {
            background: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 14px 20px;
        }
        .topbar-inner {
            max-width: 820px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .brand {
            font-weight: 800;
            font-size: 16px;
            letter-spacing: 0.04em;
            color: var(--ink);
            text-decoration: none;
        }
        .nav-links {
            display: flex;
            gap: 16px;
            font-size: 13px;
            font-weight: 600;
        }
        .nav-links a {
            color: var(--muted);
            text-decoration: none;
        }
        .nav-links a.active {
            color: var(--ink);
            text-decoration: underline;
            text-underline-offset: 4px;
        }
        .container {
            max-width: 820px;
            margin: 28px auto 48px;
            padding: 0 16px;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        h1 {
            margin: 0 0 8px;
            font-size: 25px;
            line-height: 1.25;
            font-weight: 800;
        }
        h2 {
            margin: 22px 0 10px;
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
        }
        p {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 14.5px;
        }
        ul, ol {
            margin: 0 0 14px;
            padding-left: 20px;
            color: var(--muted);
            font-size: 14.5px;
        }
        li { margin-bottom: 6px; }
        .alert {
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 18px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert-success {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success-ink);
        }
        .alert-danger {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-ink);
        }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin: 14px 0;
        }
        .info-box {
            background: var(--surface-muted);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
        }
        .info-box h3 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
        }
        .form-group {
            margin-bottom: 14px;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--ink);
            margin-bottom: 6px;
        }
        input[type="email"],
        input[type="password"],
        textarea {
            width: 100%;
            padding: 11px 13px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            color: var(--ink);
            background: #FFFFFF;
        }
        input:focus, textarea:focus {
            outline: none;
            border-color: var(--ink);
        }
        .btn-obsidian {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            background: var(--ink);
            color: #FFFFFF;
            border: none;
            border-radius: 8px;
            padding: 13px 18px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-obsidian:hover {
            opacity: 0.92;
        }
        .footer {
            text-align: center;
            font-size: 12.5px;
            color: var(--subtle);
        }
        @media (max-width: 640px) {
            .card { padding: 20px 18px; }
            .grid-2 { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="{{ route('legal.privacy-policy') }}">SEWAKASIR</a>
            <nav class="nav-links">
                <a href="{{ route('legal.privacy-policy') }}">Kebijakan Privasi</a>
                <a class="active" href="{{ route('legal.account-deletion') }}">Penghapusan Akun</a>
            </nav>
        </div>
    </header>

    <main class="container">
        @if (session('deletion_success'))
            <div class="alert alert-success" role="status">
                <strong>Berhasil:</strong> {{ session('deletion_success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                <strong>Permintaan tidak dapat diproses:</strong>
                <ul style="margin: 6px 0 0; padding-left: 18px; color: inherit;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="card">
            <h1>Permintaan Penghapusan Akun SewaKasir</h1>
            <p>
                Sesuai Kebijakan Data Pengguna Google Play, Anda dapat meminta penonaktifan permanen dan penghapusan/anonimisasi data akun pribadi SewaKasir Anda baik langsung dari dalam aplikasi maupun melalui formulir web publik di halaman ini.
            </p>

            <h2>Cara Menghapus Akun</h2>
            <ol>
                <li><strong>Melalui Aplikasi SewaKasir:</strong> Masuk ke aplikasi &rarr; buka tab <em>Pengaturan</em> &rarr; pilih <em>Profil Saya</em> &rarr; gulir ke bagian <em>Privasi &amp; Akun</em> &rarr; ketuk <em>Hapus Akun</em>.</li>
                <li><strong>Melalui Formulir Web (Tanpa Aplikasi):</strong> Lengkapi formulir verifikasi email dan kata sandi di bawah ini untuk segera menonaktifkan akun dan mencabut seluruh token login Anda.</li>
            </ol>

            <div class="grid-2">
                <div class="info-box">
                    <h3>Data yang Dihapus / Dianonimkan Permanen</h3>
                    <ul style="margin-bottom:0; font-size:13.5px;">
                        <li>Nama lengkap, alamat email pribadi, dan nomor telepon.</li>
                        <li>Tautan akun Google (<code>google_id</code>), foto profil, dan PIN otorisasi manajer.</li>
                        <li>Seluruh token akses Sanctum dan sesi perangkat aktif.</li>
                    </ul>
                </div>
                <div class="info-box">
                    <h3>Data yang Dipertahankan &amp; Retensi</h3>
                    <ul style="margin-bottom:0; font-size:13.5px;">
                        <li>Catatan transaksi sewa &amp; buku kas keuangan tenant dipertahankan dalam bentuk teranonimkan demi integritas audit dan kepatuhan pajak.</li>
                        <li><strong>Waktu Retensi:</strong> Anonimisasi akun berlaku seketika; salinan cadangan otomatis dibersihkan dalam maksimal <strong>7 hari</strong>.</li>
                    </ul>
                </div>
            </div>

            <p style="font-size:13px; color:var(--subtle); margin-top:8px;">
                Catatan Pemilik Bisnis: Demi mencegah bisnis aktif kehilangan akses admin, pemilik tunggal bisnis aktif perlu mentransfer kepemilikan atau menutup cabang/bisnis aktif terlebih dahulu sebelum penghapusan akun dapat diproses. Bantuan lebih lanjut: <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>.
            </p>
        </section>

        <section class="card">
            <h2 style="margin-top:0;">Formulir Verifikasi Penghapusan Akun</h2>
            <form method="POST" action="{{ route('legal.account-deletion.submit') }}">
                @csrf
                <div class="form-group">
                    <label for="email">Email Akun Terdaftar</label>
                    <input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="email"
                        value="{{ old('email') }}"
                        placeholder="nama@domainanda.com"
                    >
                </div>

                <div class="form-group">
                    <label for="password">Kata Sandi Akun</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        required
                        autocomplete="current-password"
                        placeholder="Masukkan kata sandi untuk verifikasi"
                    >
                </div>

                <div class="form-group">
                    <label for="reason">Alasan Penghapusan (Opsional)</label>
                    <textarea
                        id="reason"
                        name="reason"
                        rows="3"
                        maxlength="500"
                        placeholder="Contoh: Tidak lagi menggunakan layanan atau pindah perangkat"
                    >{{ old('reason') }}</textarea>
                </div>

                <button type="submit" class="btn-obsidian">
                    Hapus &amp; Nonaktifkan Akun Saya
                </button>
            </form>
        </section>

        <p class="footer">&copy; {{ date('Y') }} SewaKasir &bull; <a href="{{ route('legal.privacy-policy') }}">Kebijakan Privasi</a></p>
    </main>
</body>
</html>
