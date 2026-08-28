<div
    x-data="{
        uploading: false,
        progress: 0,
        errorMessage: null,

        triggerMobileCamera() {
            this.$refs.cameraInput.click();
        },

        handleCameraFile(event) {
            const file = event.target.files[0];
            if (!file) return;

            if (!file.type.match('image.*')) {
                this.errorMessage = 'File harus berupa gambar JPG atau PNG.';
                return;
            }

            this.errorMessage = null;
            this.uploading = true;
            this.progress = 0;

            @this.upload(
                'data.kk_photo',
                file,
                () => {
                    this.uploading = false;
                    this.progress = 100;
                },
                () => {
                    this.uploading = false;
                    this.errorMessage = 'Gagal memproses foto kamera.';
                },
                (event) => {
                    this.progress = event.detail.progress;
                }
            );

            event.target.value = '';
        }
    }"
    class="flex items-center gap-2 py-0.5"
>
    <!-- Native Mobile Camera Input -->
    <input
        type="file"
        x-ref="cameraInput"
        accept="image/*"
        capture="environment"
        @change="handleCameraFile"
        class="hidden"
    >

    <button
        type="button"
        @click="triggerMobileCamera()"
        title="Ambil Foto langsung via Kamera HP"
        class="inline-flex items-center gap-1.5 rounded-md border border-gray-300 bg-white px-2.5 py-1 text-xs font-medium text-gray-700 shadow-sm transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
    >
        <span class="text-sm">📷</span>
        <span>Kamera</span>
    </button>

    <!-- Upload Progress Indicator -->
    <span x-show="uploading" x-cloak class="text-xs font-medium text-primary-600 dark:text-primary-400" x-text="'Memproses foto... ' + progress + '%'"></span>

    <!-- Error Alert -->
    <span x-show="errorMessage" x-cloak class="text-xs font-medium text-red-600 dark:text-red-400" x-text="errorMessage"></span>
</div>
