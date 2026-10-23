{{--
    Search results drawn by the tracker (the search widget of Site → Search in OneTrace must be on): facets, sorting,
    infinite scroll, the query and filters in the page address. cart-url — your add-to-cart address with {id} for the
    product id: the "Add to cart" button posts quantity to it and expects {"ok": true} or {"redirect": "…"}; without it
    the address from the widget settings is used. query-param — the query parameter of the page (q).
--}}
@props(['cartUrl' => null, 'queryParam' => 'q', 'perPage' => null])
<div data-cdp-search-results data-query-param="{{ $queryParam }}"
    @if ($cartUrl) data-cart-url="{{ $cartUrl }}" @endif
    @if ($perPage) data-per-page="{{ (int) $perPage }}" @endif
    {{ $attributes }}></div>
