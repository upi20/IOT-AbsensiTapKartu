@if ($paginator->hasPages() || ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $paginator->total() > 0))
    <nav class="pagination" aria-label="Navigasi halaman">
        <div class="info">
            @if ($paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                Menampilkan {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ $paginator->total() }}
            @else
                Halaman {{ $paginator->currentPage() }}
            @endif
        </div>
        @if ($paginator->hasPages())
            <div class="pages">
                @if ($paginator->onFirstPage())
                    <span class="disabled" aria-disabled="true">&lsaquo;</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="Sebelumnya">&lsaquo;</a>
                @endif

                @isset($elements)
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span class="gap">{{ $element }}</span>
                        @endif
                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                @if ($page == $paginator->currentPage())
                                    <span class="current" aria-current="page">{{ $page }}</span>
                                @else
                                    <a href="{{ $url }}">{{ $page }}</a>
                                @endif
                            @endforeach
                        @endif
                    @endforeach
                @endisset

                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="Berikutnya">&rsaquo;</a>
                @else
                    <span class="disabled" aria-disabled="true">&rsaquo;</span>
                @endif
            </div>
        @endif
    </nav>
@endif
