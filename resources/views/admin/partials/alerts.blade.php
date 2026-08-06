@php
    /** @var \Azuriom\Plugin\GamingHubManager\Support\ManagerAlertNormalizer $alertNormalizer */
    $alertNormalizer = app(\Azuriom\Plugin\GamingHubManager\Support\ManagerAlertNormalizer::class);
    $validationMessages = $alertNormalizer->validation($errors ?? null);
    $managerAlertRecords = [
        ...$alertNormalizer->custom($managerAlerts ?? []),
        ...$alertNormalizer->custom(session('managerAlerts', [])),
    ];
    $flashAlerts = array_values(array_filter([
        $alertNormalizer->flash(session('success'), 'success'),
        $alertNormalizer->flash(session('warning'), 'warning'),
        $alertNormalizer->flash(session('error'), 'danger'),
    ]));
@endphp

@if ($validationMessages !== [])
    <div class="alert alert-danger">
        <strong>Please correct the following:</strong>
        <ul class="mb-0 mt-2">
            @foreach ($validationMessages as $validationMessage)
                <li>{{ $validationMessage }}</li>
            @endforeach
        </ul>
    </div>
@endif

@foreach ($managerAlertRecords as $managerAlert)
    <div class="alert alert-{{ $managerAlert['level'] }}">
        @if ($managerAlert['label'] !== null)
            <strong>{{ $managerAlert['label'] }}:</strong>
        @endif
        {{ $managerAlert['message'] }}
    </div>
@endforeach

@foreach ($flashAlerts as $flashAlert)
    <div class="alert alert-{{ $flashAlert['level'] }}">{{ $flashAlert['message'] }}</div>
@endforeach
