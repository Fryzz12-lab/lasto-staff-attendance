<?php
declare(strict_types=1);

/** Admin-only presentation values; attendance and location records are not changed. */
function attendance_location_admin_view(array $event): array
{
    $status = (string) ($event['location_status'] ?? 'unavailable');
    $labels = [
        'captured' => 'Captured', 'low_accuracy' => 'Low accuracy',
        'permission_denied' => 'Permission denied', 'timed_out' => 'Timed out',
        'unavailable' => 'Unavailable', 'unsupported' => 'Unsupported browser',
        'sharing_disabled' => 'Location sharing off',
    ];
    $badges = [
        'captured' => 'badge-present', 'low_accuracy' => 'badge-late',
        'permission_denied' => 'badge-pending',
    ];
    $hasCoordinates = $event['latitude'] !== null && $event['longitude'] !== null;
    $proximity = $event['office_proximity'] ?? null;
    $office = match ($proximity) {
        'within' => ['label' => 'Within office area', 'tone' => 'positive', 'helper' => null],
        'outside' => ['label' => 'Outside office area', 'tone' => 'warning', 'helper' => null],
        default => [
            'label' => 'Office comparison unavailable',
            'tone' => 'unavailable',
            'helper' => $hasCoordinates ? 'Office location has not been configured.' : 'No coordinates were captured for this event.',
        ],
    };
    $clientIp = (string) ($event['client_ip'] ?? '');
    $network = in_array($clientIp, ['127.0.0.1', '::1'], true)
        ? 'Localhost'
        : match ($event['office_network'] ?? 'unknown') {
            'office' => 'Office network',
            'other' => 'Other network',
            default => 'Unknown',
        };

    return [
        'has_coordinates' => $hasCoordinates,
        'status_label' => $labels[$status] ?? 'Unavailable',
        'status_badge' => $badges[$status] ?? 'badge-off',
        'accuracy' => $event['accuracy_metres'] !== null ? (int) round((float) $event['accuracy_metres']) : null,
        'office' => $office,
        'network' => $network,
        'client_ip' => $clientIp,
        'map_url' => $hasCoordinates
            ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($event['latitude'] . ',' . $event['longitude'])
            : null,
    ];
}
