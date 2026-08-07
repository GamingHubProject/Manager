@extends('admin.layouts.admin')

@section('title', 'Gaming Hub Manager')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    @if (($legacy['sources'] ?? 0) + ($legacy['packages'] ?? 0) + ($legacy['operations'] ?? 0) + ($legacy['backups'] ?? 0) > 0)
        <div class="alert alert-info">
            Imported legacy Core lifecycle metadata: {{ $legacy['sources'] ?? 0 }} sources,
            {{ $legacy['packages'] ?? 0 }} packages, {{ $legacy['operations'] ?? 0 }} operations,
            and {{ $legacy['backups'] ?? 0 }} backups.
        </div>
    @endif

    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Installed packages</div><div class="display-6">{{ $installed->count() }}</div></div></div></div>
        <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Updates available</div><div class="display-6">{{ count($updates) }}</div></div></div></div>
        <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Verified backups</div><div class="display-6">{{ $backupCount }}</div></div></div></div>
        <div class="col-md-3"><div class="card h-100"><div class="card-body"><div class="text-muted">Integrity warnings</div><div class="display-6">{{ $changedCount }}</div></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><strong>Available updates</strong></div>
                <div class="table-responsive">
                    <table class="table table-striped mb-0 align-middle">
                        <thead><tr><th>Package</th><th>Installed</th><th>Available</th><th>Source</th></tr></thead>
                        <tbody>
                        @forelse ($updates as $packageId => $update)
                            <tr>
                                <td><a href="{{ route('gaming-hub-manager.admin.packages.show', $update['installed']) }}">{{ $update['name'] }}</a><div class="small text-muted">{{ $packageId }}</div></td>
                                <td>{{ $update['installed']->installed_version }}</td>
                                <td>{{ $update['latest_version'] }}</td>
                                <td>{{ $update['source']->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">No updates detected.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><strong>Recent operations</strong></div>
                <div class="list-group list-group-flush">
                    @forelse ($recentOperations as $operation)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between gap-3">
                                <span><strong>{{ ucfirst($operation->operation) }}</strong> {{ $operation->extension_id ?: 'direct package' }}</span>
                                <span class="badge bg-{{ $operation->result === 'completed' ? 'success' : ($operation->result === 'failed' ? 'danger' : 'warning') }}">{{ $operation->result }}</span>
                            </div>
                            <div class="small text-muted">{{ $operation->started_at?->format('Y-m-d H:i:s') }} · {{ $operation->current_stage }}</div>
                        </div>
                    @empty
                        <div class="list-group-item text-muted">No operations recorded.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
