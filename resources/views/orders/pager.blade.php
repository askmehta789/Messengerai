@if ($paginator->hasPages())
    <nav class="row" aria-label="Pages">
        @if ($paginator->onFirstPage())
            <span class="btn btn-ghost btn-sm" aria-disabled="true">← Newer</span>
        @else
            <a class="btn btn-ghost btn-sm" href="{{ $paginator->previousPageUrl() }}">← Newer</a>
        @endif
        <span class="muted small">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }} · {{ $paginator->total() }} orders</span>
        @if ($paginator->hasMorePages())
            <a class="btn btn-ghost btn-sm" href="{{ $paginator->nextPageUrl() }}">Older →</a>
        @else
            <span class="btn btn-ghost btn-sm" aria-disabled="true">Older →</span>
        @endif
    </nav>
@endif
