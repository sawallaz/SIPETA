@php
    $serverIp = request()->server('SERVER_ADDR');
    if (empty($serverIp) || $serverIp === '0.0.0.0' || $serverIp === '127.0.0.1' || $serverIp === '::1') {
        $serverIp = gethostbyname(gethostname());
    }
    $lanUrl = "http://{$serverIp}:8100";
@endphp

<div style="display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; margin: 0 8px; background: #0f172a; border: 1px solid #38bdf8; border-radius: 6px; color: #ffffff; font-size: 11px; z-index: 9999;">
    <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #22c55e;"></span>
    <span style="font-family: monospace; font-weight: 600; color: #38bdf8;">LAN: {{ $lanUrl }}</span>
    <button 
        type="button"
        onclick="navigator.clipboard.writeText('{{ $lanUrl }}'); alert('Alamat {{ $lanUrl }} berhasil disalin!');"
        style="background: #2563eb; color: #ffffff; border: none; padding: 2px 8px; border-radius: 4px; cursor: pointer; font-size: 10px; font-weight: bold;"
    >
        Salin
    </button>
</div>
