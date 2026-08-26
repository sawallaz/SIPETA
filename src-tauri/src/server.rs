use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::path::{Path, PathBuf};
use std::process::{Child, Command, Stdio};
use std::thread;
use std::time::{Duration, Instant};

use crate::app_data::AppDataPaths;
use crate::boot_log;

pub struct ServerManager {
    pub project_root: PathBuf,
    pub php_bin: PathBuf,
    pub tesseract_bin: PathBuf,
    pub tessdata_dir: PathBuf,
    pub port: u16,
    pub child: Option<Child>,
}

impl ServerManager {
    pub fn new(port: u16) -> Result<Self, String> {
        let project_root = find_project_root()?;
        let php_bin = find_php_binary(&project_root)?;
        let (tesseract_bin, tessdata_dir) = find_tesseract(&project_root);
        boot_log(&format!(
            "[BOOT] Tesseract binary: {} (exists: {})",
            tesseract_bin.display(),
            tesseract_bin.exists()
        ));
        boot_log(&format!(
            "[BOOT] Tessdata directory: {} (exists: {}, ind.traineddata: {})",
            tessdata_dir.display(),
            tessdata_dir.exists(),
            tessdata_dir.join("ind.traineddata").exists()
        ));

        Ok(ServerManager {
            project_root,
            php_bin,
            tesseract_bin,
            tessdata_dir,
            port,
            child: None,
        })
    }

    pub fn prepare_database(&self, app_data: &AppDataPaths) -> Result<(), String> {
        boot_log(&format!(
            "[BOOT] Menyiapkan database SQLite di: {} (First run: {})",
            app_data.database.display(),
            app_data.is_first_run
        ));

        let artisan_path = self.project_root.join("artisan");
        boot_log(&format!(
            "[BOOT] PHP executable: {} (exists: {})",
            self.php_bin.display(),
            self.php_bin.exists()
        ));
        boot_log(&format!(
            "[BOOT] Laravel artisan: {} (exists: {})",
            artisan_path.display(),
            artisan_path.exists()
        ));

        if !self.php_bin.exists() {
            return Err(format!("PHP binary tidak ditemukan di: {}", self.php_bin.display()));
        }
        if !artisan_path.exists() {
            return Err(format!("Artisan script tidak ditemukan di: {}", artisan_path.display()));
        }

        if app_data.is_first_run {
            // Run migrations
            boot_log("[BOOT] First run: menjalankan php artisan migrate --force");
            let mut migrate_cmd = Command::new(&self.php_bin);
            migrate_cmd.current_dir(&self.project_root);
            if let Some(ini) = find_php_ini(&self.php_bin, &self.project_root) {
                migrate_cmd.arg("-c").arg(&ini);
                if let Some(parent) = ini.parent() {
                    let ext_dir = parent.join("ext");
                    if ext_dir.exists() {
                        migrate_cmd.arg("-d").arg(format!("extension_dir={}", ext_dir.display()));
                    }
                }
            }
            migrate_cmd
                .arg("artisan")
                .arg("migrate")
                .arg("--force")
                .env("APP_NAME", "SIPETA")
                .env("APP_ENV", "production")
                .env("APP_KEY", "base64:LY8ZE0zYet/zuyCnO6OO+I+P5IykjlJJ4HY0I/IfCKk=")
                .env("APP_DEBUG", "false")
                .env("APP_URL", format!("http://localhost:{}", self.port))
                .env("DB_CONNECTION", "sqlite")
                .env("DB_DATABASE", &app_data.database)
                .env("LARAVEL_STORAGE_PATH", &app_data.storage)
                .env("DB_JOURNAL_MODE", "WAL")
                .env("DB_BUSY_TIMEOUT", "5000")
                .env("SESSION_DRIVER", "database")
                .env("CACHE_STORE", "database")
                .env("QUEUE_CONNECTION", "database")
                .env("FILESYSTEM_DISK", "local")
                .stdout(Stdio::piped())
                .stderr(Stdio::piped());

            #[cfg(target_os = "windows")]
            {
                use std::os::windows::process::CommandExt;
                migrate_cmd.creation_flags(0x08000000); // CREATE_NO_WINDOW
            }

            let output = migrate_cmd
                .output()
                .map_err(|e| format!("Gagal menjalankan php artisan migrate: {}", e))?;

            if !output.status.success() {
                let stderr = String::from_utf8_lossy(&output.stderr);
                let stdout = String::from_utf8_lossy(&output.stdout);
                return Err(format!(
                    "Migrasi database gagal (exit code {}):\n{}\n{}",
                    output.status, stdout, stderr
                ));
            }
            boot_log("[BOOT] Migrasi database berhasil.");

            // Run system reference seeders ONLY on first run
            boot_log("[BOOT] Inisialisasi first-run: menjalankan seeder referensi sistem (Settings, Agama, Pendidikan, Pekerjaan)...");
            let mut seed_cmd = Command::new(&self.php_bin);
            seed_cmd.current_dir(&self.project_root);
            if let Some(ini) = find_php_ini(&self.php_bin, &self.project_root) {
                seed_cmd.arg("-c").arg(&ini);
                if let Some(parent) = ini.parent() {
                    let ext_dir = parent.join("ext");
                    if ext_dir.exists() {
                        seed_cmd.arg("-d").arg(format!("extension_dir={}", ext_dir.display()));
                    }
                }
            }
            seed_cmd
                .arg("artisan")
                .arg("db:seed")
                .arg("--class=SystemReferenceSeeder")
                .arg("--force")
                .env("APP_NAME", "SIPETA")
                .env("APP_ENV", "production")
                .env("APP_KEY", "base64:LY8ZE0zYet/zuyCnO6OO+I+P5IykjlJJ4HY0I/IfCKk=")
                .env("APP_DEBUG", "false")
                .env("APP_URL", format!("http://localhost:{}", self.port))
                .env("DB_CONNECTION", "sqlite")
                .env("DB_DATABASE", &app_data.database)
                .env("LARAVEL_STORAGE_PATH", &app_data.storage)
                .env("DB_JOURNAL_MODE", "WAL")
                .env("DB_BUSY_TIMEOUT", "5000")
                .env("SESSION_DRIVER", "database")
                .env("CACHE_STORE", "database")
                .env("QUEUE_CONNECTION", "database")
                .env("FILESYSTEM_DISK", "local")
                .stdout(Stdio::piped())
                .stderr(Stdio::piped());

            #[cfg(target_os = "windows")]
            {
                use std::os::windows::process::CommandExt;
                seed_cmd.creation_flags(0x08000000); // CREATE_NO_WINDOW
            }

            let seed_output = seed_cmd
                .output()
                .map_err(|e| format!("Gagal menjalankan php artisan db:seed: {}", e))?;

            if !seed_output.status.success() {
                let stderr = String::from_utf8_lossy(&seed_output.stderr);
                let stdout = String::from_utf8_lossy(&seed_output.stdout);
                return Err(format!(
                    "Seeder database gagal (exit code {}):\n{}\n{}",
                    seed_output.status, stdout, stderr
                ));
            }
            boot_log("[BOOT] Inisialisasi seeder referensi sistem selesai.");
        } else {
            boot_log("[BOOT] Existing database terdeteksi: melewati seeder untuk menjaga integritas data penduduk.");
        }

        Ok(())
    }

