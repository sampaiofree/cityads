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
                            sourceMode: @entangle('data.creative_source_mode'),
                            existingPostId: @entangle('data.existing_post_id'),
                            imagePath: @entangle('data.image_path'),
                            imagePreviewUrl: @entangle('data.image_preview_url'),
                            rotationImagePaths: @entangle('data.rotation_image_paths'),
                            rotationImagePreviewUrls: @entangle('data.rotation_image_preview_urls'),
                            mediaType: @entangle('data.creative_media_type'),
                            overlayText: @entangle('data.overlay_text'),
                            textColor: @entangle('data.overlay_text_color'),
                            bgColor: @entangle('data.overlay_bg_color'),
                            posX: @entangle('data.overlay_position_x'),
                            posY: @entangle('data.overlay_position_y'),
                        })"
                        class="w-full"
                    >
                    <div class="text-sm text-gray-500">
                        <span x-show="!isExistingPostMode">Arraste o bloco para posicionar o texto sobre a imagem.</span>
                        <span x-show="isExistingPostMode">Modo de post existente: o anuncio usara o mesmo post para todas as cidades.</span>
                    </div>
                    <div class="mt-3" x-show="!isExistingPostMode">
                        <x-filament::button type="button" size="sm" color="gray" wire:click="addImage">
                            Adicionar midia
                        </x-filament::button>
                    </div>

                    <div class="mt-4 flex flex-col items-start gap-4">
                        <div class="relative w-full max-w-xl overflow-hidden rounded-lg border border-gray-200 bg-gray-50" x-ref="preview">
                                <template x-if="isExistingPostMode">
                                    <div class="flex min-h-40 w-full flex-col justify-center gap-2 p-4 text-sm text-gray-600">
                                        <div class="font-medium text-gray-700">Usando post existente</div>
                                        <div>
                                            ID do post:
                                            <span class="font-mono text-xs" x-text="normalizedExistingPostId || '-'"></span>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            O mesmo post sera reutilizado em todos os anuncios das cidades selecionadas.
                                        </div>
                                    </div>
                                </template>

                                <template x-if="!isExistingPostMode && !imageUrl">
                                    <div class="flex h-64 w-full items-center justify-center text-sm text-gray-400">
                                        Envie uma midia para ver a previa.
                                    </div>
                                </template>

                                <template x-if="!isExistingPostMode && imageUrl && isVideoMedia">
                                    <div class="space-y-2">
                                        <video :src="imageUrl" controls playsinline muted preload="metadata" class="block w-full h-auto rounded">
                                            Seu navegador nao suporta video.
                                        </video>
                                        <p class="text-xs text-gray-500">
                                            Videos nao recebem bloco de texto/placeholder {cidade}.
                                        </p>
                                    </div>
                                </template>

                                <template x-if="!isExistingPostMode && imageUrl && !isVideoMedia">
                                    <div class="relative" x-ref="canvas">
                                        <img :src="imageUrl" alt="Previa" class="block w-full h-auto" />
                                        <div
                                            x-ref="block"
                                            class="absolute select-none cursor-move px-1 py-0.5 text-base font-semibold shadow-sm"
                                            :style="overlayStyle"
                                            x-show="displayText !== ''"
                                            @pointerdown="startDrag"
                                        >
                                            <span x-text="displayText"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            @if (data_get($this->data, 'creative_source_mode', 'single_media') === 'image_rotation')
                                @php($rotationPreviewImageItems = $this->rotationPreviewImageItems)
                                @php($rotationPreviewSampleRows = $this->rotationPreviewSampleRows)
                                @php($rotationPreviewTotalCities = $this->rotationPreviewTotalCities)
                                <div class="w-full max-w-xl rounded-lg border border-gray-200 bg-white p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Ordem das imagens (upload)
                                    </p>
                                    @if (empty($rotationPreviewImageItems))
                                        <p class="mt-2 text-sm text-gray-500">Nenhuma imagem selecionada para o rodizio.</p>
                                    @else
                                        <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                            @foreach ($rotationPreviewImageItems as $image)
                                                <li>
                                                    <span class="font-medium">#{{ $image['number'] }}</span>
                                                    <span class="text-gray-600">{{ $image['name'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        <p class="mt-2 text-xs text-gray-500">
                                            Recomendacao: use a mesma proporcao em todas as imagens para manter o posicionamento visual consistente.
                                        </p>
                                    @endif
                                </div>

                                <div class="w-full max-w-xl rounded-lg border border-gray-200 bg-white p-3">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Amostra do rodizio (primeiras {{ count($rotationPreviewSampleRows) }} cidades)
                                    </p>
                                    @if (empty($rotationPreviewSampleRows))
                                        <p class="mt-2 text-sm text-gray-500">Selecione um estado ou cidades e envie imagens para visualizar o rodizio.</p>
                                    @else
                                        <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                            @foreach ($rotationPreviewSampleRows as $row)
                                                <li>
                                                    <span class="font-medium">{{ $row['city'] }}</span>
                                                    <span class="text-gray-500">({{ $row['state'] }})</span>
                                                    <span class="text-gray-600">-> Imagem #{{ $row['image_number'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                        @if ($rotationPreviewTotalCities > count($rotationPreviewSampleRows))
                                            <p class="mt-2 text-xs text-gray-500">
                                                Mostrando {{ count($rotationPreviewSampleRows) }} de {{ $rotationPreviewTotalCities }} cidades em ordem alfabetica.
                                            </p>
                                        @endif
                                    @endif
                                </div>
                            @endif
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
                    sourceMode: config.sourceMode,
                    existingPostId: config.existingPostId,
                    imagePath: config.imagePath,
                    imagePreviewUrl: config.imagePreviewUrl,
                    rotationImagePaths: config.rotationImagePaths,
                    rotationImagePreviewUrls: config.rotationImagePreviewUrls,
                    mediaType: config.mediaType,
                    overlayText: config.overlayText,
                    textColor: config.textColor,
                    bgColor: config.bgColor,
                    posX: config.posX,
                    posY: config.posY,
                    dragging: false,
                    moveHandler: null,
                    upHandler: null,
                    get isExistingPostMode() {
                        return (this.sourceMode || 'single_media') === 'existing_post';
                    },
                    get normalizedExistingPostId() {
                        return (this.existingPostId || '').toString().trim();
                    },
                    get imageUrl() {
                        if (this.isExistingPostMode) {
                            return null;
                        }

                        if ((this.sourceMode || 'single_media') === 'image_rotation') {
                            const previewUrls = Array.isArray(this.rotationImagePreviewUrls) ? this.rotationImagePreviewUrls : [];
                            const previewUrl = previewUrls.find((url) => !!url);
                            if (previewUrl) {
                                return previewUrl;
                            }

                            const paths = Array.isArray(this.rotationImagePaths) ? this.rotationImagePaths : [];
                            const firstPath = paths.find((path) => !!path);
                            if (firstPath) {
                                return `${this.storageBaseUrl}/${firstPath}`;
                            }

                            return null;
                        }

                        if (this.imagePreviewUrl) {
                            return this.imagePreviewUrl;
                        }
                        if (!this.imagePath) {
                            return null;
                        }
                        return `${this.storageBaseUrl}/${this.imagePath}`;
                    },
                    get isVideoMedia() {
                        if (this.isExistingPostMode) {
                            return false;
                        }

                        if ((this.sourceMode || 'single_media') === 'image_rotation') {
                            return false;
                        }

                        if ((this.mediaType || '').toLowerCase() === 'video') {
                            return true;
                        }

                        const source = (this.imagePreviewUrl || this.imagePath || '').toLowerCase();
                        return ['.mp4', '.mov', '.avi', '.m4v', '.webm'].some((ext) => source.includes(ext));
                    },
                    get displayText() {
                        const text = (this.overlayText || '').replace(/\s+/g, ' ').trim();
                        const replaced = text.replace(/\{cidade\}/gi, 'NOME DA CIDADE');
                        return replaced.toLocaleUpperCase('pt-BR');
                    },
                    get overlayStyle() {
                        const isTransparent = !this.bgColor || this.bgColor === 'transparent';
                        const bg = isTransparent ? 'transparent' : this.hexToRgba(this.bgColor || '#000000', 0.7);
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
                        if (!this.$refs.canvas || !this.$refs.block) {
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
                        const rect = this.$refs.canvas.getBoundingClientRect();
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

            document.addEventListener('meta-ads-rotation-image-picker', () => {
                const input = document.getElementById('meta-ads-rotation-image-input');
                if (input && !input.disabled) {
                    input.click();
                }
            });

        </script>
    @endpush
</x-filament::page>
