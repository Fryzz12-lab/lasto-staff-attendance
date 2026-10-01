<?php
/**
 * Staff correction requests removed — redirect to Leave / WFH.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
redirect('/staff/leave.php');
