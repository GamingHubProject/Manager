@extends('admin.layouts.admin')

@section('title', 'Package Details')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3">
        <div>
            <h2 class="h4 mb-1">{{ $extension->manifest_snapshot['name'] ?? $extension->extension_id }}</h2>
            <div class="text-muted">{{ $extension->extension_id }} · {{ $extension->installed_version }}</div>
        </div>
        <span class="badge bg-{{ $enabled ? 'success' : 'secondary' }} fs-6">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>
    </div>

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card mb-4">
                <div class="card-header"><strong>Lifecycle Actions</strong></div>
                <div class="card-body">
                    @if ($protectedPackage)
                        <div class="alert alert-info mb-3">
                            Gaming Hub Manager reports its own installed presence for inventory purposes. Self-update, reinstall, enable/disable, backup, and uninstall actions are intentionally unavailable.
                        </div>
                        @can('gaminghub.manager.update')
                            <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.verify', $extension) }}">@csrf<button class="btn btn-outline-secondary">Verify Integrity</button></form>
                        @endcan
                    @else
                        <div class="d-flex gap-2 flex-wrap align-items-start">
                            @can('gaminghub.manager.lifecycle')
                                @if ($enabled)
                                    <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.disable', $extension) }}">@csrf @method('PATCH')
                                        @if (count($dependents) > 0)<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm_dependents" value="1" required id="confirm-dependents"><label class="form-check-label text-danger" for="confirm-dependents">Acknowledge dependent packages</label></div>@endif
                                        <button class="btn btn-outline-warning">Disable</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.enable', $extension) }}">@csrf @method('PATCH')<button class="btn btn-success">Enable</button></form>
                                @endif
                            @endcan

                            @can('gaminghub.manager.update')
                                <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.verify', $extension) }}">@csrf<button class="btn btn-outline-secondary">Verify Integrity</button></form>
                                @if ($catalogItem !== null)
                                    <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.update', $extension) }}">@csrf<input type="hidden" name="source_id" value="{{ $catalogItem['source']->id }}">
                                        @if (! $catalogItem['source']->trusted && $catalogItem['source']->type !== 'official')<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm_unverified" value="1" required id="update-untrusted"><label class="form-check-label text-danger" for="update-untrusted">Accept untrusted source</label></div>@endif
                                        <button class="btn btn-primary" @disabled($catalogItem['state'] !== 'update')>Update to {{ $catalogItem['latest_version'] }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.reinstall', $extension) }}">@csrf<input type="hidden" name="source_id" value="{{ $catalogItem['source']->id }}">
                                        @if (! $catalogItem['source']->trusted && $catalogItem['source']->type !== 'official')<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm_unverified" value="1" required id="reinstall-untrusted"><label class="form-check-label text-danger" for="reinstall-untrusted">Accept untrusted source</label></div>@endif
                                        <button class="btn btn-outline-primary">Reinstall {{ $catalogItem['latest_version'] }}</button>
                                    </form>
                                @endif
                            @endcan

                            @can('gaminghub.manager.backups')
                                <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.backup', $extension) }}">@csrf<button class="btn btn-outline-info">Create Backup</button></form>
                            @endcan
                            @can('gaminghub.manager.uninstall')
                                <a class="btn btn-outline-danger" href="{{ route('gaming-hub-manager.admin.packages.uninstall.confirm', $extension) }}">Uninstall</a>
                            @endcan
                        </div>
                        @if ($catalogItem === null)
                            <div class="alert alert-warning mt-3 mb-0">No enabled registry or GitHub source currently provides this package. Update and reinstall are unavailable until a matching source is enabled.</div>
                        @endif
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Package Metadata</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $extension->manifest_snapshot['description'] ?? '—' }}</dd>
                        <dt class="col-sm-4">Source</dt><dd class="col-sm-8">{{ $extension->source_id ?: 'Local discovery' }}</dd>
                        <dt class="col-sm-4">Repository</dt><dd class="col-sm-8 text-break">{{ $extension->repository_url ?: '—' }}</dd>
                        <dt class="col-sm-4">Trust</dt><dd class="col-sm-8">{{ $extension->trust_level }}</dd>
                        <dt class="col-sm-4">Release checksum</dt><dd class="col-sm-8">{{ $extension->checksum_verified ? 'Verified SHA-256' : 'Not verified' }}@if ($extension->checksum)<div><code>{{ $extension->checksum }}</code></div>@endif</dd>
                        <dt class="col-sm-4">File integrity</dt><dd class="col-sm-8"><span class="badge bg-{{ $extension->integrity_status === 'verified' ? 'success' : ($extension->integrity_status === 'changed' ? 'danger' : 'secondary') }}">{{ $extension->integrity_status }}</span>@if ($extension->integrity_hash)<div><code>{{ $extension->integrity_hash }}</code></div>@endif</dd>
                        <dt class="col-sm-4">Installed</dt><dd class="col-sm-8">{{ $extension->installed_at?->format('Y-m-d H:i:s') ?: 'Unknown' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card mb-4">
                <div class="card-header"><strong>Dependencies</strong></div>
                <div class="card-body">
                    @if (count($dependents) > 0)
                        <p class="text-danger">These installed packages depend on {{ $extension->extension_id }}:</p>
                        <ul class="mb-0">@foreach ($dependents as $dependent)<li>{{ $dependent['id'] }} {{ $dependent['constraint'] }}</li>@endforeach</ul>
                    @else
                        <div class="text-muted">No installed dependents detected.</div>
                    @endif
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header"><strong>Recent Backups</strong></div>
                <div class="list-group list-group-flush">
                    @forelse ($backups as $backup)
                        <div class="list-group-item"><strong>{{ $backup->version }}</strong> · {{ str_replace('_', ' ', $backup->reason) }}<div class="small text-muted">{{ $backup->created_at?->format('Y-m-d H:i:s') }}</div></div>
                    @empty
                        <div class="list-group-item text-muted">No backups for this package.</div>
                    @endforelse
                </div>
            </div>

            <div class="card">
                <div class="card-header"><strong>Recent Operations</strong></div>
                <div class="list-group list-group-flush">
                    @forelse ($operations as $operation)
                        <div class="list-group-item"><div class="d-flex justify-content-between"><strong>{{ ucfirst($operation->operation) }}</strong><span class="badge bg-{{ $operation->result === 'completed' ? 'success' : ($operation->result === 'failed' ? 'danger' : 'warning') }}">{{ $operation->result }}</span></div><div class="small text-muted">{{ $operation->started_at?->format('Y-m-d H:i:s') }} · {{ $operation->current_stage }}</div></div>
                    @empty
                        <div class="list-group-item text-muted">No operations for this package.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
