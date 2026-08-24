#!/usr/bin/env bash
# ==============================================================================
# SIPETA DIRECT BROWSER LAUNCHER
# ==============================================================================
# Deskripsi:
# Menjalankan PHP built-in server SIPETA di background, menunggu health check,
# lalu langsung membuka browser default OS tanpa jendela Tauri / UI perantara.
#
# URL Priority:
#   1. http://sipeta:8100        (jika hostname resolve & health OK)
#   2. http://<LAN-IP>:8100      (IP LAN otomatis dari default route)
#   3. http://127.0.0.1:8100     (fallback localhost)
# ==============================================================================

set -e

ACTION="${1:-launch}"
PORT="${SIPETA_PORT:-8100}"
DATA_DIR="${SIPETA_DATA_DIR:-$HOME/.local/share/SIPETA}"
DB_DIR="$DATA_DIR/database"
DB_FILE="$DB_DIR/database.sqlite"
STORAGE_DIR="$DATA_DIR/storage"
PID_FILE="$DATA_DIR/sipeta.pid"
ENV_FILE="$DATA_DIR/.env"

# Hostname utama — dikonfigurasi di router kantor via Local DNS
SIPETA_HOSTNAME="sipeta"

# ------------------------------------------------------------------------------
# 1. Resolusi Portable Application Directory
# ------------------------------------------------------------------------------
resolve_app_dir() {
    if [ -n "$SIPETA_PROJECT_ROOT" ] && [ -f "$SIPETA_PROJECT_ROOT/artisan" ] && [ -f "$SIPETA_PROJECT_ROOT/server.php" ]; then
        echo "$SIPETA_PROJECT_ROOT"
        return
    fi

    # Cek lokasi script saat ini
    SCRIPT_DIR="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
    if [ -f "$SCRIPT_DIR/artisan" ] && [ -f "$SCRIPT_DIR/server.php" ]; then
        echo "$SCRIPT_DIR"
        return
    fi
    if [ -f "$SCRIPT_DIR/../artisan" ] && [ -f "$SCRIPT_DIR/../server.php" ]; then
        echo "$(cd "$SCRIPT_DIR/.." && pwd)"
        return
    fi

    # Cek direktori standar instalasi Linux
    CANDIDATES=(
        "$HOME/Documents/SIPETA"
        "$HOME/.local/share/SIPETA/app"
        "/usr/share/sipeta"
        "/usr/lib/sipeta"
        "/opt/sipeta"
    )

    for CAND in "${CANDIDATES[@]}"; do
        if [ -f "$CAND/artisan" ] && [ -f "$CAND/server.php" ]; then
            echo "$CAND"
            return
        fi
    done

    echo "ERROR: Direktori project SIPETA tidak ditemukan." >&2
    exit 1
}

APP_DIR="$(resolve_app_dir)"

# ------------------------------------------------------------------------------
# 2. Inisialisasi Direktori & Environment
# ------------------------------------------------------------------------------
init_environment() {
    mkdir -p \
        "$DATA_DIR" \
        "$DB_DIR" \
        "$STORAGE_DIR/app/private" \
        "$STORAGE_DIR/app/private/livewire-tmp" \
        "$STORAGE_DIR/app/public" \
        "$STORAGE_DIR/app/kk_uploads" \
        "$STORAGE_DIR/app/ocr_temp" \
        "$STORAGE_DIR/app/livewire-tmp" \
        "$STORAGE_DIR/framework/cache/data" \
        "$STORAGE_DIR/framework/sessions" \
        "$STORAGE_DIR/framework/views" \
        "$STORAGE_DIR/logs"

    FIRST_RUN=0
    if [ ! -f "$DB_FILE" ]; then
        touch "$DB_FILE"
        FIRST_RUN=1
    fi

    if [ ! -f "$ENV_FILE" ]; then
        cat <<ENVEOF > "$ENV_FILE"
APP_NAME="SIPETA"
APP_ENV=production
APP_KEY=base64:LY8ZE0zYet/zuyCnO6OO+I+P5IykjlJJ4HY0I/IfCKk=
APP_DEBUG=false
APP_URL=http://localhost:$PORT
APP_LOCALE=id
APP_FALLBACK_LOCALE=id

DB_CONNECTION=sqlite
DB_DATABASE="$DB_FILE"
LARAVEL_STORAGE_PATH="$STORAGE_DIR"
DB_JOURNAL_MODE=WAL
DB_BUSY_TIMEOUT=5000

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info
ENVEOF
    fi

    # Export environment variables for PHP child process
    export DB_CONNECTION=sqlite
    export DB_DATABASE="$DB_FILE"
    export LARAVEL_STORAGE_PATH="$STORAGE_DIR"
    export APP_ENV=production
    export APP_DEBUG=false
    export APP_URL="http://localhost:$PORT"
    export SIPETA_PORT="$PORT"

    # Jalankan migration pada setiap start, dan seeder referensi jika first run atau database kosong
    php "$APP_DIR/artisan" migrate --force --no-interaction >/dev/null 2>&1 || true
    if [ "$FIRST_RUN" -eq 1 ] || [ ! -s "$DB_FILE" ]; then
        php "$APP_DIR/artisan" db:seed --force --no-interaction >/dev/null 2>&1 || true
    fi
}

