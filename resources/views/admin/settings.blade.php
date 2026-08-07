@extends('admin.layouts.admin')

@section('title', 'Manager Settings')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card">
                <div class="card-header"><strong>Lifecycle Settings</strong></div>
                <div class="card-body">
                    <form method="POST" action="{{ route('gaming-hub-manager.admin.settings.update') }}">
                        @csrf @method('PUT')
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="retain_successful_update_backups" value="1" id="setting-backups" @checked($settings['retain_successful_update_backups'])><label class="form-check-label" for="setting-backups">Retain successful update and reinstall backups</label></div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="auto_import_legacy_core_metadata" value="1" id="setting-import" @checked($settings['auto_import_legacy_core_metadata'])><label class="form-check-label" for="setting-import">Import legacy Gaming Hub Core installer metadata</label></div>
                        <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="allow_private_hosts" value="1" id="setting-private" @checked($settings['allow_private_hosts'])><label class="form-check-label" for="setting-private">Allow administrators to opt into private-host sources</label><div class="form-text">Individual sources must still explicitly enable private-host access.</div></div>
                        <div class="mb-3"><label class="form-label" for="setting-staging">Delete stale staging after hours</label><input class="form-control" type="number" min="1" max="168" id="setting-staging" name="stale_staging_hours" value="{{ $settings['stale_staging_hours'] }}" required></div>
                        <div class="mb-3"><label class="form-label" for="setting-logs">Operation log retention days</label><input class="form-control" type="number" min="7" max="3650" id="setting-logs" name="operation_log_retention_days" value="{{ $settings['operation_log_retention_days'] }}" required></div>
                        <button class="btn btn-primary">Save Settings</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card">
                <div class="card-header"><strong>Compatibility Diagnostics</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-5">PHP</dt><dd class="col-sm-7">{{ $diagnostics['php'] }}</dd>
                        <dt class="col-sm-5">ZIP extension</dt><dd class="col-sm-7"><span class="badge bg-{{ $diagnostics['zip'] ? 'success' : 'danger' }}">{{ $diagnostics['zip'] ? 'Available' : 'Missing' }}</span></dd>
                        <dt class="col-sm-5">Plugins writable</dt><dd class="col-sm-7"><span class="badge bg-{{ $diagnostics['plugin_root_writable'] ? 'success' : 'danger' }}">{{ $diagnostics['plugin_root_writable'] ? 'Yes' : 'No' }}</span><div class="small text-muted text-break">{{ $diagnostics['plugin_root'] }}</div></dd>
                        <dt class="col-sm-5">Storage writable</dt><dd class="col-sm-7"><span class="badge bg-{{ $diagnostics['storage_root_writable'] ? 'success' : 'danger' }}">{{ $diagnostics['storage_root_writable'] ? 'Yes' : 'No' }}</span><div class="small text-muted text-break">{{ $diagnostics['storage_root'] }}</div></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
