<?php
/**
 * NovaCPX Auto-Deploy Webhook Handler
 * Place at: https://<panel-ip>:2083/deploy/webhook.php
 * GitHub webhook: push to main branch → this endpoint
 * Secret set in /etc/novacpx/config.ini [deploy] webhook_secret
 */

$configFile = '/etc/novacpx/config.ini';
$cfg        = is_file($configFile) ? parse_ini_file($configFile, true) : [];
$secret     = $cfg['deploy']['webhook_secret'] ?? '';
$repoPath   = $cfg['deploy']['repo_path']      ?? '/opt/novacpx-src';
$webRoot    = $cfg['deploy']['web_root']       ?? '/srv/novacpx/public';
$branch     = $cfg['deploy']['branch']         ?? 'main';
$logFile    = '/var/log/novacpx/deploy.log';

header('Content-Type: application/json');

function log_deploy(string $msg): void {
    global $logFile;
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND | LOCK_EX);
}

// Verify HMAC signature
$rawBody = file_get_contents('php://input');
$hubSig  = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if ($secret) {
    $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
    if (!hash_equals($expected, $hubSig)) {
        http_response_code(403);
        log_deploy('BLOCKED: invalid signature');
        echo json_encode(['error' => 'Invalid signature']);
        exit;
    }
}

$payload = json_decode($rawBody, true);
$pushedBranch = basename($payload['ref'] ?? '');

// Accept pushes to main (stable) or beta — both can trigger deploys
$allowedBranches = ['main', 'beta'];
if (!in_array($pushedBranch, $allowedBranches)) {
    echo json_encode(['status' => 'skipped', 'reason' => "Not a deployable branch ($pushedBranch)"]);
    exit;
}

$commit  = $payload['after'] ?? 'unknown';
$pusher  = $payload['pusher']['name'] ?? 'unknown';
$message = $payload['head_commit']['message'] ?? '';

log_deploy("Deploy triggered by $pusher | branch $pushedBranch | commit $commit | $message");

// Queue the deploy — include branch so runner knows what to pull
$queueFile = '/tmp/novacpx-deploy-queue.txt';
file_put_contents($queueFile, "$repoPath|$webRoot|$commit\n", FILE_APPEND | LOCK_EX);

http_response_code(200);
echo json_encode(['status' => 'queued', 'commit' => $commit]);