    pub fn start(&mut self, app_data: &AppDataPaths) -> Result<(), String> {
        let server_script = self.project_root.join("server.php");
        let public_dir = self.project_root.join("public");

        boot_log(&format!(
            "[BOOT] server.php: {} (exists: {})",
            server_script.display(),
            server_script.exists()
        ));
        boot_log(&format!(
            "[BOOT] public dir: {} (exists: {})",
            public_dir.display(),
            public_dir.exists()
        ));

        if !server_script.exists() {
            return Err(format!(
                "File server.php tidak ditemukan di: {}",
                server_script.display()
            ));
        }

        let bind_addr = format!("0.0.0.0:{}", self.port);
        boot_log(&format!(
            "[BOOT] Memulai PHP built-in web server: {} -S {} -t {} {}",
            self.php_bin.display(),
            bind_addr,
            public_dir.display(),
            server_script.display()
        ));

        let mut cmd = Command::new(&self.php_bin);
        cmd.current_dir(&self.project_root);
        if let Some(ini) = find_php_ini(&self.php_bin, &self.project_root) {
            cmd.arg("-c").arg(&ini);
            boot_log(&format!("[BOOT] PHP server configured with php.ini: {}", ini.display()));
            if let Some(parent) = ini.parent() {
                let ext_dir = parent.join("ext");
                if ext_dir.exists() {
                    cmd.arg("-d").arg(format!("extension_dir={}", ext_dir.display()));
                    boot_log(&format!("[BOOT] PHP server configured with extension_dir: {}", ext_dir.display()));
                }
            }
        }
        cmd.arg("-d")
            .arg("upload_max_filesize=32M")
            .arg("-d")
            .arg("post_max_size=32M")
            .arg("-d")
            .arg("memory_limit=256M")
            .arg("-d")
            .arg("max_execution_time=120")
            .arg("-d")
            .arg("max_input_time=120")
            .arg("-S")
            .arg(&bind_addr)
            .arg("-t")
            .arg(&public_dir)
            .arg(&server_script)
            .env("APP_NAME", "SIPETA")
            .env("APP_ENV", "production")
            .env("APP_KEY", "base64:LY8ZE0zYet/zuyCnO6OO+I+P5IykjlJJ4HY0I/IfCKk=")
            .env("APP_DEBUG", "false")
            .env("APP_URL", format!("http://localhost:{}", self.port))
            .env("SIPETA_PORT", self.port.to_string())
            .env("DB_CONNECTION", "sqlite")
            .env("DB_DATABASE", &app_data.database)
            .env("LARAVEL_STORAGE_PATH", &app_data.storage)
            .env("DB_JOURNAL_MODE", "WAL")
            .env("DB_BUSY_TIMEOUT", "5000")
            .env("SESSION_DRIVER", "database")
            .env("SESSION_LIFETIME", "120")
            .env("SESSION_ENCRYPT", "false")
            .env("SESSION_PATH", "/")
            .env("SESSION_SECURE_COOKIE", "false")
            .env("SESSION_HTTP_ONLY", "true")
            .env("FILESYSTEM_DISK", "local")
            .env("GOOGLE_CLIENT_ID", "825838256749-rr3h1209q1it62t68qrfffal5iqptm97.apps.googleusercontent.com")
            .env("GOOGLE_CLIENT_SECRET", "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")
            .env("GOOGLE_REDIRECT_URI", "http://localhost:8100/admin/backup/google/callback")
            .env("TESSERACT_PATH", &self.tesseract_bin)
            .env("TESSDATA_PREFIX", &self.tessdata_dir);

        #[cfg(target_os = "windows")]
        {
            ensure_windows_firewall_rules();
        }

        let log_file_path = app_data.storage.join("logs/php_server.log");
        let log_file = std::fs::OpenOptions::new()
            .create(true)
            .append(true)
            .open(&log_file_path)
            .map_err(|e| format!("Gagal membuka file log server {}: {}", log_file_path.display(), e))?;
        let log_file_err = log_file.try_clone().map_err(|e| e.to_string())?;

        cmd.stdout(Stdio::from(log_file));
        cmd.stderr(Stdio::from(log_file_err));

        #[cfg(target_os = "windows")]
        {
            use std::os::windows::process::CommandExt;
            cmd.creation_flags(0x08000000); // CREATE_NO_WINDOW
        }

        #[cfg(target_os = "linux")]
        {
            use std::os::unix::process::CommandExt;
            unsafe {
                cmd.pre_exec(|| {
                    libc::prctl(libc::PR_SET_PDEATHSIG, libc::SIGTERM);
                    Ok(())
                });
            }
        }

        let child = cmd
            .spawn()
            .map_err(|e| format!("Gagal menjalankan PHP web server: {}", e))?;

        let pid = child.id();
        boot_log(&format!("[BOOT] PHP process spawned successfully (PID: {})", pid));
        let pid_file = app_data.root.join("sipeta.pid");
        let _ = std::fs::write(&pid_file, pid.to_string());

        self.child = Some(child);

        // Wait for health endpoint to respond
        self.wait_for_health(30, &log_file_path)?;

        Ok(())
    }

