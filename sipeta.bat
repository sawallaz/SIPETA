@echo off
if "%~1"=="start" (
    if exist "%~dp0resources\php\php.exe" (
        start "" /B "%~dp0resources\php\php.exe" -c "%~dp0resources\php\php.ini" -S 0.0.0.0:8100 "%~dp0server.php"
        echo [SIPETA] Server started at http://localhost:8100
        exit /b 0
    )
)
if exist "%~dp0src-tauri\target\release\SIPETA.exe" (
    "%~dp0src-tauri\target\release\SIPETA.exe" %*
) else if exist "C:\tools\target\release\sipeta.exe" (
    "C:\tools\target\release\sipeta.exe" %*
) else if exist "%~dp0SIPETA.exe" (
    "%~dp0SIPETA.exe" %*
) else if exist "%~dp0src-tauri\target\debug\SIPETA.exe" (
    "%~dp0src-tauri\target\debug\SIPETA.exe" %*
) else if exist "C:\tools\target\debug\sipeta.exe" (
    "C:\tools\target\debug\sipeta.exe" %*
) else (
    echo [SIPETA] Binary executable not found. Please run 'npm run tauri build' or build target first.
    exit /b 1
)
