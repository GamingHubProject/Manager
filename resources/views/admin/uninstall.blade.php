@extends('admin.layouts.admin')

@section('title', 'Uninstall Package')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="card border-danger">
        <div class="card-header bg-danger text-white"><strong>Uninstall {{ $extension->manifest_snapshot['name'] ?? $extension->extension_id }}</strong></div>
        <div class="card-body">
            <div class="alert alert-warning">Manager will create a verified recovery backup, disable the plugin, remove its executable files, and retain its database data. Restoring the backup does not reverse database migrations.</div>
            @if (count($dependents) > 0)
                <div class="alert alert-danger"><strong>Uninstall is blocked by dependents:</strong><ul class="mb-0 mt-2">@foreach ($dependents as $dependent)<li>{{ $dependent['id'] }} requires {{ $extension->extension_id }} {{ $dependent['constraint'] }}</li>@endforeach</ul></div>
            @endif
            <dl class="row"><dt class="col-sm-3">Package ID</dt><dd class="col-sm-9"><code>{{ $extension->extension_id }}</code></dd><dt class="col-sm-3">Version</dt><dd class="col-sm-9">{{ $extension->installed_version }}</dd><dt class="col-sm-3">State</dt><dd class="col-sm-9">{{ $enabled ? 'Enabled' : 'Disabled' }}</dd></dl>
            <form method="POST" action="{{ route('gaming-hub-manager.admin.packages.uninstall', $extension) }}">
                @csrf @method('DELETE')
                <div class="mb-3"><label class="form-label" for="uninstall-confirmation">Type <code>{{ $extension->extension_id }}</code></label><input class="form-control" id="uninstall-confirmation" name="confirmation" required autocomplete="off"></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="retain_data" value="1" required id="retain-data"><label class="form-check-label" for="retain-data">I understand package database data and migrations are retained</label></div>
                <button class="btn btn-danger" @disabled(count($dependents) > 0)>Create Backup and Uninstall Files</button>
                <a class="btn btn-outline-secondary" href="{{ route('gaming-hub-manager.admin.packages.show', $extension) }}">Cancel</a>
            </form>
        </div>
    </div>
</div>
@endsection
