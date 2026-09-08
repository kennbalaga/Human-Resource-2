<x-mail::message>
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
@if ($level === 'error')
# @lang('Whoops!')
@else
# @lang('Hello!')
@endif
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
@lang('Regards,')<br>
{{ config('app.name') }}
@endif

{{-- Laravel's own copy of this view ends with a subcopy block: "If you're
     having trouble clicking the ... button, copy and paste the URL below into
     your web browser", followed by the raw link.

     It is dropped here on purpose. These notifications carry a local
     development URL that means nothing to the employee reading them on a
     phone, and a bare pasted address underneath a working button reads as
     something gone wrong with the message. The button itself is unchanged and
     still carries the same link. --}}
</x-mail::message>
