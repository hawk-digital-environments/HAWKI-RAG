@php
    $variant = $variant ?? 'default';
    $kicker = $kicker ?? 'HAWKI-RAG';
    $title = $title ?? 'System Surface';
    $copy = $copy ?? '';
    $signals = $signals ?? [];
@endphp

<section class="hawki-rag-page-signal hawki-rag-page-signal--{{ $variant }}" aria-label="{{ $title }} route context">
    <div class="hawki-rag-page-signal__copy">
        <p class="hawki-rag-page-signal__kicker">{{ $kicker }}</p>
        <h2>{{ $title }}</h2>
        @if ($copy !== '')
            <p>{{ $copy }}</p>
        @endif
    </div>

    <div class="hawki-rag-page-signal__map" aria-label="HAWKI-RAG service flow">
        @foreach ($signals as $signal)
            <article class="hawki-rag-page-signal__node" data-tone="{{ $signal['tone'] ?? 'active' }}">
                <span>{{ $signal['state'] ?? 'Live' }}</span>
                <strong>{{ $signal['label'] ?? 'Service' }}</strong>
                <small>{{ $signal['copy'] ?? '' }}</small>
            </article>
        @endforeach
    </div>
</section>
