@extends('layouts.app')
@section('title', 'Add Position')
@section('content')
    <div class="organization-form-shell"><section class="page-heading"><div><p class="eyebrow">Organization · Positions</p><h1>Add position</h1><p>Create an approved role and assign it to the correct department.</p></div></section>@include('partials.organization-feedback')@include('positions._form')</div>
@endsection
