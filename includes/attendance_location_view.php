<?php
declare(strict_types=1);

/** Admin-only presentation values; attendance and location records are not changed. */
function attendance_location_admin_view(array $event, ?string $officeName = null, ?int $allowedRadius = null): array
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

    if ($officeName === null && !empty($event['office_id']) && function_exists('db')) {
        try {
            $stmt = db()->prepare('SELECT name, radius_meters FROM offices WHERE id = ?');
            $stmt->execute([(int) $event['office_id']]);
            $off = $stmt->fetch();
            if ($off) {
                $officeName = (string) $off['name'];
                if ($allowedRadius === null) $allowedRadius = (int) $off['radius_meters'];
            }
        } catch (\Throwable) {}
    }

    $distance = isset($event['distance_metres']) && $event['distance_metres'] !== null ? (float) $event['distance_metres'] : null;
    $distanceStr = null;
    if ($distance !== null) {
        $distanceStr = $distance < 1000 ? round($distance) . ' m' : round($distance / 1000, 1) . ' km';
    }

    $withinExtra = '';
    if ($officeName) {
        $parts = [$officeName];
        if ($distanceStr) $parts[] = $distanceStr . ($allowedRadius ? " / {$allowedRadius} m allowed" : '');
        $withinExtra = ' (' . implode(' · ', $parts) . ')';
    }

    $outsideExtra = '';
    if ($officeName) {
        $parts = [$officeName];
        if ($distanceStr) $parts[] = $distanceStr . ' from office';
        $outsideExtra = ' (' . implode(' · ', $parts) . ')';
    }

    $office = match ($proximity) {
        'within' => ['label' => 'Within office area' . $withinExtra, 'tone' => 'positive', 'helper' => null],
        'outside' => ['label' => 'Outside office area' . $outsideExtra, 'tone' => 'warning', 'helper' => null],
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
        'office_name' => $officeName,
        'distance_metres' => $distance,
        'network' => $network,
        'client_ip' => $clientIp,
        'map_url' => $hasCoordinates
            ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($event['latitude'] . ',' . $event['longitude'])
            : null,
    ];
}
