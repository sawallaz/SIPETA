pub mod app_data;
pub mod lock;
pub mod mdns;
pub mod network;
pub mod server;

use std::sync::Mutex;
use tauri::menu::{Menu, MenuItem};
use tauri::tray::{TrayIconBuilder, TrayIconEvent};
use tauri::Manager;

use crate::app_data::init_app_data;
use crate::lock::SingleInstanceLock;
use crate::mdns::MdnsResponder;
use crate::network::{discover_best_url, get_primary_lan_ip, ServerNetworkInfo};
use crate::server::ServerManager;

pub fn boot_log(msg: &str) {
    println!("{}", msg);
    append_to_boot_log(msg);
}

pub fn boot_error(msg: &str) {
    let formatted = if msg.starts_with("[ERROR]") {
        msg.to_string()
    } else {
        format!("[ERROR] {}", msg)
    };
    eprintln!("{}", formatted);
    append_to_boot_log(&formatted);
}

fn append_to_boot_log(line: &str) {
    let app_data_dir = crate::app_data::resolve_app_data_dir();
    let _ = std::fs::create_dir_all(&app_data_dir);
    let log_file = app_data_dir.join("boot.log");
    if let Ok(mut f) = std::fs::OpenOptions::new().create(true).append(true).open(&log_file) {
        use std::io::Write;
        let _ = writeln!(f, "{}", line);
    }
}

struct AppState {
    _lock: SingleInstanceLock,
    server: Mutex<ServerManager>,
    _mdns: Option<MdnsResponder>,
    network_info: ServerNetworkInfo,
}

pub fn open_default_browser(url: &str) -> Result<(), String> {
    #[cfg(target_os = "linux")]
    {
        std::process::Command::new("xdg-open")
            .arg(url)
            .spawn()
            .map_err(|e| format!("Gagal membuka browser via xdg-open: {}", e))?;
    }

    #[cfg(target_os = "windows")]
    {
        use std::os::windows::process::CommandExt;
        let mut cmd = std::process::Command::new("cmd");
        cmd.args(["/C", "start", "", url]);
        cmd.creation_flags(0x08000000); // CREATE_NO_WINDOW
        cmd.spawn()
            .map_err(|e| format!("Gagal membuka browser via cmd start: {}", e))?;
    }

    #[cfg(target_os = "macos")]
    {
        std::process::Command::new("open")
            .arg(url)
            .spawn()
            .map_err(|e| format!("Gagal membuka browser via open: {}", e))?;
    }

    Ok(())
}

#[tauri::command]
fn get_server_info(state: tauri::State<'_, AppState>) -> Result<serde_json::Value, String> {
    Ok(serde_json::json!({
        "status": "running",
        "port": state.network_info.port,
        "best_url": state.network_info.best_url,
        "hostname_url": state.network_info.hostname_url,
        "mdns_url": state.network_info.mdns_url,
        "lan_ip": state.network_info.lan_ip,
        "lan_url": state.network_info.lan_url,
        "local_url": state.network_info.local_url,
    }))
}

#[tauri::command]
fn open_browser_url(url: Option<String>, state: tauri::State<'_, AppState>) -> Result<(), String> {
    let target = url.unwrap_or_else(|| format!("{}/admin", state.network_info.best_url));
    open_default_browser(&target)
}

