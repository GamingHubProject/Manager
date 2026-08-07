@extends('admin.layouts.admin')

@section('title', 'Backups')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="alert alert-warning">
        Rollback restores package files and the captured enabled state. It does not reverse database migrations or delete package data.
    </div>
    <div class="card">
        <div class="card-header"><strong>Verified Package Backups</strong><div class="small text-muted">{{ $backupPath }}</div></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr><th>Created</th><th>Package</th><th>Version</th><th>Reason</th><th>Integrity</th><th>Last restored</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse ($backups as $backup)
                    <tr>
                        <td>{{ $backup->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td><strong>{{ $backup->manifest_snapshot['name'] ?? $backup->extension_id }}</strong><div class="small text-muted">{{ $backup->extension_id }}</div></td>
                        <td>{{ $backup->version }}</td>
                        <td>{{ str_replace('_', ' ', $backup->reason) }}</td>
                        <td><code>{{ substr($backup->integrity_hash, 0, 16) }}…</code></td>
                        <td>{{ $backup->restored_at?->format('Y-m-d H:i:s') ?: 'Never' }}</td>
                        <td class="text-end">
                            <form class="d-inline-block text-start" method="POST" action="{{ route('gaming-hub-manager.admin.backups.restore', $backup) }}">
                                @csrf
                                <input class="form-control form-control-sm mb-1" name="confirmation" required placeholder="Type {{ $backup->extension_id }}">
                                <button class="btn btn-sm btn-warning">Restore / Rollback</button>
                            </form>
                            <form class="d-inline-block text-start" method="POST" action="{{ route('gaming-hub-manager.admin.backups.destroy', $backup) }}">
                                @csrf @method('DELETE')
                                <input type="hidden" name="confirmation" value="{{ $backup->backup_uuid }}">
                                <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Permanently delete this backup?')">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No backups recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $backups->links() }}</div>
    </div>
</div>
@endsection
