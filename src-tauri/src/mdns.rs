use std::net::{Ipv4Addr, SocketAddrV4, UdpSocket};
#[cfg(unix)]
use std::os::fd::FromRawFd;
use std::sync::atomic::{AtomicBool, Ordering};
use std::sync::Arc;
use std::thread;
use std::time::Duration;

/// Fallback mDNS responder untuk sipeta.local
///
/// Ini adalah supplement untuk Avahi daemon.
/// Jika Avahi aktif, Avahi menangani mDNS secara penuh.
/// Jika Avahi tidak aktif (misalnya di sistem tanpa Avahi),
/// responder ini menjawab query mDNS untuk sipeta.local.
pub struct MdnsResponder {
    stop_signal: Arc<AtomicBool>,
    handle: Option<thread::JoinHandle<()>>,
}

impl MdnsResponder {
    pub fn start(target_ip: Ipv4Addr) -> Option<Self> {
        let stop_signal = Arc::new(AtomicBool::new(false));
        let stop_signal_clone = stop_signal.clone();

        // Multicast address 224.0.0.251:5353
        let mdns_addr = Ipv4Addr::new(224, 0, 0, 251);

        let socket = match create_reusable_mdns_socket() {
            Ok(s) => s,
            Err(e) => {
                log::warn!("mDNS SO_REUSEPORT bind failed: {}. Mencoba fallback standard bind.", e);
                let bind_addr = SocketAddrV4::new(Ipv4Addr::UNSPECIFIED, 5353);
                match UdpSocket::bind(bind_addr) {
                    Ok(s) => s,
                    Err(err) => {
                        log::warn!("Standard mDNS bind juga gagal: {}. Avahi mungkin sudah menangani mDNS.", err);
                        return None;
                    }
                }
            }
        };

        if let Err(e) = socket.join_multicast_v4(&mdns_addr, &Ipv4Addr::UNSPECIFIED) {
            log::warn!("mDNS join_multicast_v4 warning: {}", e);
        }

        let _ = socket.set_read_timeout(Some(Duration::from_millis(500)));

        // Send initial announcement for sipeta.local so mDNS caches on LAN are primed
        let dest = SocketAddrV4::new(mdns_addr, 5353);
        let announcement = build_mdns_a_response(&[0, 0], target_ip, DOMAIN_WIRE_SIPETA_LOCAL);
        let _ = socket.send_to(&announcement, dest);

        let handle = thread::spawn(move || {
            let mut buf = [0u8; 2048];

            while !stop_signal_clone.load(Ordering::Relaxed) {
                match socket.recv_from(&mut buf) {
                    Ok((len, src)) => {
                        if len > 12 {
                            if is_sipeta_local_query(&buf[..len]) {
                                let response = build_mdns_a_response(&buf[..len], target_ip, DOMAIN_WIRE_SIPETA_LOCAL);
                                let dest = if src.port() == 5353 {
                                    SocketAddrV4::new(mdns_addr, 5353)
                                } else {
                                    match src {
                                        std::net::SocketAddr::V4(v4) => v4,
                                        _ => SocketAddrV4::new(mdns_addr, 5353),
                                    }
                                };
                                let _ = socket.send_to(&response, dest);
                            }
                        }
                    }
                    Err(_) => {
                        // Read timeout
                    }
                }
            }
        });

        Some(MdnsResponder {
            stop_signal,
            handle: Some(handle),
        })
    }

    pub fn stop(&mut self) {
        self.stop_signal.store(true, Ordering::Relaxed);
        if let Some(h) = self.handle.take() {
            let _ = h.join();
        }
    }
}

impl Drop for MdnsResponder {
    fn drop(&mut self) {
        self.stop();
    }
}

