<?php

require_once __DIR__ . '/../admin/admin_boot.php';

use Photobooth\Service\SystemStatusService;

header('Content-Type: application/json');
header('Cache-Control: no-store');

echo json_encode(SystemStatusService::getInstance()->getStatus());
exit();