    pub fn wait_for_health(&mut self, timeout_seconds: u64, log_file_path: &Path) -> Result<(), String> {
        let start = Instant::now();
        let timeout = Duration::from_secs(timeout_seconds);
        let health_host = format!("127.0.0.1:{}", self.port);
        let sock_addr: SocketAddr = health_host
            .parse()
            .map_err(|e| format!("Invalid socket address {}: {}", health_host, e))?;

        boot_log(&format!(
            "[BOOT] Polling health check endpoint http://{}/health (timeout: {}s)...",
            health_host, timeout_seconds
        ));

        let mut attempt = 0;
        while start.elapsed() < timeout {
            attempt += 1;
            // Check if PHP child process exited prematurely
            if let Some(ref mut child) = self.child {
                if let Ok(Some(status)) = child.try_wait() {
                    let log_content = std::fs::read_to_string(log_file_path).unwrap_or_default();
                    let recent_logs: Vec<&str> = log_content.lines().rev().take(20).collect();
                    return Err(format!(
                        "PHP server berhenti mendadak dengan status: {}.\nLog terakhir:\n{}",
                        status, recent_logs.into_iter().rev().collect::<Vec<&str>>().join("\n")
                    ));
                }
            }

            if let Ok(mut stream) = TcpStream::connect_timeout(&sock_addr, Duration::from_millis(1000)) {
                let request = format!(
                    "GET /health HTTP/1.1\r\nHost: {}\r\nConnection: close\r\nUser-Agent: SIPETA-Launcher/1.0\r\n\r\n",
                    health_host
                );
                if stream.write_all(request.as_bytes()).is_ok() {
                    let _ = stream.set_read_timeout(Some(Duration::from_millis(2000)));
                    let mut response = [0u8; 1024];
                    if let Ok(n) = stream.read(&mut response) {
                        if n > 0 {
                            let resp_str = String::from_utf8_lossy(&response[..n]);
                            if resp_str.contains("200 OK") || resp_str.contains("HTTP/1.1 200") {
                                boot_log(&format!("[BOOT] SIPETA server health check: OK (200) on attempt {}", attempt));
                                return Ok(());
                            } else {
                                boot_log(&format!("[BOOT] Health check status (attempt {}): {}", attempt, resp_str.lines().next().unwrap_or("")));
                            }
                        }
                    }
                }
            }

            thread::sleep(Duration::from_millis(300));
        }

        let log_content = std::fs::read_to_string(log_file_path).unwrap_or_default();
        let recent_logs: Vec<&str> = log_content.lines().rev().take(20).collect();

        Err(format!(
            "Server SIPETA tidak merespons health check dalam {} detik.\nLog terakhir:\n{}",
            timeout_seconds, recent_logs.into_iter().rev().collect::<Vec<&str>>().join("\n")
        ))
    }

