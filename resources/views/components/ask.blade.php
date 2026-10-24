{{--
    "Ask AI about the product" on the product page (Site → Product questions in OneTrace must be on): the tracker draws
    suggested questions and the AI answer from the product description and the knowledge base of the store, or a chat
    button with the product, as set up in OneTrace. product — the product id of the catalog (product_id of events).
    While the widget is off the element stays empty.
--}}
@props(['product'])
<div data-cdp-ask data-product-id="{{ $product }}" {{ $attributes }}></div>