#[tauri::command]
fn stop_server_and_exit(app: tauri::AppHandle, state: tauri::State<'_, AppState>) -> Result<(), String> {
    let _ = state.server.lock().map(|mut s| s.stop());
    app.exit(0);
    Ok(())
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    // Panic hook to capture any panic during startup
    std::panic::set_hook(Box::new(|panic_info| {
        boot_error(&format!("[CRITICAL PANIC] {}", panic_info));
    }));

    let args: Vec<String> = std::env::args().collect();
    let action = if args.len() > 1 {
        args[1].to_lowercase()
    } else {
        "launch".to_string()
    };

    let port: u16 = std::env::var("SIPETA_PORT")
        .ok()
        .and_then(|p| p.parse().ok())
        .unwrap_or(8100);

    match action.as_str() {
        "stop" => {
            let app_data = match init_app_data() {
                Ok(paths) => paths,
                Err(e) => {
                    eprintln!("Gagal inisialisasi AppData: {}", e);
                    std::process::exit(1);
                }
            };
            println!("Stopping SIPETA...");
            crate::server::stop_server_by_pid(&app_data, port);
            println!("SIPETA STOPPED");
            std::process::exit(0);
        }
        "status" => {
            let app_data = match init_app_data() {
                Ok(paths) => paths,
                Err(e) => {
                    eprintln!("Gagal inisialisasi AppData: {}", e);
                    std::process::exit(1);
                }
            };
            if crate::server::is_server_healthy(port) {
                let pid = std::fs::read_to_string(app_data.root.join("sipeta.pid"))
                    .unwrap_or_else(|_| "-".to_string())
                    .trim()
                    .to_string();
                let network_info = discover_best_url(port, Some(&app_data.root));
                println!("Status: RUNNING");
                println!("PID: {}", pid);
                println!("Port: {}", port);
                println!("URL: {}/admin", network_info.best_url);
            } else {
                let _ = std::fs::remove_file(app_data.root.join("sipeta.pid"));
                let _ = std::fs::remove_file(&app_data.lock_file);
                let _ = std::fs::remove_file(app_data.root.join("endpoint.url"));
                println!("Status: STOPPED");
            }
            std::process::exit(0);
        }
        "restart" => {
            let app_data = match init_app_data() {
                Ok(paths) => paths,
                Err(e) => {
                    eprintln!("Gagal inisialisasi AppData: {}", e);
                    std::process::exit(1);
                }
            };
            println!("Stopping SIPETA...");
            crate::server::stop_server_by_pid(&app_data, port);
            println!("SIPETA STOPPED");
            println!("Starting SIPETA...");
        }
        "start" => {
            let app_data = match init_app_data() {
                Ok(paths) => paths,
                Err(e) => {
                    eprintln!("Gagal inisialisasi AppData: {}", e);
                    std::process::exit(1);
                }
            };
            if crate::server::is_server_healthy(port) {
                let network_info = discover_best_url(port, Some(&app_data.root));
                let target_url = format!("{}/admin", network_info.best_url);
                println!("SIPETA RUNNING");
                println!("URL: {}", target_url);
                let _ = open_default_browser(&target_url);
                std::process::exit(0);
            }
            println!("Starting SIPETA...");
        }
        _ => {
            // Default "launch" or double-click GUI mode:
            // If already healthy, immediately open browser and exit
            if crate::server::is_server_healthy(port) {
                let app_data = init_app_data().ok();
                let network_info = discover_best_url(port, app_data.as_ref().map(|d| d.root.as_path()));
                let target_url = format!("{}/admin", network_info.best_url);
                boot_log(&format!("[BOOT] SIPETA server sudah aktif. Membuka: {}", target_url));
                let _ = open_default_browser(&target_url);
                std::process::exit(0);
            }
        }
    }

    boot_log("==================================================");
    boot_log("[BOOT] SIPETA Desktop Launcher starting (Background Mode)...");

    if let Ok(exe) = std::env::current_exe() {
        boot_log(&format!("[BOOT] executable path: {}", exe.display()));
    } else {
        boot_error("Failed to resolve current_exe path");
    }

    if let Ok(cwd) = std::env::current_dir() {
        boot_log(&format!("[BOOT] current directory: {}", cwd.display()));
    }

    boot_log(&format!("[BOOT] target port: {}", port));

    // 1. Initialize App Data directory
    boot_log("[BOOT] initializing app data");
    let app_data = match init_app_data() {
        Ok(paths) => {
            boot_log(&format!("[BOOT] app data directory: {}", paths.root.display()));
            boot_log(&format!("[BOOT] database path: {}", paths.database.display()));
            boot_log(&format!("[BOOT] storage path: {}", paths.storage.display()));
            paths
        }
        Err(e) => {
            boot_error(&format!("Gagal inisialisasi AppData: {}", e));
            std::process::exit(1);
        }
    };

    // 2. Acquire Single Instance Lock
    boot_log("[BOOT] checking single-instance lock");
    let lock = match SingleInstanceLock::acquire(&app_data.lock_file, port) {
        Ok(l) => {
            boot_log("[BOOT] single-instance lock acquired successfully");
            l
        }
        Err(e) => {
            boot_log(&format!("[BOOT] SIPETA single-instance: {}. Membuka browser ke server aktif...", e));
            let network_info = discover_best_url(port, Some(&app_data.root));
            let target_url = format!("{}/admin", network_info.best_url);
            let _ = open_default_browser(&target_url);
            std::process::exit(0);
        }
    };

    // 3. Initialize Server Manager (Resolving project root and PHP binary)
    boot_log("[BOOT] resolving project root and PHP path");
    let mut server = match ServerManager::new(port) {
        Ok(s) => {
            boot_log(&format!("[BOOT] Laravel root path: {}", s.project_root.display()));
            boot_log(&format!("[BOOT] resolved PHP path: {}", s.php_bin.display()));
            s
        }
        Err(e) => {
            boot_error(&format!("Gagal konfigurasi server: {}", e));
            std::process::exit(1);
        }
    };

    // 4. Prepare writable runtime directories (bootstrap/cache, storage/framework/...)
    if let Err(e) = crate::app_data::prepare_writable_runtime_dirs(&server.project_root, &app_data) {
        boot_error(&format!("Gagal menyiapkan direktori writable runtime: {}", e));
        std::process::exit(1);
    }

    // 5. Prepare Database & Run Migrations
    boot_log("[BOOT] preparing database (migrations / seeders)");
    if let Err(e) = server.prepare_database(&app_data) {
        boot_error(&format!("Database initialization failed:\n{}", e));
        std::process::exit(1);
    }

    // 6. Start PHP Built-in Server (bind 0.0.0.0:PORT)
    boot_log("[BOOT] server manager start - spawning PHP");
    if let Err(e) = server.start(&app_data) {
        boot_error(&format!("Gagal menjalankan PHP server: {}", e));
        std::process::exit(1);
    }

    // 7. Start mDNS Responder (supplement Avahi)
    let mdns = if let Some(ip) = get_primary_lan_ip() {
        MdnsResponder::start(ip)
    } else {
        None
    };

    // 8. Discover best URL with priority: sipeta -> sipeta.local -> LAN IP -> localhost
    boot_log("[BOOT] health check and URL discovery");
    let network_info = discover_best_url(port, Some(&app_data.root));

    boot_log("==================================================");
    boot_log("[BOOT] SIPETA SERVER AKTIF (BACKGROUND TRAY MODE)");
    boot_log(&format!("[BOOT] Best URL:         {}", network_info.best_url));
    boot_log(&format!("[BOOT] Hostname URL:     {}", network_info.hostname_url));
    boot_log(&format!("[BOOT] mDNS URL:         {}", network_info.mdns_url));
    if !network_info.lan_ip.is_empty() {
        boot_log(&format!("[BOOT] LAN IP:           {}", network_info.lan_ip));
        boot_log(&format!("[BOOT] LAN URL:          {}", network_info.lan_url));
    }
    boot_log(&format!("[BOOT] Localhost URL:    {}", network_info.local_url));
    boot_log("==================================================");

    // 9. Open default browser
    let target_browser_url = format!("{}/admin", network_info.best_url);
    if action == "start" || action == "restart" {
        println!("SIPETA RUNNING");
        println!("URL: {}", target_browser_url);
    }
    boot_log(&format!("[BOOT] browser dispatch to: {}", target_browser_url));
    if let Err(e) = open_default_browser(&target_browser_url) {
        boot_error(&format!("Peringatan membuka browser otomatis: {}", e));
    }

    boot_log("[BOOT] initializing system tray icon");
    let best_url = network_info.best_url.clone();
    let app_state = AppState {
        _lock: lock,
        server: Mutex::new(server),
        _mdns: mdns,
        network_info: network_info.clone(),
    };

    let builder_result = tauri::Builder::default()
        .manage(app_state)
        .invoke_handler(tauri::generate_handler![
            get_server_info,
            open_browser_url,
            stop_server_and_exit
        ])
        .setup(move |app| {
            boot_log("[BOOT] setting up system tray menu");

            let open_i = MenuItem::with_id(app, "open_browser", "Buka SIPETA", true, None::<&str>)?;
            let quit_i = MenuItem::with_id(app, "quit", "Keluar", true, None::<&str>)?;
            let menu = Menu::with_items(app, &[&open_i, &quit_i])?;

            let target_url_clone = format!("{}/admin", best_url);
            let tray_builder = TrayIconBuilder::new()
                .tooltip("SIPETA Server")
                .menu(&menu)
                .menu_on_left_click(false)
                .on_menu_event(move |app_handle, event| match event.id.as_ref() {
                    "open_browser" => {
                        let _ = open_default_browser(&target_url_clone);
                    }
                    "quit" => {
                        boot_log("[TRAY] Keluar dipilih. Menghentikan server dan keluar...");
                        let state = app_handle.state::<AppState>();
                        let _ = state.server.lock().map(|mut s| s.stop());
                        app_handle.exit(0);
                    }
                    _ => {}
                })
                .on_tray_icon_event({
                    let target_url_clone2 = format!("{}/admin", best_url);
                    move |_tray, event| {
                        if let TrayIconEvent::DoubleClick { .. } | TrayIconEvent::Click { button: tauri::tray::MouseButton::Left, .. } = event {
                            let _ = open_default_browser(&target_url_clone2);
                        }
                    }
                });

            if let Some(icon) = app.default_window_icon() {
                let _ = tray_builder.icon(icon.clone()).build(app)?;
            } else {
                let _ = tray_builder.build(app)?;
            }

            boot_log("[BOOT] SIPETA running in system tray successfully");
            Ok(())
        })
        .run(tauri::generate_context!());

    if let Err(e) = builder_result {
        boot_error(&format!("Tauri application run error: {}", e));
        std::process::exit(1);
    }
}