    pub fn stop(&mut self) {
        if let Some(mut child) = self.child.take() {
            boot_log(&format!("[BOOT] Menghentikan PHP server PID {}...", child.id()));

            #[cfg(unix)]
            {
                unsafe {
                    libc::kill(child.id() as libc::pid_t, libc::SIGTERM);
                }
            }

            #[cfg(windows)]
            {
                let _ = child.kill();
            }

            // Wait up to 5s for graceful termination
            let start = Instant::now();
            let mut exited = false;
            while start.elapsed() < Duration::from_secs(5) {
                if let Ok(Some(_)) = child.try_wait() {
                    exited = true;
                    break;
                }
                thread::sleep(Duration::from_millis(100));
            }

            if !exited {
                let _ = child.kill();
                let _ = child.wait();
            }

            boot_log("[BOOT] PHP server telah dihentikan.");
        }
    }
}

impl Drop for ServerManager {
    fn drop(&mut self) {
        self.stop();
    }
}

fn is_valid_laravel_root(p: &Path) -> bool {
    p.join("artisan").exists() && p.join("server.php").exists()
}

fn find_project_root() -> Result<PathBuf, String> {
    // 1. Check environment variable override
    if let Ok(custom) = std::env::var("SIPETA_PROJECT_ROOT") {
        let p = PathBuf::from(&custom);
        if is_valid_laravel_root(&p) {
            boot_log(&format!("[BOOT] Laravel root found via SIPETA_PROJECT_ROOT: {}", p.display()));
            return Ok(p);
        }
    }

    // 2. Check executable directory and its subdirectories (especially _up_ for Tauri NSIS bundles)
    if let Ok(exe) = std::env::current_exe() {
        if let Some(exe_dir) = exe.parent() {
            let exe_candidates = [
                exe_dir.join("_up_"),                 // Tauri NSIS bundle for ../ resources
                exe_dir.join("resources/_up_"),       // Tauri standard resource subfolder
                exe_dir.join("resources/app"),
                exe_dir.join("resources"),
                exe_dir.join("app"),
                exe_dir.to_path_buf(),
            ];
            for cand in &exe_candidates {
                if is_valid_laravel_root(cand) {
                    boot_log(&format!("[BOOT] Laravel root found relative to exe: {}", cand.display()));
                    return Ok(cand.clone());
                }
            }

            if let Some(prefix) = exe_dir.parent() {
                let prefix_candidates = [
                    prefix.join("_up_"),
                    prefix.join("lib/sipeta"),
                    prefix.join("share/sipeta"),
                    prefix.join("share/id.sipeta.desktop"),
                    prefix.join("resources/app"),
                    prefix.join("resources/_up_"),
                    prefix.join("resources"),
                    prefix.join("lib/id.sipeta.desktop"),
                ];
                for cand in &prefix_candidates {
                    if is_valid_laravel_root(cand) {
                        boot_log(&format!("[BOOT] Laravel root found in prefix: {}", cand.display()));
                        return Ok(cand.clone());
                    }
                }
            }
        }
    }

    // 3. Check current working directory and parent
    let cwd = std::env::current_dir().unwrap_or_else(|_| PathBuf::from("."));
    if is_valid_laravel_root(&cwd) {
        boot_log(&format!("[BOOT] Laravel root found in current_dir: {}", cwd.display()));
        return Ok(cwd);
    }
    if let Some(parent) = cwd.parent() {
        if is_valid_laravel_root(parent) {
            boot_log(&format!("[BOOT] Laravel root found in parent dir: {}", parent.display()));
            return Ok(parent.to_path_buf());
        }
    }
    if is_valid_laravel_root(&cwd.join("_up_")) {
        boot_log(&format!("[BOOT] Laravel root found in cwd/_up_: {}", cwd.join("_up_").display()));
        return Ok(cwd.join("_up_"));
    }

    // 4. Check user home & documents directory
    if let Some(doc_dir) = dirs::document_dir() {
        let candidate = doc_dir.join("SIPETA");
        if is_valid_laravel_root(&candidate) {
            boot_log(&format!("[BOOT] Laravel root found in documents: {}", candidate.display()));
            return Ok(candidate);
        }
    }
    if let Some(home_dir) = dirs::home_dir() {
        let candidates = [
            home_dir.join("Documents/SIPETA"),
            home_dir.join("SIPETA"),
            home_dir.join(".local/share/SIPETA/app"),
            home_dir.join("AppData/Local/SIPETA/_up_"),
            home_dir.join("AppData/Local/SIPETA"),
        ];
        for cand in &candidates {
            if is_valid_laravel_root(cand) {
                boot_log(&format!("[BOOT] Laravel root found in home candidate: {}", cand.display()));
                return Ok(cand.clone());
            }
        }
    }

    // 5. Check standard system install directories
    let system_paths = [
        PathBuf::from("/usr/lib/sipeta"),
        PathBuf::from("/usr/share/sipeta"),
        PathBuf::from("/opt/sipeta"),
        PathBuf::from("/usr/lib/id.sipeta.desktop"),
        PathBuf::from("C:\\Program Files\\SIPETA"),
        PathBuf::from("C:\\Program Files\\SIPETA\\_up_"),
        PathBuf::from("C:\\Program Files (x86)\\SIPETA"),
        PathBuf::from("C:\\Program Files (x86)\\SIPETA\\_up_"),
        PathBuf::from("C:\\SIPETA"),
        PathBuf::from("C:\\SIPETA\\_up_"),
    ];
    for p in &system_paths {
        if is_valid_laravel_root(p) {
            boot_log(&format!("[BOOT] Laravel root found in system path: {}", p.display()));
            return Ok(p.clone());
        }
    }

    Err(format!(
        "Direktori project SIPETA tidak ditemukan. CWD: {}, Exe: {:?}",
        cwd.display(),
        std::env::current_exe()
    ))
}

