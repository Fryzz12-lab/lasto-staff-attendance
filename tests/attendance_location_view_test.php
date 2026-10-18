<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/attendance_location_view.php';

function expect_view(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$base = [
    'location_status' => 'captured',
    'latitude' => '3.1390000',
    'longitude' => '101.6869000',
    'accuracy_metres' => '80.00',
    'office_proximity' => 'within',
    'office_network' => 'office',
    'client_ip' => '203.0.113.10',
];

foreach ([
    'captured' => ['Captured', 'badge-present'],
    'low_accuracy' => ['Low accuracy', 'badge-late'],
    'permission_denied' => ['Permission denied', 'badge-pending'],
    'timed_out' => ['Timed out', 'badge-off'],
    'unavailable' => ['Unavailable', 'badge-off'],
    'sharing_disabled' => ['Location sharing off', 'badge-off'],
] as $status => [$label, $badge]) {
    $overrides = ['location_status' => $status];
    if ($status === 'low_accuracy') $overrides['accuracy_metres'] = '180.00';
    if (in_array($status, ['permission_denied', 'timed_out', 'unavailable', 'sharing_disabled'], true)) {
        $overrides = array_replace($overrides, [
            'latitude' => null, 'longitude' => null, 'accuracy_metres' => null, 'office_proximity' => null,
        ]);
    }
    $view = attendance_location_admin_view(array_replace($base, $overrides));
    expect_view($view['status_label'] === $label && $view['status_badge'] === $badge, "$status status presentation failed");
    if ($status === 'low_accuracy') expect_view($view['accuracy'] === 180, 'Low accuracy should remain visible');
    if ($status === 'permission_denied') expect_view($view['map_url'] === null, 'Denied location should not offer a map');
}

$view = attendance_location_admin_view($base);
expect_view($view['accuracy'] === 80 && $view['status_label'] === 'Captured', 'Accuracy must remain separate from status');
expect_view($view['office']['tone'] === 'positive' && $view['network'] === 'Office network', 'Configured office presentation failed');
expect_view($view['map_url'] !== null, 'Captured coordinates should offer a map');

$view = attendance_location_admin_view(array_replace($base, ['office_proximity' => 'outside', 'office_network' => 'other']));
expect_view($view['office']['tone'] === 'warning' && $view['network'] === 'Other network', 'Outside/other presentation failed');

$view = attendance_location_admin_view(array_replace($base, ['office_proximity' => null, 'office_network' => 'unknown']));
expect_view($view['office']['tone'] === 'unavailable' && $view['office']['helper'] === 'Office location has not been configured.', 'Unconfigured office presentation failed');
expect_view($view['network'] === 'Unknown', 'Unknown network presentation failed');

$view = attendance_location_admin_view(array_replace($base, [
    'location_status' => 'sharing_disabled', 'latitude' => null, 'longitude' => null,
    'accuracy_metres' => null, 'office_proximity' => null,
]));
expect_view($view['accuracy'] === null && $view['map_url'] === null, 'Missing location should not offer accuracy or map');
expect_view($view['office']['helper'] === 'No coordinates were captured for this event.', 'Missing coordinates helper failed');

foreach (['127.0.0.1', '::1'] as $loopback) {
    $view = attendance_location_admin_view(array_replace($base, ['client_ip' => $loopback, 'office_network' => 'office']));
    expect_view($view['network'] === 'Localhost', 'Loopback must not be displayed as Office network');
}

echo "Attendance location admin presentation states passed.\n";
