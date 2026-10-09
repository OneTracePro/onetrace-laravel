{{--
    A search box with product suggestions of the tracker (product search of the plan): suggestions while typing,
    Enter submits the form to your results page (OneTrace::searchProducts() there). category-url — a link to a category
    page with {id} for the category id; without it categories are not suggested.
--}}
@props(['action' => '/search', 'name' => 'q', 'value' => null, 'categoryUrl' => null, 'placeholder' => null])
<form action="{{ $action }}" method="get" role="search" {{ $attributes->only('class') }}>
    <input type="search" name="{{ $name }}" value="{{ $value ?? request()->query($name) }}" data-cdp-search
        @if ($categoryUrl) data-category-url="{{ $categoryUrl }}" @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        {{ $attributes->except('class') }}>
</form>