fn find_php_binary(project_root: &Path) -> Result<PathBuf, String> {
    if let Ok(custom) = std::env::var("SIPETA_PHP_PATH") {
        if Path::new(&custom).exists() {
            boot_log(&format!("[BOOT] PHP binary found via SIPETA_PHP_PATH: {}", custom));
            return Ok(PathBuf::from(custom));
        }
    }

    // 1. Check inside project_root (e.g. _up_/resources/php/php.exe)
    let candidates = [
        project_root.join("resources/php/php.exe"),
        project_root.join("resources/php/php"),
        project_root.join("php/php.exe"),
        project_root.join("php/php"),
        project_root.join("php.exe"),
        project_root.join("php"),
    ];
    for cand in &candidates {
        if cand.exists() {
            boot_log(&format!("[BOOT] PHP binary found in project_root: {}", cand.display()));
            return Ok(cand.clone());
        }
    }

    // 2. Check relative to current executable
    if let Ok(exe) = std::env::current_exe() {
        if let Some(exe_dir) = exe.parent() {
            let exe_candidates = [
                exe_dir.join("_up_/resources/php/php.exe"),
                exe_dir.join("_up_/resources/php/php"),
                exe_dir.join("resources/php/php.exe"),
                exe_dir.join("resources/php/php"),
                exe_dir.join("php/php.exe"),
                exe_dir.join("php/php"),
                exe_dir.join("php.exe"),
                exe_dir.join("php"),
            ];
            for cand in &exe_candidates {
                if cand.exists() {
                    boot_log(&format!("[BOOT] PHP binary found relative to exe: {}", cand.display()));
                    return Ok(cand.clone());
                }
            }
        }
    }

    // 3. Platform specific fallbacks
    #[cfg(target_os = "windows")]
    {
        let win_standard_paths = [
            "C:\\php\\php.exe",
            "C:\\tools\\php\\php.exe",
            "C:\\Program Files\\PHP\\php.exe",
            "C:\\Program Files (x86)\\PHP\\php.exe",
            "C:\\laragon\\bin\\php\\current\\php.exe",
            "C:\\xampp\\php\\php.exe",
        ];
        for path in &win_standard_paths {
            if Path::new(path).exists() {
                boot_log(&format!("[BOOT] PHP binary found in Windows standard path: {}", path));
                return Ok(PathBuf::from(path));
            }
        }

        if let Ok(output) = Command::new("where").arg("php.exe").output() {
            if output.status.success() {
                let path_str = String::from_utf8_lossy(&output.stdout).trim().to_string();
                if let Some(first_line) = path_str.lines().next() {
                    if !first_line.trim().is_empty() {
                        boot_log(&format!("[BOOT] PHP binary found via 'where php.exe': {}", first_line.trim()));
                        return Ok(PathBuf::from(first_line.trim()));
                    }
                }
            }
        }

        Ok(PathBuf::from("php.exe"))
    }

    #[cfg(not(target_os = "windows"))]
    {
        let standard_php_paths = [
            "/usr/bin/php",
            "/bin/php",
            "/usr/local/bin/php",
        ];
        for path in &standard_php_paths {
            if Path::new(path).exists() {
                return Ok(PathBuf::from(path));
            }
        }

        if let Ok(output) = Command::new("which").arg("php").output() {
            if output.status.success() {
                let path_str = String::from_utf8_lossy(&output.stdout).trim().to_string();
                if !path_str.is_empty() {
                    return Ok(PathBuf::from(path_str));
                }
            }
        }

        Ok(PathBuf::from("php"))
    }
}

