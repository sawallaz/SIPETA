<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup SIPETA - Administrator Utama</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, ::before, ::after {
            box-sizing: border-box;
            border-width: 0;
            border-style: solid;
            border-color: #e2e8f0;
        }
        body {
            margin: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            background-color: #0f172a;
            background-image: url('{{ asset('images/auth-bg.jpg') }}');
            background-size: cover;
            background-position: center 35%;
            background-repeat: no-repeat;
            overflow-x: hidden;
        }
        body::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(15, 28, 20, 0.82) 0%, rgba(20, 31, 23, 0.88) 50%, rgba(10, 18, 13, 0.94) 100%);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 1;
        }
        .setup-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 28rem;
            margin: auto;
        }
        .setup-card {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 1.25rem;
            border: 1px solid rgba(255, 255, 255, 0.6);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.3);
            padding: 2.25rem 2rem;
            overflow: hidden;
        }
        .setup-header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .setup-logo {
            height: 7rem;
            width: auto;
            margin: 0 auto 0.5rem auto;
            display: block;
            object-fit: contain;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.12));
        }
        .setup-brand-title {
            font-size: 1.625rem;
            font-weight: 800;
            color: #1e3824;
            letter-spacing: -0.025em;
            line-height: 1.1;
            margin-bottom: 0.25rem;
        }
        .setup-brand-sub {
            font-size: 0.8125rem;
            font-weight: 500;
            color: #64748b;
            line-height: 1.3;
            margin-bottom: 1rem;
        }
        .setup-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            background-color: rgba(69, 107, 79, 0.12);
            color: #2f4635;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.25rem 0.625rem;
            border-radius: 9999px;
            margin-bottom: 0.375rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }
        .setup-header h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.25rem;
            font-weight: 800;
            color: #1e3824;
            letter-spacing: -0.025em;
        }
        .setup-header p {
            margin: 0;
            font-size: 0.875rem;
            color: #64748b;
            line-height: 1.4;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.375rem;
        }
        .form-input {
            width: 100%;
            padding: 0.6875rem 0.875rem;
            font-size: 0.9375rem;
            border: 1px solid #d1d5db;
            border-radius: 0.625rem;
            background-color: #ffffff;
            color: #111827;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-input:focus {
            outline: none;
            border-color: #456b4f;
            box-shadow: 0 0 0 3px rgba(69, 107, 79, 0.2);
        }
        .form-input.is-invalid {
            border-color: #ef4444;
        }
        .form-error {
            color: #dc2626;
            font-size: 0.8125rem;
            margin-top: 0.25rem;
        }
        .btn-submit {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 600;
            color: #ffffff;
            background-color: #456b4f;
            border-radius: 0.75rem;
            cursor: pointer;
            transition: all 0.15s ease;
            box-shadow: 0 4px 6px -1px rgba(69, 107, 79, 0.25);
            margin-top: 1.25rem;
        }
        .btn-submit:hover {
            background-color: #385640;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px -2px rgba(69, 107, 79, 0.35);
        }
        .security-notice {
            margin-top: 1.25rem;
            padding: 0.625rem 0.875rem;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
            text-align: center;
            line-height: 1.4;
        }
        .alert-error {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            margin-bottom: 1rem;
        }
        .alert-error ul {
            margin: 0.25rem 0 0 1rem;
            padding: 0;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <img src="{{ asset('images/sipeta-logo.png') }}" alt="Logo SIPETA" class="setup-logo">
                <div class="setup-brand-title">SIPETA</div>
                <div class="setup-brand-sub">Sistem Informasi Pendataan Penduduk Tanete</div>
                <span class="setup-badge">Inisialisasi Pertama</span>
                <h1>Setup SIPETA</h1>
            </div>

            @if ($errors->any())
                <div class="alert-error">
                    <strong>Terdapat kesalahan pengisian:</strong>
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form action="{{ route('setup.store') }}" method="POST">
                @csrf

                <div class="form-group">
                    <label for="name" class="form-label">Nama Lengkap</label>
                    <input
                        type="text"
                        id="name"
                        name="name"
                        value="{{ old('name') }}"
                        class="form-input @error('name') is-invalid @enderror"
                        placeholder="Contoh: Administrator Kelurahan"
                        required
                        autofocus
                    >
                    @error('name')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="email" class="form-label">Email Super Admin</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="{{ old('email') }}"
                        class="form-input @error('email') is-invalid @enderror"
                        placeholder="admin@kelurahan.go.id"
                        required
                    >
                    @error('email')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password" class="form-label">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-input @error('password') is-invalid @enderror"
                        placeholder="Minimal 8 karakter"
                        required
                    >
                    @error('password')
                        <div class="form-error">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group">
                    <label for="password_confirmation" class="form-label">Konfirmasi Password</label>
                    <input
                        type="password"
                        id="password_confirmation"
                        name="password_confirmation"
                        class="form-input"
                        placeholder="Ulangi password di atas"
                        required
                    >
                </div>

                <button type="submit" class="btn-submit">
                    Mulai SIPETA
                </button>

                <div class="security-notice">
                    🔒 Akun ini digunakan sebagai administrator utama SIPETA.
                </div>
            </form>
        </div>
    </div>
</body>
</html>
