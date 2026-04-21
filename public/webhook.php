<?php
// =============================================================================
// GitHub Webhook Receiver — Phase 2 Stub
// =============================================================================
// This endpoint is prepared but not active. It will receive POST events from
// GitHub and trigger automatic deployments in Phase 2.
// =============================================================================
require_once __DIR__ . '/../app/helpers.php';
app_boot();

http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['message' => 'Webhook receiver active. Implementation pending (Phase 2).']);
exit;
