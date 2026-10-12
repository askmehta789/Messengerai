@extends('layouts.app')
@section('title', $product->exists ? 'Edit '.$product->name : 'Add product')

@section('content')
<main class="container">
    <div class="page-head">
        <div>
            <a href="{{ route('products.index') }}" class="muted">← Products</a>
            <h1>{{ $product->exists ? $product->name : 'Add product' }}</h1>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="product-form"
          action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}">
        @csrf
        @if ($product->exists) @method('put') @endif

        <div class="card">
            <h2><span class="card-icon">🏷️</span> Details</h2>
            <div class="grid-2">
                <label>Product name
                    <input name="name" value="{{ old('name', $product->name) }}" required placeholder="Dashain special combo">
                </label>
                <label>Price
                    <input name="price" value="{{ old('price', $product->price) }}" placeholder="Rs. 2,499">
                </label>
                <label>Page
                    <select name="page_id" @unless (auth()->user()->isAdmin()) required @endunless>
                        @if (auth()->user()->isAdmin())
                            <option value="">All pages (shared)</option>
                        @endif
                        @foreach ($pages as $page)
                            <option value="{{ $page->id }}" @selected((string) old('page_id', $product->page_id) === (string) $page->id)>{{ $page->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Visibility
                    <input type="hidden" name="active" value="0">
                    <span class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $product->exists ? $product->active : true))> Show in chats and let the AI offer it</span>
                </label>
            </div>
            <label>Description <small class="muted">sizes, colours, stock, offer… the AI uses this to answer questions</small>
                <textarea name="description" rows="4" placeholder="Pack of 5 items. Available in red and gold. Offer valid until Dashain.">{{ old('description', $product->description) }}</textarea>
            </label>
        </div>

        <div class="card">
            <h2><span class="card-icon">🖼️</span> Photos &amp; videos <small class="muted">JPG, PNG, WEBP, GIF, MP4 · up to 25 MB each</small></h2>

            @if ($product->exists && $product->media->isNotEmpty())
                <div class="media-grid">
                    @foreach ($product->media as $media)
                        <label class="media-tile">
                            @if ($media->type === 'video')
                                <video src="{{ $media->url() }}#t=0.5" muted preload="metadata"></video>
                                <span class="play">▶</span>
                            @else
                                <img src="{{ $media->url() }}" alt="" loading="lazy">
                            @endif
                            <span class="remove"><input type="checkbox" name="remove_media[]" value="{{ $media->id }}"> Remove</span>
                        </label>
                    @endforeach
                </div>
            @endif

            <label class="dropzone" id="dropzone">
                <input type="file" name="files[]" id="files" multiple accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime">
                <span class="dz-icon">⬆️</span>
                <strong>Click to choose photos or videos</strong>
                <span class="muted">or drag them here, up to 10 at a time</span>
            </label>
            <div class="media-grid" id="previews"></div>

            @if ($errors->any())
                <div class="flash flash-err" style="border-radius:8px;margin-top:12px">{{ $errors->first() }}</div>
            @endif
        </div>

        <div class="row">
            <button class="btn btn-primary">{{ $product->exists ? 'Save changes' : 'Save product' }}</button>
            <a class="btn btn-ghost" href="{{ route('products.index') }}">Cancel</a>
            <span class="grow"></span>
            @if ($product->exists)
                <button class="btn btn-danger" form="delete-product">Delete product</button>
            @endif
        </div>
    </form>

    @if ($product->exists)
        <form id="delete-product" method="post" action="{{ route('products.destroy', $product) }}" hidden
              onsubmit="return confirm('Delete {{ addslashes($product->name) }} and all its photos?')">
            @csrf @method('delete')
        </form>
    @endif
</main>
@endsection

@push('scripts')
<script>
(() => {
    const input = document.getElementById('files');
    const zone = document.getElementById('dropzone');
    const previews = document.getElementById('previews');

    function show() {
        previews.innerHTML = '';
        [...input.files].forEach(file => {
            const url = URL.createObjectURL(file);
            const tile = document.createElement('div');
            tile.className = 'media-tile new';
            tile.innerHTML = file.type.startsWith('video/')
                ? `<video src="${url}" muted></video><span class="play">▶</span>`
                : `<img src="${url}" alt="">`;
            tile.insertAdjacentHTML('beforeend', `<span class="remove">${(file.size / 1048576).toFixed(1)} MB · new</span>`);
            previews.appendChild(tile);
        });
    }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('over'); }));
    ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('over'); }));
    zone.addEventListener('drop', e => { input.files = e.dataTransfer.files; show(); });
})();
</script>
@endpush
