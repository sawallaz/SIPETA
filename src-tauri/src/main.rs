// Prevents additional console window on Windows in release, DO NOT REMOVE!!
#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

#[cfg(target_os = "windows")]
extern "system" {
    fn AttachConsole(dwProcessId: u32) -> i32;
}

fn main() {
    #[cfg(target_os = "windows")]
    unsafe {
        // Attach to parent process console (e.g. PowerShell, CMD) if available
        // ATTACH_PARENT_PROCESS = 0xFFFFFFFF
        AttachConsole(0xFFFFFFFF);
    }

    sipeta_lib::run();
}

