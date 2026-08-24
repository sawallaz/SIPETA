use std::fs;
use std::path::{Path, PathBuf};

use crate::boot_log;

pub struct AppDataPaths {
    pub root: PathBuf,
    pub database: PathBuf,
    pub storage: PathBuf,
    pub backups: PathBuf,
    pub env_file: PathBuf,
    pub lock_file: PathBuf,
    pub is_first_run: bool,
}

pub fn resolve_app_data_dir() -> PathBuf {
    if let Ok(custom) = std::env::var("SIPETA_DATA_DIR") {
        if !custom.trim().is_empty() {
            return PathBuf::from(custom);
        }
    }

    dirs::data_dir()
        .map(|p| p.join("SIPETA"))
        .unwrap_or_else(|| {
            dirs::home_dir()
                .map(|p| p.join(".local/share/SIPETA"))
                .unwrap_or_else(|| PathBuf::from("data"))
        })
}

pub fn init_app_data() -> Result<AppDataPaths, String> {
    let root = resolve_app_data_dir();
    let db_dir = root.join("database");
    let database = db_dir.join("database.sqlite");
    let storage = root.join("storage");
    let backups = root.join("backups");
    let env_file = root.join(".env");
    let lock_file = root.join("sipeta.lock");

    let is_first_run = !database.exists();

    // Ensure all required directories exist
    let dirs_to_create = [
        &root,
        &db_dir,
        &backups,
        &storage,
        &storage.join("app/private"),
        &storage.join("app/private/livewire-tmp"),
        &storage.join("app/public"),
        &storage.join("app/kk_uploads"),
        &storage.join("app/ocr_temp"),
        &storage.join("app/livewire-tmp"),
        &storage.join("framework/cache/data"),
        &storage.join("framework/sessions"),
        &storage.join("framework/views"),
        &storage.join("logs"),
    ];

    for dir in dirs_to_create {
        fs::create_dir_all(dir).map_err(|e| {
            format!("Gagal membuat direktori {}: {}", dir.display(), e)
        })?;
    }

    // Touch database.sqlite if first run
    if is_first_run {
        if let Err(e) = fs::File::create(&database) {
            return Err(format!("Gagal membuat file database.sqlite: {}", e));
        }
    }

    // Generate .env if missing, or update if Google OAuth config missing
    if !env_file.exists() {
        let env_content = generate_default_env(&database, &storage);
        let _ = fs::write(&env_file, env_content);
    } else if let Ok(existing_env) = fs::read_to_string(&env_file) {
        let mut updated = existing_env.clone();
        let mut changed = false;
        if !existing_env.contains("GOOGLE_CLIENT_ID") {
            updated.push_str("\r\nGOOGLE_CLIENT_ID=825838256749-rr3h1209q1it62t68qrfffal5iqptm97.apps.googleusercontent.com\r\nGOOGLE_CLIENT_SECRET=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\r\nGOOGLE_REDIRECT_URI=http://localhost:8100/admin/backup/google/callback\r\n");
            changed = true;
        } else if !existing_env.contains("GOOGLE_REDIRECT_URI") {
            updated.push_str("\r\nGOOGLE_REDIRECT_URI=http://localhost:8100/admin/backup/google/callback\r\n");
            changed = true;
        }
        if changed {
            let _ = fs::write(&env_file, updated);
        }
    }

    Ok(AppDataPaths {
        root,
        database,
        storage,
        backups,
        env_file,
        lock_file,
        is_first_run,
    })
}

pub fn prepare_writable_runtime_dirs(project_root: &Path, app_data: &AppDataPaths) -> Result<(), String> {
    boot_log("[BOOT] preparing writable runtime directories");

    // 1. Ensure project_root/bootstrap/cache exists and is writable
    let bootstrap_cache = project_root.join("bootstrap/cache");
    fs::create_dir_all(&bootstrap_cache).map_err(|e| {
        format!("Gagal membuat bootstrap/cache di {}: {}", bootstrap_cache.display(), e)
    })?;

    let (bc_exists, bc_writable) = check_dir_writable(&bootstrap_cache);
    boot_log(&format!(
        "[BOOT] bootstrap/cache ready (exists={} writable={}) at {}",
        bc_exists, bc_writable, bootstrap_cache.display()
    ));

    // 2. Ensure all framework storage subdirectories exist and are writable
    let storage_cache = app_data.storage.join("framework/cache/data");
    let storage_sessions = app_data.storage.join("framework/sessions");
    let storage_views = app_data.storage.join("framework/views");
    let storage_logs = app_data.storage.join("logs");

    let storage_dirs = [
        ("storage/framework/cache", &storage_cache),
        ("storage/framework/sessions", &storage_sessions),
        ("storage/framework/views", &storage_views),
        ("storage/logs", &storage_logs),
    ];

    for (name, dir) in &storage_dirs {
        fs::create_dir_all(dir).map_err(|e| {
            format!("Gagal membuat direktori {}: {}", dir.display(), e)
        })?;
        let (exists, writable) = check_dir_writable(dir);
        boot_log(&format!("[BOOT] {} ready (exists={} writable={})", name, exists, writable));
    }

    Ok(())
}

fn check_dir_writable(dir: &Path) -> (bool, bool) {
    if !dir.exists() {
        return (false, false);
    }
    let test_file = dir.join(format!(".sipeta_write_test_{}", std::process::id()));
    match fs::write(&test_file, b"ok") {
        Ok(_) => {
            let _ = fs::remove_file(&test_file);
            (true, true)
        }
        Err(_) => (true, false),
    }
}

fn generate_default_env(db_path: &Path, storage_path: &Path) -> String {
    format!(
        r#"APP_NAME="SIPETA"
APP_ENV=production
APP_KEY=base64:LY8ZE0zYet/zuyCnO6OO+I+P5IykjlJJ4HY0I/IfCKk=
APP_DEBUG=false
APP_URL=http://localhost:8100
APP_LOCALE=id
APP_FALLBACK_LOCALE=id

DB_CONNECTION=sqlite
DB_DATABASE="{}"
LARAVEL_STORAGE_PATH="{}"
DB_JOURNAL_MODE=WAL
DB_BUSY_TIMEOUT=5000

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

GOOGLE_CLIENT_ID=825838256749-rr3h1209q1it62t68qrfffal5iqptm97.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=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
GOOGLE_REDIRECT_URI=http://localhost:8100/admin/backup/google/callback
"#,
        db_path.display(),
        storage_path.display()
    )
}



