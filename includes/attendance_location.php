<?php
declare(strict_types=1);

/** Record the request's direct peer address. Proxy headers are deliberately ignored. */
function attendance_request_ip(): ?string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/** NULL means the employee has not yet made an application-level choice. */
function attendance_location_preference(int $userId): ?bool
{
    $stmt = db()->prepare('SELECT attendance_location_enabled FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $value = $stmt->fetchColumn();
    return $value === null ? null : (bool) $value;
}

function attendance_location_enabled(int $userId): bool
{
    return attendance_location_preference($userId) === true;
}

function attendance_location_start(int $attendanceId, int $userId, string $type, string $eventTime): int
{
    $preference = attendance_location_preference($userId);
    $ip = attendance_request_ip();
    $officeIp = trim((string) get_setting('office_public_ip', ''));
    $network = $officeIp === '' || $ip === null ? 'unknown' : ($ip === $officeIp ? 'office' : 'other');
    $stmt = db()->prepare('INSERT INTO attendance_location_events
        (attendance_id,user_id,event_type,event_time,location_status,client_ip,office_network,location_finalized_at)
        VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$attendanceId, $userId, $type, $eventTime,
        $preference === false ? 'sharing_disabled' : 'unavailable', $ip, $network,
        $preference === true ? null : $eventTime]);
    return (int) db()->lastInsertId();
}

function attendance_location_finalize(int $eventId, int $userId, array $input): bool
{
    return with_employee_lock($userId, function () use ($eventId, $userId, $input): bool {
        $stmt = db()->prepare('SELECT * FROM attendance_location_events WHERE id=? AND user_id=? FOR UPDATE');
        $stmt->execute([$eventId, $userId]);
        $event = $stmt->fetch();
        if (!$event || $event['location_finalized_at'] !== null
            || strtotime((string) $event['event_time']) < time() - 120
            || !attendance_location_enabled($userId)) return false;

        $status = (string) ($input['location_status'] ?? 'unavailable');
        if (!in_array($status, ['captured','low_accuracy','permission_denied','timed_out','unavailable','unsupported'], true)) {
            $status = 'unavailable';
        }
        $lat = $lon = $accuracy = $proximity = $method = null;
        if (in_array($status, ['captured','low_accuracy'], true)) {
            $lat = filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
            $accuracy = filter_var($input['accuracy_metres'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lon === false || $accuracy === false
                || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180
                || $accuracy < 0 || $accuracy > 100000) {
                $status = 'unavailable';
                $lat = $lon = $accuracy = null;
            } else {
                $status = $accuracy <= 100 ? 'captured' : 'low_accuracy';
                $method = 'browser_geolocation';
                $officeLat = get_setting('office_latitude');
                $officeLon = get_setting('office_longitude');
                $radius = (float) get_setting('office_radius_metres', '100');
                if ($officeLat !== null && $officeLon !== null && is_numeric($officeLat) && is_numeric($officeLon)) {
                    $a = deg2rad((float) $officeLat);
                    $b = deg2rad((float) $lat);
                    $deltaLat = $b - $a;
                    $deltaLon = deg2rad((float) $lon - (float) $officeLon);
                    $h = sin($deltaLat / 2) ** 2 + cos($a) * cos($b) * sin($deltaLon / 2) ** 2;
                    $distance = 6371000 * 2 * atan2(sqrt($h), sqrt(max(0, 1 - $h)));
                    $proximity = $distance <= $radius ? 'within' : 'outside';
                }
            }
        }
        db()->prepare('UPDATE attendance_location_events SET latitude=?,longitude=?,accuracy_metres=?,location_status=?,office_proximity=?,capture_method=?,location_finalized_at=NOW() WHERE id=?')
            ->execute([$lat, $lon, $accuracy, $status, $proximity, $method, $eventId]);
        return true;
    });
}

function attendance_location_label(?array $event): string
{
    if (!$event) return 'Legacy / unavailable';
    $labels = [
        'captured' => 'Captured', 'low_accuracy' => 'Low accuracy',
        'permission_denied' => 'Permission denied', 'timed_out' => 'Timed out',
        'unavailable' => 'Unavailable', 'unsupported' => 'Unsupported browser',
        'sharing_disabled' => 'Location sharing off',
    ];
    $label = $labels[$event['location_status']] ?? 'Unavailable';
    if ($event['accuracy_metres'] !== null) $label .= ' · ±' . round((float) $event['accuracy_metres']) . ' m';
    return $label;
}
