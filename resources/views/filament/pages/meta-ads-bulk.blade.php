<x-filament::page>
    <div class="grid grid-cols-1 gap-6 md:grid-cols-3">
        <div class="md:col-span-2">
            <form wire:submit="createBatch" class="space-y-6" style="margin-bottom: 50px;">
                {{ $this->form }}

                <x-filament::button type="submit">
                    Criar anuncios
                </x-filament::button>
            </form>
            <x-filament::section>
                    <x-slot name="heading">
                        Historico de lotes
                    </x-slot>

                    {{ $this->table }}
            </x-filament::section>
        </div>

        <div class="md:col-span-1">
            <div class="space-y-6 md:sticky md:top-6 md:self-start" style="position: fixed;">
                <x-filament::section>
                    <x-slot name="heading">
                        Previa do bloco de texto
                    </x-slot>

                    <div
                        x-data="metaOverlayPreview({
                            storageBaseUrl: @js(asset('storage')),
                            imagePath: @entangle('data.image_path'),
                            imagePreviewUrl: @entangle('data.image_preview_url'),
                            overlayText: @entangle('data.overlay_text'),
                            textColor: @entangle('data.overlay_text_color'),
                            bgColor: @entangle('data.overlay_bg_color'),
                            posX: @entangle('data.overlay_position_x').defer,
                            posY: @entangle('data.overlay_position_y').defer,
                        })"
                        class="w-full"
                    >
                    <div class="text-sm text-gray-500">
                        Arraste o bloco para posicionar o texto sobre a imagem.
                    </div>
                    <div class="mt-3">
                        <x-filament::button type="button" size="sm" color="gray" wire:click="addImage">
                            Adicionar imagem
                        </x-filament::button>
                    </div>

                    <div class="mt-4 flex flex-col items-start gap-4">
                        <div class="relative w-full max-w-xl overflow-hidden rounded-lg border border-gray-200 bg-gray-50" x-ref="preview">
                                <template x-if="!imageUrl">
                                    <div class="flex h-64 w-full items-center justify-center text-sm text-gray-400">
                                        Envie uma imagem para ver a previa.
                                    </div>
                                </template>

                                <template x-if="imageUrl">
                                    <div class="relative">
                                        <img :src="imageUrl" alt="Previa" class="block w-full h-auto" />
                                        <div
                                            x-ref="block"
                                            class="absolute select-none cursor-move px-1 py-0.5 text-base font-semibold shadow-sm"
                                            :style="overlayStyle"
                                            @pointerdown="startDrag"
                                        >
                                            <span x-text="displayText"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </x-filament::section>

                
            </div>
        </div>
    </div>
    @include('partials.meta-sdk')

    @push('scripts')
        <script>
            function metaOverlayPreview(config) {
                return {
                    storageBaseUrl: config.storageBaseUrl,
                    imagePath: config.imagePath,
                    imagePreviewUrl: config.imagePreviewUrl,
                    overlayText: config.overlayText,
                    textColor: config.textColor,
                    bgColor: config.bgColor,
                    posX: config.posX,
                    posY: config.posY,
                    dragging: false,
                    moveHandler: null,
                    upHandler: null,
                    get imageUrl() {
                        if (this.imagePreviewUrl) {
                            return this.imagePreviewUrl;
                        }
                        if (!this.imagePath) {
                            return null;
                        }
                        return `${this.storageBaseUrl}/${this.imagePath}`;
                    },
                    get displayText() {
                        const text = (this.overlayText || '').replace(/\s+/g, ' ').trim();
                        const replaced = text.replace(/\{cidade\}/gi, 'NOME DA CIDADE');
                        return replaced !== '' ? replaced : 'NOME DA CIDADE';
                    },
                    get overlayStyle() {
                        const bg = this.hexToRgba(this.bgColor || '#000000', 0.7);
                        const color = this.textColor || '#ffffff';
                        const x = this.posX ?? 50;
                        const y = this.posY ?? 12;
                        return `
                            left: ${x}%;
                            top: ${y}%;
                            transform: translate(-50%, -50%);
                            background-color: ${bg};
                            color: ${color};
                            border-radius: 10px;
                            max-width: 98%;
                            text-align: center;
                            white-space: nowrap;
                        `;
                    },
                    startDrag(event) {
                        if (!this.$refs.preview || !this.$refs.block) {
                            return;
                        }
                        event.preventDefault();
                        this.dragging = true;
                        this.onDrag(event);
                        this.moveHandler = (moveEvent) => this.onDrag(moveEvent);
                        this.upHandler = () => this.endDrag();
                        window.addEventListener('pointermove', this.moveHandler);
                        window.addEventListener('pointerup', this.upHandler);
                    },
                    onDrag(event) {
                        if (!this.dragging) {
                            return;
                        }
                        const rect = this.$refs.preview.getBoundingClientRect();
                        const blockRect = this.$refs.block.getBoundingClientRect();
                        if (!rect.width || !rect.height) {
                            return;
                        }
                        const xPercent = ((event.clientX - rect.left) / rect.width) * 100;
                        const yPercent = ((event.clientY - rect.top) / rect.height) * 100;
                        const halfW = (blockRect.width / rect.width) * 50;
                        const halfH = (blockRect.height / rect.height) * 50;
                        this.posX = this.clamp(xPercent, halfW, 100 - halfW);
                        this.posY = this.clamp(yPercent, halfH, 100 - halfH);
                    },
                    endDrag() {
                        this.dragging = false;
                        if (this.moveHandler) {
                            window.removeEventListener('pointermove', this.moveHandler);
                            this.moveHandler = null;
                        }
                        if (this.upHandler) {
                            window.removeEventListener('pointerup', this.upHandler);
                            this.upHandler = null;
                        }
                    },
                    clamp(value, min, max) {
                        return Math.min(Math.max(value, min), max);
                    },
                    hexToRgba(hex, alpha) {
                        let clean = (hex || '').replace('#', '');
                        if (clean.length === 3) {
                            clean = clean.split('').map((c) => c + c).join('');
                        }
                        if (clean.length !== 6) {
                            return `rgba(0,0,0,${alpha})`;
                        }
                        const num = parseInt(clean, 16);
                        const r = (num >> 16) & 255;
                        const g = (num >> 8) & 255;
                        const b = num & 255;
                        return `rgba(${r}, ${g}, ${b}, ${alpha})`;
                    },
                };
            }

            document.addEventListener('meta-ads-image-picker', () => {
                const input = document.getElementById('meta-ads-image-input');
                if (input && !input.disabled) {
                    input.click();
                }
            });
        </script>
    @endpush
</x-filament::page>
