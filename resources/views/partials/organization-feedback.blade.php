{{-- A success that still leaves the reader something to do. It stays as a
     banner because it is an instruction, and the confirmation toast fades
     before a half-read next step has been acted on. Plain successes go to the
     toast (partials/toast). --}}
@if (session('notice'))
    <div class="attendance-alert attendance-alert-success" role="status">
        <x-icon name="check-circle" /><span>{{ session('notice') }}</span>
    </div>
@endif

{{-- A refusal that carries a reason: an archive stopped because the person
     still has work on the books, say. Without this branch the page simply
     reloaded unchanged, which reads as a dead button rather than an answer. --}}
@if (session('warning'))
    <div class="attendance-alert attendance-alert-warning" role="status">
        <x-icon name="alert" /><span>{{ session('warning') }}</span>
    </div>
@endif

@if ($errors->any())
    <div class="attendance-alert attendance-alert-danger" role="alert">
        <x-icon name="close" /><span>{{ $errors->first() }}</span>
    </div>
@endif
