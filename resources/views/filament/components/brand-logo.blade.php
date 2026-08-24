@if (request()->routeIs('filament.admin.auth.login'))
    <div class="flex flex-col items-center justify-center text-center w-full">
        <img src="{{ asset('images/sipeta-logo.png') }}" alt="Logo SIPETA" class="h-28 w-auto object-contain mx-auto drop-shadow-md" />
        <span class="font-extrabold text-2xl tracking-tight text-[#1e3824] dark:text-white mt-3 leading-none">SIPETA</span>
        <span class="text-xs font-medium text-slate-600 dark:text-slate-300 tracking-wide mt-1.5 leading-snug max-w-xs">Sistem Informasi Pendataan Penduduk Tanete</span>
    </div>
@else
    <div class="flex items-center gap-2.5 fi-brand-logo-ctn">
        <img src="{{ asset('images/sipeta-icon.png') }}" alt="Logo SIPETA" class="h-8 w-8 object-contain drop-shadow-sm" />
        <div class="flex flex-col text-left leading-none">
            <div class="flex items-center gap-1.5">
                <span class="font-extrabold text-lg tracking-tight fi-brand-title leading-none">SIPETA</span>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[9px] font-bold fi-brand-badge">KELURAHAN</span>
            </div>
            <span class="text-[9px] font-medium fi-brand-sub mt-1">Sistem Informasi Pendataan Penduduk Tanete</span>
        </div>
    </div>
@endif