fn create_reusable_mdns_socket() -> Result<UdpSocket, String> {
    #[cfg(unix)]
    unsafe {
        let fd = libc::socket(libc::AF_INET, libc::SOCK_DGRAM, 0);
        if fd < 0 {
            return Err("Failed to create UDP socket".into());
        }

        let optval: libc::c_int = 1;
        libc::setsockopt(
            fd,
            libc::SOL_SOCKET,
            libc::SO_REUSEADDR,
            &optval as *const _ as *const libc::c_void,
            std::mem::size_of::<libc::c_int>() as libc::socklen_t,
        );

        libc::setsockopt(
            fd,
            libc::SOL_SOCKET,
            libc::SO_REUSEPORT,
            &optval as *const _ as *const libc::c_void,
            std::mem::size_of::<libc::c_int>() as libc::socklen_t,
        );

        let mut addr: libc::sockaddr_in = std::mem::zeroed();
        addr.sin_family = libc::AF_INET as libc::sa_family_t;
        addr.sin_port = 5353u16.to_be();
        addr.sin_addr.s_addr = libc::INADDR_ANY;

        let res = libc::bind(
            fd,
            &addr as *const _ as *const libc::sockaddr,
            std::mem::size_of::<libc::sockaddr_in>() as libc::socklen_t,
        );

        if res < 0 {
            libc::close(fd);
            return Err("Failed to bind mDNS socket with SO_REUSEPORT".into());
        }

        Ok(UdpSocket::from_raw_fd(fd))
    }

    #[cfg(not(unix))]
    {
        let bind_addr = SocketAddrV4::new(Ipv4Addr::UNSPECIFIED, 5353);
        UdpSocket::bind(bind_addr).map_err(|e| e.to_string())
    }
}

/// Wire format untuk "sipeta.local" — DNS label encoding
/// \x06 s i p e t a \x05 l o c a l \x00
const DOMAIN_WIRE_SIPETA_LOCAL: &[u8] = &[
    0x06, b's', b'i', b'p', b'e', b't', b'a',
    0x05, b'l', b'o', b'c', b'a', b'l',
    0x00,
];

/// Cek apakah packet mDNS berisi query untuk sipeta.local
fn is_sipeta_local_query(packet: &[u8]) -> bool {
    let lower: Vec<u8> = packet.iter().map(|b| b.to_ascii_lowercase()).collect();
    lower.windows(DOMAIN_WIRE_SIPETA_LOCAL.len()).any(|w| w == DOMAIN_WIRE_SIPETA_LOCAL)
}

/// Bangun DNS A record response untuk sipeta.local
fn build_mdns_a_response(query: &[u8], ip: Ipv4Addr, domain_wire: &[u8]) -> Vec<u8> {
    let mut resp = Vec::with_capacity(128);

    // Transaction ID (match query ID or 0 for mDNS)
    let tx_id_0 = query.get(0).copied().unwrap_or(0);
    let tx_id_1 = query.get(1).copied().unwrap_or(0);
    resp.push(tx_id_0);
    resp.push(tx_id_1);

    // Flags: 0x8400 (Standard query response, Authoritative)
    resp.push(0x84);
    resp.push(0x00);

    // Questions: 0, Answer RRs: 1, Authority RRs: 0, Additional RRs: 0
    resp.push(0x00);
    resp.push(0x00);
    resp.push(0x00);
    resp.push(0x01); // 1 Answer
    resp.push(0x00);
    resp.push(0x00);
    resp.push(0x00);
    resp.push(0x00);

    // Name (wire format)
    resp.extend_from_slice(domain_wire);

    // Type: A (0x0001)
    resp.push(0x00);
    resp.push(0x01);

    // Class: IN (0x0001) | Flush cache bit (0x8000) = 0x8001
    resp.push(0x80);
    resp.push(0x01);

    // TTL: 120 seconds (0x00000078)
    resp.push(0x00);
    resp.push(0x00);
    resp.push(0x00);
    resp.push(0x78);

    // Data Length: 4 bytes for IPv4
    resp.push(0x00);
    resp.push(0x04);

    // IP Octets
    resp.extend_from_slice(&ip.octets());

    resp
}
