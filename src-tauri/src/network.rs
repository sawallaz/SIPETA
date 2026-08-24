use std::io::{Read, Write};
use std::net::{IpAddr, Ipv4Addr, TcpStream, UdpSocket};
use std::path::Path;
use std::time::Duration;

/// Hostname utama — dikonfigurasi di router kantor via Local DNS.
const SIPETA_HOSTNAME: &str = "sipeta";

/// Hostname mDNS — diiklankan otomatis oleh Avahi daemon.
/// Tidak memerlukan konfigurasi router, bekerja via multicast UDP 5353.
const SIPETA_MDNS_HOSTNAME: &str = "sipeta.local";

#[derive(Clone, Debug)]
pub struct ServerNetworkInfo {
    pub port: u16,
    /// URL terbaik untuk dibuka di browser, sudah melalui health check
    pub best_url: String,
    /// http://sipeta:PORT (memerlukan router Local DNS)
    pub hostname_url: String,
    /// http://sipeta.local:PORT (via Avahi mDNS, tanpa konfigurasi router)
    pub mdns_url: String,
    /// IP LAN yang terdeteksi saat runtime (bisa berubah jika jaringan berubah)
    pub lan_ip: String,
    /// http://LAN_IP:PORT
    pub lan_url: String,
    /// http://127.0.0.1:PORT
    pub local_url: String,
}

/// Deteksi IP LAN aktif dari interface yang digunakan oleh default route.
pub fn get_primary_lan_ip() -> Option<Ipv4Addr> {
    let socket = UdpSocket::bind("0.0.0.0:0").ok()?;
    socket.connect("8.8.8.8:80").ok()?;
    let local_addr = socket.local_addr().ok()?;

    match local_addr.ip() {
        IpAddr::V4(ipv4) => {
            if !ipv4.is_loopback()
                && !ipv4.is_unspecified()
                && !is_docker_ip(ipv4)
            {
                Some(ipv4)
            } else {
                None
            }
        }
        _ => None,
    }
}

/// Filter IP Docker (172.17.x.x) dan virtual bridge lainnya
fn is_docker_ip(ip: Ipv4Addr) -> bool {
    let octets = ip.octets();
    octets[0] == 172 && octets[1] == 17
}

/// Fast non-blocking connect with strict timeout to prevent DNS resolution stalls on Windows.
fn resolve_and_connect(host: &str, port: u16, timeout: Duration) -> Option<TcpStream> {
    let addr_str = format!("{}:{}", host, port);

    // If host is a direct IP address, connect immediately without DNS resolve
    if let Ok(sock_addr) = addr_str.parse::<std::net::SocketAddr>() {
        return TcpStream::connect_timeout(&sock_addr, timeout).ok();
    }

    // If host is a hostname, resolve in a background worker thread with a strict deadline
    let (tx, rx) = std::sync::mpsc::channel();
    let host_owned = host.to_string();
    std::thread::spawn(move || {
        use std::net::ToSocketAddrs;
        let target = format!("{}:{}", host_owned, port);
        if let Ok(mut addrs) = target.to_socket_addrs() {
            if let Some(sock_addr) = addrs.next() {
                if let Ok(stream) = TcpStream::connect_timeout(&sock_addr, timeout) {
                    let _ = tx.send(stream);
                }
            }
        }
    });

    rx.recv_timeout(timeout).ok()
}

/// Lakukan HTTP health check cepat ke URL tertentu dan verifikasi identitas SIPETA.
fn fast_health_check(host: &str, port: u16, timeout_ms: u64) -> bool {
    let timeout = Duration::from_millis(timeout_ms);
    if let Some(mut stream) = resolve_and_connect(host, port, timeout) {
        let request = format!(
            "GET /health HTTP/1.1\r\nHost: {}:{}\r\nConnection: close\r\nUser-Agent: SIPETA-Discovery/1.0\r\n\r\n",
            host, port
        );
        if stream.write_all(request.as_bytes()).is_err() {
            return false;
        }
        let _ = stream.set_read_timeout(Some(Duration::from_millis(timeout_ms)));
        let mut response = [0u8; 1024];
        if let Ok(n) = stream.read(&mut response) {
            let resp_str = String::from_utf8_lossy(&response[..n]);
            let is_200 = resp_str.contains("200 OK") || resp_str.contains("HTTP/1.1 200");
            let is_sipeta = resp_str.contains("\"status\":\"ok\"")
                || resp_str.contains("\"database\":\"sqlite\"")
                || resp_str.contains("\"ocr\":");
            return is_200 && is_sipeta;
        }
    }
    false
}

