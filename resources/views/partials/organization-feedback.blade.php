@if (session('success'))
    <div class="attendance-alert attendance-alert-success" role="status">
        <x-icon name="check-circle" /><span>{{ session('success') }}</span>
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
