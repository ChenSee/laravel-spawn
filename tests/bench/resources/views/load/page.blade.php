@extends('load.layout')

@section('title', $token)

@push('scripts')<script>{{ $token }}</script>@endpush

@section('content')
<url>{{ $url }}</url>
@include('load.partials.aside')
<x-panel>slot:{{ $token }}</x-panel>
@include('load.partials.once', ['token' => $token])
@include('load.partials.once', ['token' => $token])
@endsection
