@extends('admin.layouts.admin')

@section('title', 'Install Logs')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="card">
        <div class="card-header"><strong>Install and Lifecycle Logs</strong></div>
        <div class="table-responsive">
            <table class="table table-striped mb-0 align-middle">
                <thead><tr><th>Started</th><th>Operation</th><th>Package</th><th>Version</th><th>Stage</th><th>Result</th><th>Summary</th></tr></thead>
                <tbody>
                @forelse ($operations as $operation)
                    <tr>
                        <td class="text-nowrap">{{ $operation->started_at?->format('Y-m-d H:i:s') }}</td>
                        <td>{{ ucfirst($operation->operation) }}<div class="small text-muted">{{ $operation->operation_uuid }}</div></td>
                        <td>{{ $operation->extension_id ?: 'Direct package' }}</td>
                        <td>{{ $operation->version ?: '—' }}</td>
                        <td>{{ $operation->current_stage }}</td>
                        <td><span class="badge bg-{{ $operation->result === 'completed' ? 'success' : ($operation->result === 'failed' ? 'danger' : 'warning') }}">{{ $operation->result }}</span>@if ($operation->rollback_attempted)<div class="small">Rollback: {{ $operation->rollback_succeeded ? 'succeeded' : 'failed' }}</div>@endif</td>
                        <td style="min-width: 280px;">{{ $operation->summary ?: 'In progress' }}
                            @if (! empty($operation->context['checksum_source']))
                                <div class="small text-muted">Checksum source: <code>{{ $operation->context['checksum_source'] }}</code></div>
                            @endif
                            @if (! empty($operation->events))
                                <details class="mt-2"><summary>Event timeline</summary><ol class="small mt-2 mb-0">@foreach ($operation->events as $event)<li><strong>{{ $event['stage'] ?? 'unknown' }}</strong>: {{ $event['message'] ?? '' }}</li>@endforeach</ol></details>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No Manager operations recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-footer">{{ $operations->links() }}</div>
    </div>
</div>
@endsection