# ------------------------------------------------------------------------------
# 3. Helper Cek Health Server (via 127.0.0.1)
# ------------------------------------------------------------------------------
is_server_healthy() {
    php -r '
        $port = $argv[1];
        $ctx = stream_context_create(["http" => ["timeout" => 0.5]]);
        $res = @file_get_contents("http://127.0.0.1:{$port}/health", false, $ctx);
        if ($res !== false && strpos($res, "\"status\":\"ok\"") !== false) {
            exit(0);
        }
        exit(1);
    ' "$PORT" >/dev/null 2>&1
}

# ------------------------------------------------------------------------------
# 4. Deteksi IP LAN dari Default Route
# ------------------------------------------------------------------------------
# Menggunakan ip route untuk menentukan interface default, lalu mengambil IP-nya.
# JANGAN hardcode IP tertentu. IP ini bisa berubah jika jaringan berubah.
detect_lan_ip() {
    # Cara 1: dari default route
    local iface
    iface=$(ip route show default 2>/dev/null | awk '/default/ {print $5; exit}')
    if [ -n "$iface" ]; then
        local ip
        ip=$(ip -4 addr show dev "$iface" 2>/dev/null | awk '/inet / {print $2}' | cut -d/ -f1 | head -n1)
        if [ -n "$ip" ] && [ "$ip" != "127.0.0.1" ]; then
            echo "$ip"
            return
        fi
    fi

    # Cara 2: hostname -I (ambil IP pertama non-loopback)
    local all_ips
    all_ips=$(hostname -I 2>/dev/null || true)
    for ip in $all_ips; do
        case "$ip" in
            127.*|172.17.*) continue ;;  # skip loopback & docker
            *) echo "$ip"; return ;;
        esac
    done

    echo ""
}

# ------------------------------------------------------------------------------
# 5. Health Check URL tertentu
# ------------------------------------------------------------------------------
check_url_health() {
    local url="$1"
    local http_code
    http_code=$(curl -s -o /dev/null -w '%{http_code}' --connect-timeout 1 --max-time 2 "$url/health" 2>/dev/null || echo "000")
    [ "$http_code" = "200" ]
}

# ------------------------------------------------------------------------------
# 6. Tentukan URL Terbaik (4-tier priority)
# ------------------------------------------------------------------------------
# PRIORITY 1: http://sipeta:PORT        (router Local DNS)
# PRIORITY 2: http://sipeta.local:PORT  (Avahi mDNS)
# PRIORITY 3: http://LAN_IP:PORT        (IP otomatis)
# PRIORITY 4: http://127.0.0.1:PORT     (localhost)
SIPETA_MDNS_HOSTNAME="sipeta.local"

discover_best_url() {
    local hostname_url="http://${SIPETA_HOSTNAME}:${PORT}"
    local mdns_url="http://${SIPETA_MDNS_HOSTNAME}:${PORT}"
    local lan_ip
    lan_ip=$(detect_lan_ip)
    local lan_url=""
    [ -n "$lan_ip" ] && lan_url="http://${lan_ip}:${PORT}"
    local local_url="http://127.0.0.1:${PORT}"

    # Priority 1: hostname (router Local DNS)
    if check_url_health "$hostname_url"; then
        echo "$hostname_url"
        return
    fi

    # Priority 2: sipeta.local (Avahi mDNS)
    if check_url_health "$mdns_url"; then
        echo "$mdns_url"
        return
    fi

    # Priority 3: LAN IP
    if [ -n "$lan_url" ] && check_url_health "$lan_url"; then
        echo "$lan_url"
        return
    fi

    # Priority 4: localhost
    echo "$local_url"
}