fn find_php_ini(php_bin: &Path, project_root: &Path) -> Option<PathBuf> {
    if let Some(parent) = php_bin.parent() {
        let ini = parent.join("php.ini");
        if ini.exists() {
            return Some(ini);
        }
    }
    let candidates = [
        project_root.join("resources/php/php.ini"),
        project_root.join("php/php.ini"),
    ];
    for cand in &candidates {
        if cand.exists() {
            return Some(cand.clone());
        }
    }
    None
}

fn find_tesseract(project_root: &Path) -> (PathBuf, PathBuf) {
    if let Ok(custom) = std::env::var("TESSERACT_PATH") {
        if Path::new(&custom).exists() {
            let tessdata = std::env::var("TESSDATA_PREFIX")
                .map(PathBuf::from)
                .unwrap_or_else(|_| {
                    if let Some(parent) = Path::new(&custom).parent() {
                        let local_data = parent.join("tessdata");
                        if local_data.exists() {
                            return local_data;
                        }
                    }
                    PathBuf::from("/usr/share/tesseract-ocr/5/tessdata")
                });
            return (PathBuf::from(custom), tessdata);
        }
    }

    // 1. Check bundled in resources/tesseract/ relative to project_root
    let bundled_bin_exe = project_root.join("resources").join("tesseract").join("tesseract.exe");
    let bundled_bin = project_root.join("resources").join("tesseract").join("tesseract");
    let bundled_data = project_root.join("resources").join("tesseract").join("tessdata");

    if bundled_bin_exe.exists() && bundled_data.exists() {
        return (bundled_bin_exe, bundled_data);
    }
    if bundled_bin.exists() && bundled_data.exists() {
        return (bundled_bin, bundled_data);
    }

    // 2. Check sibling / child of exe directory
    if let Ok(exe) = std::env::current_exe() {
        if let Some(exe_dir) = exe.parent() {
            let candidates = [
                (exe_dir.join("resources").join("tesseract").join("tesseract.exe"), exe_dir.join("resources").join("tesseract").join("tessdata")),
                (exe_dir.join("_up_").join("resources").join("tesseract").join("tesseract.exe"), exe_dir.join("_up_").join("resources").join("tesseract").join("tessdata")),
                (exe_dir.join("tesseract").join("tesseract.exe"), exe_dir.join("tesseract").join("tessdata")),
                (exe_dir.join("resources").join("tesseract").join("tesseract"), exe_dir.join("resources").join("tesseract").join("tessdata")),
                (exe_dir.join("_up_").join("resources").join("tesseract").join("tesseract"), exe_dir.join("_up_").join("resources").join("tesseract").join("tessdata")),
            ];
            for (bin, data) in &candidates {
                if bin.exists() && data.exists() {
                    return (bin.clone(), data.clone());
                }
            }
        }
    }

    #[cfg(target_os = "windows")]
    {
        let win_tess_paths = [
            ("C:\\Program Files\\Tesseract-OCR\\tesseract.exe", "C:\\Program Files\\Tesseract-OCR\\tessdata"),
            ("C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe", "C:\\Program Files (x86)\\Tesseract-OCR\\tessdata"),
            ("C:\\Tesseract-OCR\\tesseract.exe", "C:\\Tesseract-OCR\\tessdata"),
        ];
        for (bin, data) in &win_tess_paths {
            if Path::new(bin).exists() && Path::new(data).exists() {
                return (PathBuf::from(bin), PathBuf::from(data));
            }
        }
        (PathBuf::from("tesseract.exe"), PathBuf::from("tessdata"))
    }

    #[cfg(not(target_os = "windows"))]
    {
        let bin = PathBuf::from("/bin/tesseract");
        let tessdata = if Path::new("/usr/share/tesseract-ocr/5/tessdata").exists() {
            PathBuf::from("/usr/share/tesseract-ocr/5/tessdata")
        } else {
            PathBuf::from("/usr/share/tessdata")
        };

        (bin, tessdata)
    }
}

