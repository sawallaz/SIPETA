use std::fs;
use std::path::{Path, PathBuf};

pub struct SingleInstanceLock {
    lock_path: PathBuf,
}

impl SingleInstanceLock {
    pub fn acquire(lock_path: &Path, port: u16) -> Result<Self, String> {
        // Source of Truth: Is the server actually running and healthy on the port?
        if crate::server::is_server_healthy(port) {
            return Err("Server SIPETA sudah berjalan dan aktif.".to_string());
        }

        // If the server is not healthy, any existing lockfile is stale; clean it up.
        if lock_path.exists() {
            let _ = fs::remove_file(lock_path);
        }

        let my_pid = std::process::id();
        let _ = fs::write(lock_path, my_pid.to_string());

        Ok(SingleInstanceLock {
            lock_path: lock_path.to_path_buf(),
        })
    }
}

impl Drop for SingleInstanceLock {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.lock_path);
    }
}

