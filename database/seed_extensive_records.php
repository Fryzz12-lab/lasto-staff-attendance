<?php
/** Compatibility entry point: safe scoped UAT seeder. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../cli/seed_uat.php';
