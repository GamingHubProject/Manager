@extends('admin.layouts.admin')

@section('title', 'Installed Packages')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="card">
        <div class="card-header"><strong>Detected Packages</strong></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr><th>Package</th><th>Version</th><th>Source</th><th>Trust</th><th>Checksum</th><th>Integrity</th><th>State</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse ($installed as $extension)
                    @php($update = $updates[$extension->extension_id] ?? null)
                    <tr>
                        <td><a href="{{ route('gaming-hub-manager.admin.packages.show', $extension) }}">{{ $extension->manifest_snapshot['name'] ?? $extension->extension_id }}</a><div class="small text-muted">{{ $extension->extension_id }}</div></td>
                        <td>{{ $extension->installed_version }}</td>
                        <td>{{ $extension->source_id ?: 'Local discovery' }}</td>
                        <td><span class="badge bg-{{ $extension->trust_level === 'official' ? 'primary' : ($extension->trust_level === 'trusted' ? 'success' : 'secondary') }}">{{ $extension->trust_level }}</span></td>
                        <td>{{ $extension->checksum_verified ? 'Verified' : 'Not verified' }}</td>
                        <td><span class="badge bg-{{ $extension->integrity_status === 'verified' ? 'success' : ($extension->integrity_status === 'changed' ? 'danger' : 'secondary') }}">{{ $extension->integrity_status }}</span></td>
                        <td><span class="badge bg-{{ $extension->enabled_snapshot ? 'success' : 'secondary' }}">{{ $extension->enabled_snapshot ? 'Enabled' : 'Disabled' }}</span></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="{{ route('gaming-hub-manager.admin.packages.show', $extension) }}">Manage</a>
                            @if ($update !== null)
                                <span class="badge bg-info text-dark">{{ $update['latest_version'] }} available</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No Gaming Hub packages were detected.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