#[cfg(target_os = "windows")]
pub fn ensure_windows_firewall_rules() {
    use std::os::windows::process::CommandExt;
    let _ = Command::new("netsh")
        .args(["advfirewall", "firewall", "add", "rule", "name=SIPETA Web Server (Port 8100)", "dir=in", "action=allow", "protocol=TCP", "localport=8100", "profile=any"])
        .creation_flags(0x08000000)
        .output();
    let _ = Command::new("netsh")
        .args(["advfirewall", "firewall", "add", "rule", "name=SIPETA mDNS Discovery (UDP 5353)", "dir=in", "action=allow", "protocol=UDP", "localport=5353", "profile=any"])
        .creation_flags(0x08000000)
        .output();
}

pub fn is_port_listening(port: u16) -> bool {
    let addr_str = format!("127.0.0.1:{}", port);
    if let Ok(sock_addr) = addr_str.parse::<std::net::SocketAddr>() {
        if let Ok(_) = std::net::TcpStream::connect_timeout(&sock_addr, std::time::Duration::from_millis(200)) {
            return true;
        }
    }
    false
}

pub fn is_server_healthy(port: u16) -> bool {
    let addr_str = format!("127.0.0.1:{}", port);
    if let Ok(sock_addr) = addr_str.parse::<std::net::SocketAddr>() {
        if let Ok(mut stream) = std::net::TcpStream::connect_timeout(&sock_addr, std::time::Duration::from_millis(400)) {
            let request = format!(
                "GET /health HTTP/1.1\r\nHost: {}\r\nConnection: close\r\nUser-Agent: SIPETA-Health/1.0\r\n\r\n",
                addr_str
            );
            if stream.write_all(request.as_bytes()).is_ok() {
                let _ = stream.set_read_timeout(Some(std::time::Duration::from_millis(800)));
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
        }
    }
    false
}

#[cfg(target_os = "windows")]
pub fn is_sipeta_process(pid: u32) -> bool {
    use std::os::windows::process::CommandExt;
    if let Ok(output) = Command::new("wmic")
        .args(["process", "where", &format!("ProcessId={}", pid), "get", "CommandLine,ExecutablePath", "/format:list"])
        .creation_flags(0x08000000)
        .output()
    {
        let stdout = String::from_utf8_lossy(&output.stdout).to_lowercase();
        if stdout.contains("sipeta") || stdout.contains("server.php") || stdout.contains("artisan") {
            return true;
        }
    }
    if let Ok(output) = Command::new("tasklist")
        .args(["/FI", &format!("PID eq {}", pid), "/FO", "CSV", "/NH"])
        .creation_flags(0x08000000)
        .output()
    {
        let stdout = String::from_utf8_lossy(&output.stdout).to_lowercase();
        if stdout.contains("sipeta") || stdout.contains("php") {
            return true;
        }
    }
    false
}

#[cfg(not(target_os = "windows"))]
pub fn is_sipeta_process(pid: u32) -> bool {
    if let Ok(cmdline) = std::fs::read_to_string(format!("/proc/{}/cmdline", pid)) {
        let lower = cmdline.to_lowercase();
        return lower.contains("sipeta") || lower.contains("server.php") || lower.contains("artisan");
    }
    true
}

#[cfg(target_os = "windows")]
pub fn get_pids_listening_on_port(port: u16) -> Vec<u32> {
    use std::os::windows::process::CommandExt;
    let mut pids = Vec::new();
    if let Ok(output) = Command::new("netstat")
        .args(["-ano", "-p", "tcp"])
        .creation_flags(0x08000000)
        .output()
    {
        let stdout = String::from_utf8_lossy(&output.stdout);
        let port_pattern = format!(":{}", port);
        for line in stdout.lines() {
            if line.contains(&port_pattern) && line.to_uppercase().contains("LISTENING") {
                if let Some(last_token) = line.split_whitespace().last() {
                    if let Ok(pid) = last_token.parse::<u32>() {
                        if pid > 0 && !pids.contains(&pid) {
                            pids.push(pid);
                        }
                    }
                }
            }
        }
    }
    pids
}

#[cfg(not(target_os = "windows"))]
pub fn get_pids_listening_on_port(_port: u16) -> Vec<u32> {
    Vec::new()
}

pub fn stop_server_by_pid(app_data: &AppDataPaths, port: u16) {
    let my_pid = std::process::id();
    let pid_file = app_data.root.join("sipeta.pid");

    // 1. Terminate process recorded in sipeta.pid if verified as SIPETA/PHP
    if pid_file.exists() {
        if let Ok(content) = std::fs::read_to_string(&pid_file) {
            if let Ok(pid) = content.trim().parse::<u32>() {
                if pid != my_pid && pid > 0 && is_sipeta_process(pid) {
                    #[cfg(target_os = "windows")]
                    {
                        use std::os::windows::process::CommandExt;
                        let _ = Command::new("taskkill")
                            .args(["/F", "/T", "/PID", &pid.to_string()])
                            .creation_flags(0x08000000)
                            .output();
                    }
                    #[cfg(not(target_os = "windows"))]
                    {
                        unsafe {
                            libc::kill(pid as i32, libc::SIGTERM);
                        }
                        std::thread::sleep(std::time::Duration::from_millis(200));
                        unsafe {
                            libc::kill(pid as i32, libc::SIGKILL);
                        }
                    }
                }
            }
        }
    }

    // 2. Kill only verified SIPETA/PHP processes still holding the listening port
    let pids_on_port = get_pids_listening_on_port(port);
    for p in pids_on_port {
        if p != my_pid && p > 0 {
            if is_sipeta_process(p) {
                #[cfg(target_os = "windows")]
                {
                    use std::os::windows::process::CommandExt;
                    let _ = Command::new("taskkill")
                        .args(["/F", "/T", "/PID", &p.to_string()])
                        .creation_flags(0x08000000)
                        .output();
                }
                #[cfg(not(target_os = "windows"))]
                {
                    unsafe {
                        libc::kill(p as i32, libc::SIGKILL);
                    }
                }
            } else {
                eprintln!(
                    "Peringatan: Port {} sedang digunakan oleh proses lain (PID: {}). Proses bukan milik SIPETA dan tidak dihentikan.",
                    port, p
                );
            }
        }
    }

    // 3. Loop and wait up to 3 seconds until port is verified closed
    for _ in 0..30 {
        if !is_port_listening(port) && !is_server_healthy(port) {
            break;
        }
        std::thread::sleep(std::time::Duration::from_millis(100));
    }

    // 4. Remove all state files
    let _ = std::fs::remove_file(&pid_file);
    let _ = std::fs::remove_file(&app_data.lock_file);
    let _ = std::fs::remove_file(app_data.root.join("endpoint.url"));
}