/// Tentukan URL terbaik untuk browser dan cache hasilnya:
///
/// 1. http://sipeta.local:PORT  — Avahi/Windows mDNS
/// 2. http://sipeta:PORT        — router Local DNS
/// 3. http://LAN_IP:PORT        — IP LAN otomatis dari default route
/// 4. http://127.0.0.1:PORT     — fallback localhost (PC server saja)
pub fn discover_best_url(port: u16, app_data_root: Option<&Path>) -> ServerNetworkInfo {
    let hostname_url = format!("http://{}:{}", SIPETA_HOSTNAME, port);
    let mdns_url = format!("http://{}:{}", SIPETA_MDNS_HOSTNAME, port);
    let local_url = format!("http://127.0.0.1:{}", port);

    let lan_ip = get_primary_lan_ip()
        .map(|ip| ip.to_string())
        .unwrap_or_default();

    let lan_url = if !lan_ip.is_empty() {
        format!("http://{}:{}", lan_ip, port)
    } else {
        String::new()
    };

    // Check cached endpoint first if available and still healthy
    if let Some(root) = app_data_root {
        let cache_file = root.join("endpoint.url");
        if cache_file.exists() {
            if let Ok(cached) = std::fs::read_to_string(&cache_file) {
                let trimmed = cached.trim().to_string();
                if !trimmed.is_empty() {
                    let host_to_check = if trimmed.starts_with("http://") {
                        let without_proto = &trimmed[7..];
                        without_proto.split(':').next().unwrap_or("")
                    } else {
                        trimmed.as_str()
                    };
                    if !host_to_check.is_empty() && fast_health_check(host_to_check, port, 200) {
                        return ServerNetworkInfo {
                            port,
                            best_url: trimmed,
                            hostname_url,
                            mdns_url,
                            lan_ip,
                            lan_url,
                            local_url,
                        };
                    } else {
                        // Stale cache (e.g. user changed network): delete and re-discover
                        let _ = std::fs::remove_file(&cache_file);
                    }
                }
            }
        }
    }

    let make_info = |best: &str| -> ServerNetworkInfo {
        if let Some(root) = app_data_root {
            let _ = std::fs::write(root.join("endpoint.url"), best);
        }
        ServerNetworkInfo {
            port,
            best_url: best.to_string(),
            hostname_url: hostname_url.clone(),
            mdns_url: mdns_url.clone(),
            lan_ip: lan_ip.clone(),
            lan_url: lan_url.clone(),
            local_url: local_url.clone(),
        }
    };

    // PRIORITY 1: sipeta.local (Avahi/Windows mDNS) — fast 250ms deadline
    if fast_health_check(SIPETA_MDNS_HOSTNAME, port, 250) {
        log::info!("URL discovery: mDNS '{}' berhasil (PRIORITY 1)", SIPETA_MDNS_HOSTNAME);
        return make_info(&mdns_url);
    }

    // PRIORITY 2: hostname sipeta (router Local DNS) — fast 250ms deadline
    if fast_health_check(SIPETA_HOSTNAME, port, 250) {
        log::info!("URL discovery: hostname '{}' berhasil (PRIORITY 2)", SIPETA_HOSTNAME);
        return make_info(&hostname_url);
    }

    // PRIORITY 3: LAN IP otomatis dari SIPETA server — fast 200ms deadline
    if !lan_ip.is_empty() && fast_health_check(&lan_ip, port, 200) {
        log::info!("URL discovery: LAN IP {} berhasil (PRIORITY 3)", lan_ip);
        return make_info(&lan_url);
    }

    // PRIORITY 4: 127.0.0.1 (localhost fallback)
    log::info!("URL discovery: menggunakan localhost (PRIORITY 4)");
    make_info(&local_url)
}


