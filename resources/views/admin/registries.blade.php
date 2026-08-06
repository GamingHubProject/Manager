@extends('admin.layouts.admin')

@section('title', 'Registries')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><strong>Package Sources</strong></div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead><tr><th>Name</th><th>Type</th><th>Trust</th><th>Enabled</th><th>Last refresh</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                        @foreach ($sources as $source)
                            <tr>
                                <td><strong>{{ $source->name }}</strong><div class="small text-muted text-break">{{ $source->url }}</div>@if ($source->last_error)<div class="small text-danger">{{ $source->last_error }}</div>@endif</td>
                                <td>{{ ucfirst($source->type) }}</td>
                                <td><span class="badge bg-{{ $source->type === 'official' ? 'primary' : ($source->trusted ? 'success' : 'danger') }}">{{ $source->trust_level }}</span></td>
                                <td>{{ $source->enabled ? 'Yes' : 'No' }}</td>
                                <td>{{ $source->last_successful_refresh_at?->format('Y-m-d H:i:s') ?: 'Never' }}</td>
                                <td class="text-end">
                                    <form class="d-inline" method="POST" action="{{ route('gaming-hub-manager.admin.sources.refresh', $source) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-primary">Refresh</button></form>
                                    @if ($source->type !== 'official')
                                        <form class="d-inline" method="POST" action="{{ route('gaming-hub-manager.admin.sources.toggle', $source) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-secondary">{{ $source->enabled ? 'Disable' : 'Enable' }}</button></form>
                                        <form class="d-inline" method="POST" action="{{ route('gaming-hub-manager.admin.sources.trust', $source) }}">@csrf @method('PATCH')<button class="btn btn-sm btn-outline-warning">{{ $source->trusted ? 'Untrust' : 'Trust' }}</button></form>
                                        <form class="d-inline" method="POST" action="{{ route('gaming-hub-manager.admin.sources.destroy', $source) }}" onsubmit="return confirm('Remove this package source?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">Delete</button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header"><strong>Add Custom Source</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('gaming-hub-manager.admin.sources.store') }}">
                        @csrf
                        <div class="mb-3"><label class="form-label" for="source-name">Name</label><input class="form-control" id="source-name" name="name" required maxlength="150"></div>
                        <div class="mb-3"><label class="form-label" for="source-type">Type</label><select class="form-select" id="source-type" name="type"><option value="registry">Registry JSON</option><option value="github">Direct GitHub repository</option></select></div>
                        <div class="mb-3"><label class="form-label" for="source-url">HTTPS URL</label><input class="form-control" id="source-url" type="url" name="url" required placeholder="https://github.com/owner/repository"></div>
                        <div class="mb-3"><label class="form-label" for="source-release-asset">GitHub ZIP asset pattern</label><input class="form-control" id="source-release-asset" name="release_asset" value="*.zip" maxlength="255"><div class="form-text">Used only for direct GitHub sources. Example: gaming-hub-core-*.zip</div></div>
                        <div class="mb-3"><label class="form-label" for="source-checksum-asset">Checksum asset name</label><input class="form-control" id="source-checksum-asset" name="checksum_asset" maxlength="255" placeholder="SHA256SUMS"><div class="form-text">Optional preferred checksum file. When it is absent, Manager can use the exact selected GitHub asset SHA-256 digest.</div></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="source-enabled"><label class="form-check-label" for="source-enabled">Enable immediately</label></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="trusted" value="1" id="source-trusted"><label class="form-check-label" for="source-trusted">Mark source as trusted</label></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="allow_prereleases" value="1" id="source-prerelease"><label class="form-check-label" for="source-prerelease">Allow prerelease GitHub releases</label></div>
                        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="allow_private_host" value="1" id="source-private"><label class="form-check-label text-warning" for="source-private">Allow this source to use private/reserved hosts</label><div class="form-text">Requires the global private-host setting and should only be used for infrastructure you control.</div></div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="acknowledge" value="1" required id="source-ack"><label class="form-check-label text-danger" for="source-ack">I understand packages execute server-side PHP</label></div>
                        <button class="btn btn-primary">Add Source</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
