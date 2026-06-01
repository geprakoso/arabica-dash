@php
    $items = $getState() ?? [];
@endphp

@if (count($items) > 0)
    <div class="flex flex-wrap gap-2">
        @foreach ($items as $item)
            @php
                $url = $item['url'] ?? '';
                $path = parse_url($url, PHP_URL_PATH) ?? '';
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mimeType = strtolower((string) ($item['mime_type'] ?? ''));
                $isPdf = $ext === 'pdf';
                $isPreviewableImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
                    || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
                $fileLabel = $isPdf ? 'PDF' : strtoupper($ext ?: 'FILE');
            @endphp

            <a
                href="{{ $url ?: '#' }}"
                target="_blank"
                rel="noopener noreferrer"
                class="group relative block h-20 w-20 flex-shrink-0 overflow-hidden rounded-lg ring-1 ring-white/10 transition-all duration-200 hover:ring-primary-500/60 hover:shadow-md"
            >
                @if ($isPreviewableImage)
                    <img
                        src="{{ $url }}"
                        alt="{{ $item['name'] ?? 'Bukti Transaksi' }}"
                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-110"
                        loading="lazy"
                    />
                    <div class="absolute inset-0 flex items-center justify-center bg-black/0 transition-colors duration-200 group-hover:bg-black/30">
                        <x-heroicon-m-magnifying-glass-plus class="h-4 w-4 text-white opacity-0 transition-opacity duration-200 group-hover:opacity-100" />
                    </div>
                @else
                    <div class="flex h-full w-full flex-col items-center justify-center bg-gray-800/50">
                        <x-heroicon-o-document class="h-5 w-5 text-gray-400" />
                        <span class="mt-1 text-[10px] font-semibold text-gray-400">{{ $fileLabel }}</span>
                    </div>
                @endif
            </a>
        @endforeach
    </div>
@else
    <p class="text-sm text-gray-500">Tidak ada bukti yang diunggah.</p>
@endif