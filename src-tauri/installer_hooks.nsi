!macro NSIS_HOOK_PREINSTALL
  ; Terminate existing SIPETA instances if running before updating files
  nsExec::Exec 'taskkill /F /IM sipeta.exe'
  nsExec::Exec 'taskkill /F /IM SIPETA.exe'
!macroend

!macro NSIS_HOOK_POSTINSTALL
  ; Copy WebView2Loader.dll next to sipeta.exe
  File "/oname=$INSTDIR\WebView2Loader.dll" "C:\tools\target\release\WebView2Loader.dll"

  ; Create sipeta.cmd wrapper in $INSTDIR
  FileOpen $0 "$INSTDIR\sipeta.cmd" w
  FileWrite $0 "@echo off$\r$\n"
  FileWrite $0 '"%~dp0SIPETA.exe" %*$\r$\n'
  FileClose $0

  ; Create sipeta.bat wrapper in $INSTDIR
  FileOpen $0 "$INSTDIR\sipeta.bat" w
  FileWrite $0 "@echo off$\r$\n"
  FileWrite $0 '"%~dp0SIPETA.exe" %*$\r$\n'
  FileClose $0

  ; Add Windows Firewall Inbound Rules for LAN access
  nsExec::Exec 'netsh advfirewall firewall add rule name="SIPETA Web Server (Port 8100)" dir=in action=allow protocol=TCP localport=8100 profile=any'
  nsExec::Exec 'netsh advfirewall firewall add rule name="SIPETA mDNS Discovery (UDP 5353)" dir=in action=allow protocol=UDP localport=5353 profile=any'

  ; Register $INSTDIR into User PATH (HKCU\Environment\Path) if not already present
  ReadRegStr $0 HKCU "Environment" "Path"
  ${StrLoc} $1 $0 "$INSTDIR" ">"
  ${If} $1 == ""
    ${If} $0 == ""
      WriteRegExpandStr HKCU "Environment" "Path" "$INSTDIR"
    ${Else}
      WriteRegExpandStr HKCU "Environment" "Path" "$0;$INSTDIR"
    ${EndIf}
    ; Broadcast WM_SETTINGCHANGE (0x001A) so new CMD/PowerShell terminals pick up the updated PATH
    System::Call 'user32::SendMessageTimeout(i 0xffff, i 0x001A, i 0, str "Environment", i 2, i 5000, *i .r1)'
  ${EndIf}

  ; Copy Info_Alamat_SIPETA.bat into installation directory
  File "/oname=$INSTDIR\Info_Alamat_SIPETA.bat" "C:\Users\HYPE AMD\Documents\SIPETA\Info_Alamat_SIPETA.bat"

  ; Create Desktop shortcut for "Cek Alamat Jaringan SIPETA"
  CreateShortCut "$DESKTOP\Cek Alamat Jaringan SIPETA.lnk" "$INSTDIR\Info_Alamat_SIPETA.bat" "" "$INSTDIR\sipeta.exe" 0
!macroend