# ------------------------------------------------------------------------------
# 7. Helper Buka Browser Default OS
# ------------------------------------------------------------------------------
open_browser() {
    local TARGET_URL="$1"
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "$TARGET_URL" >/dev/null 2>&1 &
    elif command -v gio >/dev/null 2>&1; then
        gio open "$TARGET_URL" >/dev/null 2>&1 &
    elif command -v sensible-browser >/dev/null 2>&1; then
        sensible-browser "$TARGET_URL" >/dev/null 2>&1 &
    elif [ -n "$BROWSER" ]; then
        "$BROWSER" "$TARGET_URL" >/dev/null 2>&1 &
    fi
}

# ------------------------------------------------------------------------------
# 8. Handler Action: STOP
# ------------------------------------------------------------------------------
stop_server() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE" 2>/dev/null || true)
        if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
            kill "$PID" 2>/dev/null || true
            for _ in {1..25}; do
                if ! kill -0 "$PID" 2>/dev/null; then
                    break
                fi
                sleep 0.2
            done
            if kill -0 "$PID" 2>/dev/null; then
                kill -9 "$PID" 2>/dev/null || true
            fi
        fi
        rm -f "$PID_FILE"
    fi

    # Bersihkan sisa proses php pada port 8100 jika ada
    pkill -f "php.*-S 0.0.0.0:$PORT" 2>/dev/null || true
}

# ------------------------------------------------------------------------------
# 9. Handler Action: LAUNCH / START
# ------------------------------------------------------------------------------
launch_sipeta() {
    init_environment

    # Jika server sudah aktif & sehat, langsung discover URL dan buka browser
    if is_server_healthy; then
        TARGET_URL="$(discover_best_url)/admin"
        echo "Server sudah aktif. Membuka: $TARGET_URL"
        open_browser "$TARGET_URL"
        exit 0
    fi

    # Bersihkan PID usang jika ada
    stop_server

    # Jalankan PHP server di background (bind 0.0.0.0 agar client LAN bisa akses)
    nohup setsid php \
        -d upload_max_filesize=32M \
        -d post_max_size=32M \
        -d memory_limit=256M \
        -d max_execution_time=120 \
        -d max_input_time=120 \
        -S "0.0.0.0:$PORT" \
        -t "$APP_DIR/public" \
        "$APP_DIR/server.php" \
        > "$STORAGE_DIR/logs/php_server.log" 2>&1 &
    
    SERVER_PID=$!
    echo "$SERVER_PID" > "$PID_FILE"
    disown "$SERVER_PID" 2>/dev/null || true

    # Tunggu health check hingga 15 detik
    HEALTHY=0
    for _ in {1..75}; do
        if is_server_healthy; then
            HEALTHY=1
            break
        fi
        # Cek jika proses crash saat start
        if ! kill -0 "$SERVER_PID" 2>/dev/null; then
            break
        fi
        sleep 0.2
    done

    if [ "$HEALTHY" -eq 1 ]; then
        # Server sehat — tentukan URL terbaik lalu buka browser
        TARGET_URL="$(discover_best_url)/admin"
        echo "SIPETA aktif. Membuka: $TARGET_URL"
        open_browser "$TARGET_URL"
        exit 0
    else
        echo "ERROR: Server SIPETA gagal merespons health check pada port $PORT." >&2
        cat "$STORAGE_DIR/logs/php_server.log" 2>/dev/null | tail -n 20 >&2 || true
        exit 1
    fi
}

# ------------------------------------------------------------------------------
# 10. Main Dispatcher
# ------------------------------------------------------------------------------
case "$ACTION" in
    launch|start)
        launch_sipeta
        ;;
    stop)
        stop_server
        echo "SIPETA server dihentikan."
        ;;
    restart)
        stop_server
        launch_sipeta
        ;;
    status)
        if is_server_healthy; then
            BEST_URL="$(discover_best_url)"
            LAN_IP="$(detect_lan_ip)"
            echo "Status: SIPETA server AKTIF pada port $PORT (PID: $(cat "$PID_FILE" 2>/dev/null || echo '-'))"
            echo ""
            echo "URL Terbaik:     $BEST_URL/admin"
            echo "Hostname URL:    http://${SIPETA_HOSTNAME}:${PORT}/admin"
            echo "mDNS URL:        http://${SIPETA_MDNS_HOSTNAME}:${PORT}/admin"
            [ -n "$LAN_IP" ] && echo "LAN IP URL:      http://${LAN_IP}:${PORT}/admin"
            echo "Localhost URL:   http://127.0.0.1:${PORT}/admin"
        else
            echo "Status: SIPETA server TIDAK AKTIF."
        fi
        ;;
    *)
        echo "Usage: $0 {launch|start|stop|restart|status}"
        exit 1
        ;;
esac
