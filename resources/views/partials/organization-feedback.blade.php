@if (session('success'))
    <div class="attendance-alert attendance-alert-success" role="status">
        <x-icon name="check-circle" /><span>{{ session('success') }}</span>
    </div>
@endif

@if ($errors->any())
    <div class="attendance-alert attendance-alert-danger" role="alert">
        <x-icon name="close" /><span>{{ $errors->first() }}</span>
    </div>
@endif
