<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @php
        $data = $getState();
        $json = is_array($data)
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            : (is_string($data) ? $data : '—');
    @endphp

    <div class="rounded-lg border border-gray-700 bg-gray-900 p-4 overflow-x-auto">
        <pre class="text-sm text-gray-300 leading-relaxed m-0"><code>{{ $json }}</code></pre>
    </div>
</x-dynamic-component>
