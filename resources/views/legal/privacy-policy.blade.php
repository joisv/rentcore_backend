<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kebijakan Privasi — {{ $appName }}</title>
    <style>
        :root {
            --bg: #F8FAFC;
            --card: #FFFFFF;
            --ink: #0F172A;
            --muted: #475569;
            --subtle: #64748B;
            --border: #E2E8F0;
            --accent-bg: #F1F5F9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Inter, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--ink);
            line-height: 1.65;
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
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        h1 {
            margin: 0 0 6px;
            font-size: 26px;
            line-height: 1.25;
            font-weight: 800;
        }
        .meta {
            margin: 0 0 24px;
            font-size: 13px;
            color: var(--subtle);
        }
        h2 {
            margin: 26px 0 10px;
            font-size: 17px;
            font-weight: 700;
            color: var(--ink);
        }
        p {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 14.5px;
        }
        ul {
            margin: 0 0 14px;
            padding-left: 20px;
            color: var(--muted);
            font-size: 14.5px;
        }
        li { margin-bottom: 6px; }
        .callout {
            background: var(--accent-bg);
            border: 1px solid var(--border);
            border-left: 4px solid var(--ink);
            border-radius: 8px;
            padding: 14px 16px;
            margin: 14px 0;
        }
        .callout strong { color: var(--ink); }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 12.5px;
            background: var(--accent-bg);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--ink);
        }
        .footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12.5px;
            color: var(--subtle);
        }
        a { color: var(--ink); }
        @media (max-width: 600px) {
            .card { padding: 20px 18px; }
            h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="{{ route('legal.privacy-policy') }}">SEWAKASIR</a>
            <nav class="nav-links">
                <a class="active" href="{{ route('legal.privacy-policy') }}">Kebijakan Privasi</a>
                <a href="{{ route('legal.account-deletion') }}">Penghapusan Akun</a>
            </nav>
        </div>
    </header>

    <main class="container">
        <article class="card">
            <h1>Kebijakan Privasi</h1>
            <p class="meta">Terakhir diperbarui: {{ $lastUpdated }} &bull; Berlaku untuk aplikasi mobile &amp; layanan {{ $appName }}</p>

            <p>
                <strong>SewaKasir</strong> adalah platform SaaS (Software-as-a-Service) manajemen operasional penyewaan perangkat dan Point-of-Sale (POS) multi-tenant yang membantu pemilik usaha serta staf cabang mengelola inventaris unit, booking sewa, kasir, dan laporan keuangan. Kebijakan Privasi ini menjelaskan bagaimana kami mengumpulkan, menggunakan, melindungi, dan mengelola data Anda.
            </p>

            <h2>1. Data yang Kami Kumpulkan</h2>
            <ul>
                <li><strong>Identitas Akun Pengguna:</strong> Nama lengkap, alamat email, nomor telepon/WhatsApp, peran staf/pemilik, dan informasi autentikasi Google Sign-In (jika digunakan).</li>
                <li><strong>Profil Bisnis &amp; Cabang (Tenant):</strong> Nama usaha, slogan, alamat cabang, nomor kontak toko, konfigurasi metode pembayaran/QRIS, dan pengaturan struk.</li>
                <li><strong>Data Operasional Penyewaan &amp; POS:</strong> Catatan booking, jadwal serah-terima dan pengembalian unit, riwayat pembayaran, pengeluaran kas laci, serta data pelanggan penyewa yang diinput oleh tenant.</li>
                <li><strong>Foto Opsional:</strong> Foto produk/aset, bukti pengeluaran kasir, atau foto dokumen identitas pelanggan yang secara sukarela diunggah oleh staf/pemilik untuk keperluan verifikasi sewa.</li>
            </ul>

            <h2>2. Izin Bluetooth &amp; Perangkat</h2>
            <div class="callout">
                <p style="margin:0;">
                    <strong>Transparansi Izin Bluetooth (Android 11 &amp; Android 12–15):</strong>
                    Izin <code>BLUETOOTH_SCAN</code> (dengan flag <code>neverForLocation</code>) dan <code>BLUETOOTH_CONNECT</code> digunakan <strong>semata-mata untuk menemukan dan menghubungkan perangkat ke printer struk thermal ESC/POS lokal</strong>. Aplikasi SewaKasir <strong>tidak pernah</strong> menggunakan izin Bluetooth untuk melacak lokasi fisik pengguna atau mengumpulkan data lokasi latar belakang.
                </p>
            </div>
            <ul>
                <li><strong>Kamera &amp; Galeri (Opsional):</strong> Hanya diakses saat pengguna memilih mengambil atau mengunggah foto unit inventaris, QRIS merchant, bukti pengeluaran, atau foto identitas pelanggan.</li>
                <li><strong>Penyimpanan Sementara (Cache):</strong> Digunakan hanya untuk menyimpan file gambar PNG sementara ketika pengguna memilih fitur <em>Bagikan Struk</em>.</li>
            </ul>

            <h2>3. Isolasi Data Multi-Tenant &amp; Keamanan</h2>
            <ul>
                <li>Setiap bisnis/tenant diisolasi secara ketat pada tingkat API dan basis data menggunakan konteks tenant (<code>X-Business-Id</code> &amp; <code>X-Branch-Id</code>). Pengguna dari satu bisnis tidak dapat mengakses data bisnis lain.</li>
                <li>Seluruh lalu lintas komunikasi menggunakan enkripsi HTTPS/TLS, kata sandi di-hash secara kriptografis, dan sesi aplikasi dilindungi oleh token autentikasi Laravel Sanctum.</li>
                <li>SewaKasir tidak menjual, menyewakan, atau memperdagangkan data pribadi maupun data transaksi tenant kepada pihak ketiga untuk tujuan periklanan.</li>
            </ul>

            <h2>4. Hak Pengguna &amp; Penghapusan Akun</h2>
            <p>
                Anda berhak mengakses, memperbarui informasi profil Anda langsung melalui menu <em>Profil Saya</em> di dalam aplikasi, atau mengajukan penghapusan akun serta anonimisasi data pribadi kapan saja melalui halaman resmi <a href="{{ route('legal.account-deletion') }}">Permintaan Penghapusan Akun</a>.
            </p>

            <h2>5. Kontak Pengembang</h2>
            <p>
                Jika Anda memiliki pertanyaan terkait privasi data, izin perangkat, atau kepatuhan keamanan, silakan hubungi tim dukungan kami melalui email:
                <a href="mailto:{{ $contactEmail }}"><strong>{{ $contactEmail }}</strong></a>.
            </p>
        </article>

        <p class="footer">&copy; {{ date('Y') }} SewaKasir Multi-Tenant Rental &amp; POS Platform.</p>
    </main>
</body>
</html>
