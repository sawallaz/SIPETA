#!/usr/bin/env bash
# ==============================================================================
# SIPETA mDNS / Avahi Setup Script
# ==============================================================================
# Mengaktifkan sipeta.local via Avahi mDNS
# Jalankan: sudo bash scripts/setup-mdns.sh
# ==============================================================================
set -e

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Script ini harus dijalankan dengan sudo"
    echo "Usage: sudo bash $0"
    exit 1
fi

echo "=========================================="
echo "SIPETA mDNS SETUP"
echo "=========================================="
echo ""

# 1. Set Avahi host-name=sipeta
echo "[1/4] Mengatur Avahi host-name=sipeta ..."
AVAHI_CONF="/etc/avahi/avahi-daemon.conf"
if grep -q '^host-name=sipeta' "$AVAHI_CONF" 2>/dev/null; then
    echo "      Sudah diset: host-name=sipeta"
elif grep -q '^#host-name=' "$AVAHI_CONF" 2>/dev/null; then
    sed -i 's/^#host-name=.*/host-name=sipeta/' "$AVAHI_CONF"
    echo "      DONE: uncommented dan set host-name=sipeta"
elif grep -q '^host-name=' "$AVAHI_CONF" 2>/dev/null; then
    sed -i 's/^host-name=.*/host-name=sipeta/' "$AVAHI_CONF"
    echo "      DONE: updated host-name=sipeta"
else
    sed -i '/^\[server\]/a host-name=sipeta' "$AVAHI_CONF"
    echo "      DONE: added host-name=sipeta under [server]"
fi

# 2. Buat service file untuk SIPETA HTTP port 8100
echo "[2/4] Membuat Avahi service file ..."
SERVICE_FILE="/etc/avahi/services/sipeta.service"
cat > "$SERVICE_FILE" <<'EOF'
<?xml version="1.0" standalone='no'?>
<!DOCTYPE service-group SYSTEM "avahi-service.dtd">
<!--
  SIPETA mDNS service advertisement
  Mengiklankan sipeta.local port 8100 via Avahi/mDNS
-->
<service-group>
  <name replace-wildcards="yes">SIPETA Web Server on %h</name>
  <service>
    <type>_http._tcp</type>
    <port>8100</port>
    <txt-record>path=/admin</txt-record>
  </service>
</service-group>
EOF
echo "      DONE: $SERVICE_FILE created"

# 3. Enable dan start Avahi daemon
echo "[3/4] Enabling dan starting avahi-daemon ..."
systemctl enable avahi-daemon 2>/dev/null || true
systemctl restart avahi-daemon
sleep 2
echo "      DONE"

# 4. Verifikasi
echo "[4/4] Verifikasi ..."
echo ""
echo "--- avahi-daemon status ---"
systemctl is-active avahi-daemon && echo "      STATUS: ACTIVE" || echo "      STATUS: FAILED"
echo ""

echo "--- avahi-resolve sipeta.local ---"
if avahi-resolve -n sipeta.local 2>/dev/null; then
    echo "      RESOLVE: PASS"
else
    echo "      RESOLVE: FAIL (mungkin perlu beberapa detik)"
fi
echo ""

echo "--- getent hosts sipeta.local ---"
if getent hosts sipeta.local 2>/dev/null; then
    echo "      GETENT: PASS"
else
    echo "      GETENT: FAIL"
fi
echo ""

echo "--- UDP 5353 listeners ---"
ss -lunp | grep 5353 || echo "      (no 5353 listeners)"
echo ""

echo "--- ping sipeta.local ---"
ping -c 2 -W 2 sipeta.local 2>&1 || echo "      PING: FAIL"
echo ""

echo "=========================================="
echo "SETUP SELESAI"
echo "=========================================="
echo ""
echo "Selanjutnya tes:"
echo "  curl http://sipeta.local:8100/health"
echo "  browser: http://sipeta.local:8100"
