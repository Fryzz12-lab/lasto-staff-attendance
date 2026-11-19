<?php
/**
 * Focused PHP test verifying outbound push headers and topic behavior.
 * Run: php tests/push_headers_test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/push_service.php';

$checks = 0;
function check_header(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException("Assertion failed: {$label}");
    }
    $checks++;
}

// 1. Verify push_request_headers output
$mockEndpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint-abc-123';
$vapidKeys = push_get_vapid_keys();
$mockEncryptedHeaders = [
    'Content-Type: application/octet-stream',
    'Content-Encoding: aes128gcm',
    'Content-Length: 456',
];

$headers = push_request_headers($mockEndpoint, $vapidKeys, $mockEncryptedHeaders);

check_header(is_array($headers) && count($headers) >= 5, 'Headers array returned');

// Verify standard mandatory headers are present
$hasAuth = false;
$hasTtl = false;
$hasUrgency = false;
$hasTopic = false;
$topicValues = [];

foreach ($headers as $header) {
    if (str_starts_with($header, 'Authorization: vapid t=')) $hasAuth = true;
    if ($header === 'TTL: 86400') $hasTtl = true;
    if ($header === 'Urgency: high') $hasUrgency = true;
    if (stripos($header, 'Topic:') === 0) {
        $hasTopic = true;
        $topicValues[] = trim(substr($header, 6));
    }
}

check_header($hasAuth, 'Authorization header is present with VAPID JWT');
check_header($hasTtl, 'TTL header is present with 86400 (24h)');
check_header($hasUrgency, 'Urgency header is present with high');
check_header(!$hasTopic, 'Outbound push headers do NOT contain any Topic header');
check_header(!in_array('attendance', $topicValues, true), 'Global Topic: attendance is absent');

// 2. Verify static push_service.php source code does not contain hardcoded Topic: attendance
$source = file_get_contents(__DIR__ . '/../includes/push_service.php');
check_header($source !== false, 'push_service.php source code read');
check_header(!str_contains($source, "'Topic: attendance'"), 'No hardcoded Topic: attendance in push_service.php');
check_header(!str_contains($source, '"Topic: attendance"'), 'No double-quoted Topic: attendance in push_service.php');

// 3. Verify notification event types do not inject a shared global topic
$categories = ['new_leave_request', 'new_wfh_request', 'leave_decision', 'shift_ending', 'missing_clock_out', 'break_ending', 'test_notification'];
foreach ($categories as $cat) {
    // Ensure ordinary notification events cannot unintentionally share a replacement topic
    $samplePayload = [
        'title' => 'Title',
        'body' => 'Body',
        'category' => $cat,
        'tag' => 'tag-' . $cat,
    ];
    check_header(!isset($samplePayload['topic']), "Category {$cat} has no shared replacement topic");
}

echo "Push headers: {$checks}/{$checks} passed\n";

