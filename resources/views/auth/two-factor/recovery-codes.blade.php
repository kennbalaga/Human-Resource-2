@extends('auth.two-factor.layout')
@section('title', 'Save recovery codes')
@section('content')
<p class="text-sm font-semibold uppercase tracking-[.2em] text-emerald-600">2FA activated</p><h1 class="mt-2 text-3xl font-bold">Save your recovery codes</h1><p class="mt-3 text-slate-600 dark:text-slate-300">Keep these codes somewhere secure, separate from your phone. Each can be used only once. They will not be shown again.</p>
<div class="my-7 grid grid-cols-2 gap-3 rounded-2xl bg-slate-100 p-5 font-mono text-sm font-semibold dark:bg-slate-800 sm:grid-cols-3">@foreach($codes as $code)<span class="rounded bg-white p-3 text-center dark:bg-slate-900">{{ $code }}</span>@endforeach</div>
<div class="flex flex-col gap-3 sm:flex-row"><a href="{{ route('two-factor.recovery-codes.download') }}" class="rounded-xl bg-blue-600 px-5 py-3 text-center font-semibold text-white">Download codes</a><button onclick="window.print()" class="rounded-xl border border-slate-300 px-5 py-3 font-semibold dark:border-slate-600">Print codes</button><a href="{{ route('dashboard') }}" class="rounded-xl px-5 py-3 text-center font-semibold text-blue-700 dark:text-blue-300">Continue to dashboard</a></div>
@endsection
