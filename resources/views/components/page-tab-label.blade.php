@props(['icon', 'title', 'description' => null])

{{-- The inside of a .page-tab: the icon tile, then the title and its one-line
     description. The slot lands beside the title, for a count.

     Everything sits in one body element because the tab itself is the size
     container its narrow layouts query, and a container can only restyle what
     is inside it -- so the padding and height that shrink live here. --}}
<span class="page-tab-body">
    <span class="page-tab-icon"><x-icon :name="$icon" /></span>
    <span class="page-tab-text">
        <strong><span>{{ $title }}</span>{{ $slot }}</strong>
        @if($description)<small>{{ $description }}</small>@endif
    </span>
</span>
