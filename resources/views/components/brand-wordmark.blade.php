{{--
    The WorkForce wordmark: "Work" in the brand ink, "Force" in the accent —
    teal on a light page, peach on a dark one. Live text rather than an outline
    so it inherits the page's font rendering, scales with it and can be read by
    a screen reader. The two colours come from --hr-brand-ink and
    --hr-brand-accent, which the sidebar and the dark theme re-point.
--}}
<span {{ $attributes->merge(['class' => 'brand-wordmark']) }}><span class="brand-wordmark-work">Work</span><span class="brand-wordmark-force">Force</span></span>
