@extends('layouts.app')
@section('title', 'Products')

@section('content')
<main class="container container-wide">
    <div class="page-head">
        <div>
            <h1>Products &amp; media</h1>
            <p class="muted">Save product photos, videos and prices once. Send them in any chat with one click, and the AI sends them when customers ask for photos.</p>
        </div>
        <a class="btn btn-primary" href="{{ route('products.create') }}">+ Add product</a>
    </div>

    <form method="get" class="toolbar">
        <div class="search">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search products">
        </div>
        <select name="page" onchange="this.form.submit()">
            <option value="">All products</option>
            @if (auth()->user()->isAdmin())
                <option value="all" @selected(request('page') === 'all')>Shared with all pages</option>
            @endif
            @foreach ($pages as $page)
                <option value="{{ $page->id }}" @selected((string) request('page') === (string) $page->id)>{{ $page->name }}</option>
            @endforeach
        </select>
    </form>

    @if ($products->isEmpty())
        <div class="card empty">
            <div class="empty-art">🛍️</div>
            <h3 style="margin-top:14px">No products yet</h3>
            <p class="muted">Add your first product with its photos. It will appear in the chat's <strong>Products</strong> button and the AI will know its price.</p>
            <a class="btn btn-primary" href="{{ route('products.create') }}">+ Add product</a>
        </div>
    @else
        <div class="product-grid">
            @foreach ($products as $product)
                @php $cover = $product->media->first(); @endphp
                <a class="product-card {{ $product->active ? '' : 'inactive' }}" href="{{ route('products.edit', $product) }}">
                    <div class="product-cover">
                        @if ($cover?->type === 'image')
                            <img src="{{ $cover->url() }}" alt="" loading="lazy">
                        @elseif ($cover?->type === 'video')
                            <video src="{{ $cover->url() }}#t=0.5" muted preload="metadata"></video>
                            <span class="play">▶</span>
                        @else
                            <span class="no-media">📷<small>No photos yet</small></span>
                        @endif
                        @if ($product->media->count() > 1)
                            <span class="media-count">🖼 {{ $product->media->count() }}</span>
                        @endif
                        @unless ($product->active)
                            <span class="media-count left">Hidden</span>
                        @endunless
                    </div>
                    <div class="product-body">
                        <strong>{{ $product->name }}</strong>
                        @if ($product->price)<span class="price">{{ $product->price }}</span>@endif
                        <span class="page-chip">{{ $product->page?->name ?? 'All pages' }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</main>
@endsection
