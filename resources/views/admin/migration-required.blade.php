@extends('admin.layouts.admin')

@section('title', 'Gaming Hub Manager')

@section('content')
<div class="container-fluid">
    @php($schemaState = $runtimeStatus['schema_state'] ?? 'MIGRATIONS_PENDING')
    <div class="alert alert-warning mb-0" role="alert">
        <h4 class="alert-heading">Gaming Hub Manager database is not ready</h4>

        @if ($schemaState === 'DATABASE_UNAVAILABLE')
            <p class="mb-2">The database is currently unavailable. Manager initialization and all package lifecycle actions were skipped safely.</p>
            <p class="mb-0">Restore the normal Azuriom database connection, then reload this page.</p>
        @elseif ($schemaState === 'SCHEMA_INCONSISTENT')
            <p class="mb-2">Gaming Hub Manager migration history and the physical Manager schema disagree.</p>
            <p class="mb-2">Automatic repair was intentionally not attempted. Back up the database and review the Manager migration/schema state using your normal server maintenance workflow before continuing.</p>
        @else
            <p class="mb-2">Gaming Hub Manager database migrations are pending. Complete the supported Azuriom migration procedure, then reload this page.</p>
        @endif

        <dl class="row small mb-0 mt-3">
            <dt class="col-sm-3">Schema health</dt>
            <dd class="col-sm-9"><code>{{ $schemaState }}</code></dd>

            <dt class="col-sm-3">Database available</dt>
            <dd class="col-sm-9">{{ ($runtimeStatus['database_available'] ?? false) ? 'Yes' : 'No' }}</dd>

            <dt class="col-sm-3">Migration history available</dt>
            <dd class="col-sm-9">{{ ($runtimeStatus['migration_history_available'] ?? false) ? 'Yes' : 'No' }}</dd>

            @if (($runtimeStatus['missing_tables'] ?? []) !== [])
                <dt class="col-sm-3">Missing Manager tables</dt>
                <dd class="col-sm-9"><code>{{ implode(', ', $runtimeStatus['missing_tables']) }}</code></dd>
            @endif

            @if (($runtimeStatus['pending_migrations'] ?? []) !== [])
                <dt class="col-sm-3">Pending Manager migrations</dt>
                <dd class="col-sm-9"><code>{{ implode(', ', $runtimeStatus['pending_migrations']) }}</code></dd>
            @endif

            @if (($runtimeStatus['recorded_missing_tables'] ?? []) !== [])
                <dt class="col-sm-3">Recorded migrations with missing tables</dt>
                <dd class="col-sm-9"><code>{{ implode(', ', $runtimeStatus['recorded_missing_tables']) }}</code></dd>
            @endif

            @if (($runtimeStatus['unrecorded_existing_tables'] ?? []) !== [])
                <dt class="col-sm-3">Tables without expected migration records</dt>
                <dd class="col-sm-9"><code>{{ implode(', ', $runtimeStatus['unrecorded_existing_tables']) }}</code></dd>
            @endif
        </dl>
    </div>
</div>
@endsection
