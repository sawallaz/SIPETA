@echo off
title INFORMASI ALAMAT AKSES SIPETA - KELURAHAN
color 1F
cls

echo =====================================================================
echo                SIPETA - PUSAT INFORMASI AKSES JARINGAN
echo =====================================================================
echo.
echo  Sedang memeriksa koneksi jaringan...
echo.

:: Ambil IP IPv4 aktif dari adapter Wi-Fi atau Ethernet (bukan loopback/virtual)
for /f "usebackq tokens=*" %%a in (`powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -notmatch 'Loopback|vEthernet|Virtual|Bluetooth' -and $_.IPAddress -notmatch '^(169\.254|127\.)' } | Select-Object -First 1).IPAddress"`) do set LAN_IP=%%a

if "%LAN_IP%"=="" (
    color 4F
    echo  [!] PERINGATAN:
    echo      Komputer ini belum terhubung ke Wi-Fi atau kabel LAN Router!
    echo      Pastikan koneksi jaringan aktif agar komputer lain bisa terhubung.
    echo.
    pause
    exit /b
)

set SIPETA_URL=http://%LAN_IP%:8100

echo =====================================================================
echo   BAGIKAN ALAMAT INI KE LAPTOP / KOMPUTER STAF LAIN:
echo.
echo         %SIPETA_URL%
echo.
echo =====================================================================
echo.
echo  Petunjuk untuk Staf Kelurahan:
echo   1. Pastikan laptop staf terhubung ke Wi-Fi / LAN yang sama.
echo   2. Buka Chrome / Edge / browser favorit.
echo   3. Ketik alamat di atas pada address bar, lalu tekan Enter.
echo.
echo ---------------------------------------------------------------------
echo  PILIHAN:
echo   [1] Buka alamat ini di browser sekarang
echo   [2] Salin (Copy) URL ke clipboard
echo   [3] Tutup jendela ini
echo ---------------------------------------------------------------------
set /p choice="Pilih menu (1/2/3): "

if "%choice%"=="1" (
    start %SIPETA_URL%
    exit /b
)
if "%choice%"=="2" (
    echo | set /p="%SIPETA_URL%" | clip
    echo.
    echo  [OK] Alamat %SIPETA_URL% berhasil disalin ke Clipboard!
    timeout /t 2 ^>nul
    exit /b
)
exit /b
