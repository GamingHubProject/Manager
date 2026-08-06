@extends('admin.layouts.admin')

@section('title', 'Release Details')

@section('content')
<div class="container-fluid">
    @include('gaming-hub-manager::admin.partials.package-warning')
    @include('gaming-hub-manager::admin.partials.alerts')

    <div class="card">
        <div class="card-header"><strong>Release Inspection: {{ $packageId }}</strong></div>
        <div class="card-body">
            <dl class="row">
                <dt class="col-sm-3">Source</dt><dd class="col-sm-9">{{ $source->name }} <span class="badge bg-{{ $source->type === 'official' ? 'primary' : ($source->trusted ? 'success' : 'danger') }}">{{ $source->trust_level }}</span></dd>
                <dt class="col-sm-3">Repository</dt><dd class="col-sm-9 text-break">{{ $metadata['repository'] ?? $source->url }}</dd>
                <dt class="col-sm-3">Release tag</dt><dd class="col-sm-9">{{ $release['tag_name'] ?? 'Unknown' }}</dd>
                <dt class="col-sm-3">Selected version</dt><dd class="col-sm-9">{{ $selectedVersion }}</dd>
                <dt class="col-sm-3">Release name</dt><dd class="col-sm-9">{{ $release['name'] ?? '—' }}</dd>
                <dt class="col-sm-3">Published</dt><dd class="col-sm-9">{{ $release['published_at'] ?? 'Unknown' }}</dd>
                <dt class="col-sm-3">Prerelease</dt><dd class="col-sm-9">{{ ($release['prerelease'] ?? false) ? 'Yes' : 'No' }}</dd>
                <dt class="col-sm-3">Selected ZIP</dt><dd class="col-sm-9">{{ $asset['name'] ?? 'Unknown' }} @if (isset($asset['size']))({{ number_format($asset['size'] / 1048576, 2) }} MiB)@endif</dd>
                <dt class="col-sm-3">Checksum</dt><dd class="col-sm-9"><span class="badge bg-success">SHA-256 available</span><div><code>{{ $checksum }}</code></div></dd>
                <dt class="col-sm-3">Checksum source</dt><dd class="col-sm-9"><code>{{ $checksumSource }}</code></dd>
                <dt class="col-sm-3">Checksum asset</dt><dd class="col-sm-9">{{ $checksumAsset['name'] ?? 'Not used' }}</dd>
            </dl>
            @if (! empty($release['body']))
                <hr><h3 class="h5">Release Notes</h3><pre class="bg-light border rounded p-3" style="white-space: pre-wrap;">{{ $release['body'] }}</pre>
            @endif
        </div>
    </div>
</div>
@endsection
