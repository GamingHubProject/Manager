@extends('admin.layouts.admin')

@section('title', 'Available Packages')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="row g-3">
        @forelse ($items as $item)
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between gap-3">
                            <div>
                                <h2 class="h5 mb-1">{{ $item['name'] }}</h2>
                                <div class="small text-muted">{{ $item['id'] }} · {{ $item['category'] }}</div>
                            </div>
                            <div class="text-end">
                                @if ($item['official'])<span class="badge bg-primary">Official</span>@endif
                                @if ($item['verified'])<span class="badge bg-success">Verified listing</span>@endif
                                <span class="badge bg-secondary">{{ $item['latest_version'] }}</span>
                            </div>
                        </div>
                        <p class="mt-3 mb-2">{{ $item['description'] }}</p>
                        <div class="small text-muted mb-3">Source: {{ $item['source']->name }} · Version source: {{ str_replace('_', ' ', $item['release_discovery']) }}</div>
                        @if ($item['fallback_registry'])
                            <div class="alert alert-warning py-2">The bundled official registry fallback is being used because the remote registry was unavailable.</div>
                        @endif
                        <div class="mt-auto d-flex gap-2 flex-wrap align-items-end">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('gaming-hub-manager.admin.releases.show', [$item['source'], $item['id']]) }}">Inspect Release</a>
                            @if ($item['installed'] !== null)
                                <a class="btn btn-sm btn-outline-primary" href="{{ route('gaming-hub-manager.admin.packages.show', $item['installed']) }}">Manage Installed</a>
                                <span class="badge bg-{{ $item['state'] === 'update' ? 'info text-dark' : 'success' }}">{{ str_replace('_', ' ', $item['state']) }}</span>
                            @elseif ($item['state'] === 'incompatible')
                                <span class="badge bg-danger">Incompatible</span>
                            @elseif ($item['state'] === 'unavailable')
                                <span class="badge bg-warning text-dark">Release unavailable</span>
                            @else
                                @can('gaminghub.manager.install')
                                    <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.install', $item['source']) }}">
                                        @csrf
                                        <input type="hidden" name="extension_id" value="{{ $item['id'] }}">
                                        <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="enable" value="1" id="enable-{{ $item['source']->id }}-{{ $loop->index }}"><label class="form-check-label" for="enable-{{ $item['source']->id }}-{{ $loop->index }}">Enable after install</label></div>
                                        @if (! $item['source']->trusted && $item['source']->type !== 'official')
                                            <div class="form-check"><input class="form-check-input" type="checkbox" name="confirm_unverified" value="1" required id="trust-{{ $item['source']->id }}-{{ $loop->index }}"><label class="form-check-label text-danger" for="trust-{{ $item['source']->id }}-{{ $loop->index }}">I accept this untrusted executable source</label></div>
                                        @endif
                                        <button class="btn btn-sm btn-primary mt-2">Install</button>
                                    </form>
                                @endcan
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="card"><div class="card-body text-center text-muted">No packages are available from enabled sources.</div></div></div>
        @endforelse
    </div>
</div>
@endsection
