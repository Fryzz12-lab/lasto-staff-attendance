<?php
/** Retired: the old updater generated inconsistent totals. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
fwrite(STDERR, "Use php cli/seed_uat.php --reset to refresh only the UAT scenarios.\n");
exit(1);
