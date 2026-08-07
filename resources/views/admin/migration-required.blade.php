@extends('admin.layouts.admin')

@section('title', 'Gaming Hub Manager')

@section('content')
<div class="container-fluid">
    <div class="alert alert-warning mb-0" role="alert">
        <h4 class="alert-heading">Gaming Hub Manager migrations are not ready</h4>
        @if (! ($runtimeStatus['database_available'] ?? false))
            <p class="mb-2">The database connection is currently unavailable. Manager initialization was skipped safely.</p>
        @else
            <p class="mb-2">The package is installed, but its database schema is incomplete. Run the pending Azuriom migrations, then reload this page.</p>
            @if (($runtimeStatus['missing_tables'] ?? []) !== [])
                <div class="small text-muted">Missing tables: {{ implode(', ', $runtimeStatus['missing_tables']) }}</div>
            @endif
        @endif
    </div>
</div>
@endsection
