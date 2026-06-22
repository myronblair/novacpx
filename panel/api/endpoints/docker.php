<?php
require_once NOVACPX_LIB . '/DockerManager.php';

$auth    = Auth::getInstance();
$role    = $currentUser['role'];
$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$dm      = new DockerManager();
$isAdmin = $role === 'admin';

// Resolve the hosting account_id for the current non-admin user
$_userAccountId = 0;
if (!$isAdmin) {
    $acctRow = DB::getInstance()->fetchOne("SELECT id FROM accounts WHERE user_id = ? LIMIT 1", [$currentUser['uid']]);
    $_userAccountId = $acctRow ? (int)$acctRow['id'] : 0;
}

match ($action) {

    // ── Engine ──────────────────────────────────────────────────────────────
    'status' => (function() use ($dm, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        Response::success($dm->engineStatus());
    })(),

    'install' => (function() use ($dm, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $msg = $dm->install();
        audit('docker.install', 'system');
        Response::success(null, $msg);
    })(),

    'uninstall-account' => (function() use ($dm, $currentUser, $isAdmin, $_userAccountId, $body) {
        // Stop and remove all containers and stacks belonging to one account.
        // Users can only remove their own; admins can specify any account_id.
        $accountId = $isAdmin ? (int)($body['account_id'] ?? $_userAccountId) : ($_userAccountId ?? 0);
        if (!$accountId) Response::error('account_id required');

        $db = DB::getInstance();

        // Tear down compose stacks
        $stacks = $db->fetchAll("SELECT * FROM docker_compose_stacks WHERE account_id = ?", [$accountId]);
        foreach ($stacks as $stack) {
            if (is_dir($stack['stack_dir']) && file_exists("{$stack['stack_dir']}/docker-compose.yml")) {
                shell_exec("sudo docker compose -f " . escapeshellarg("{$stack['stack_dir']}/docker-compose.yml") . " down -v 2>/dev/null");
            }
            $db->execute("DELETE FROM docker_compose_stacks WHERE id = ?", [$stack['id']]);
        }

        // Stop and remove bare containers
        $containers = $db->fetchAll("SELECT container_id FROM docker_containers WHERE account_id = ?", [$accountId]);
        foreach ($containers as $c) {
            if ($c['container_id']) {
                shell_exec("sudo docker rm -f " . escapeshellarg($c['container_id']) . " 2>/dev/null");
            }
        }
        $db->execute("DELETE FROM docker_containers WHERE account_id = ?", [$accountId]);

        audit("docker.uninstall-account", "account:{$accountId}");
        Response::success(null, 'All Docker apps and containers removed for this account');
    })(),

    'prune' => (function() use ($dm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $out = $dm->systemPrune((bool)($body['volumes'] ?? false));
        audit('docker.prune', 'system');
        Response::success(['output' => $out], 'Prune complete');
    })(),

    // ── Containers ──────────────────────────────────────────────────────────
    'containers' => (function() use ($dm, $currentUser, $isAdmin, $role, $_userAccountId) {
        $accountId = $isAdmin ? (isset($_GET['account_id']) ? (int)$_GET['account_id'] : null)
                              : ($_userAccountId ?? null);
        if ($role === 'reseller') $accountId = null; // resellers see their customers' containers below
        $list = $dm->listContainers($accountId);
        Response::success(['containers' => $list]);
    })(),

    'container-action' => (function() use ($dm, $body, $currentUser, $isAdmin, $role, $_userAccountId) {
        $cid    = $body['container_id'] ?? '';
        $act    = $body['action'] ?? '';
        if (!$cid || !$act) Response::error('container_id and action required');
        // Access check for non-admins
        if (!$isAdmin) {
            $acctId = $_userAccountId ?? 0;
            $row = DB::getInstance()->fetchOne("SELECT id FROM docker_containers WHERE container_id=? AND account_id=?", [$cid, $acctId]);
            if (!$row) Response::error('Container not found', 404);
        }
        $out = $dm->containerAction($cid, $act);
        audit("docker.container.{$act}", "container:{$cid}");
        Response::success(['output' => $out]);
    })(),

    'container-remove' => (function() use ($dm, $body, $currentUser, $isAdmin, $_userAccountId) {
        $cid   = $body['container_id'] ?? '';
        $force = (bool)($body['force'] ?? false);
        if (!$cid) Response::error('container_id required');
        if (!$isAdmin) {
            $acctId = $_userAccountId ?? 0;
            $row = DB::getInstance()->fetchOne("SELECT id FROM docker_containers WHERE container_id=? AND account_id=?", [$cid, $acctId]);
            if (!$row) Response::error('Container not found', 404);
        }
        $dm->removeContainer($cid, $force);
        audit('docker.container.remove', "container:{$cid}");
        Response::success(null, 'Container removed');
    })(),

    'container-logs' => (function() use ($dm, $currentUser, $isAdmin, $_userAccountId) {
        $cid   = $_GET['container_id'] ?? '';
        $lines = min((int)($_GET['lines'] ?? 100), 500);
        if (!$cid) Response::error('container_id required');
        if (!$isAdmin) {
            $acctId = $_userAccountId ?? 0;
            $row = DB::getInstance()->fetchOne("SELECT id FROM docker_containers WHERE container_id=? AND account_id=?", [$cid, $acctId]);
            if (!$row) Response::error('Container not found', 404);
        }
        $logs = $dm->containerLogs($cid, $lines);
        Response::success(['logs' => $logs]);
    })(),

    'container-inspect' => (function() use ($dm, $currentUser, $isAdmin) {
        $cid = $_GET['container_id'] ?? '';
        if (!$cid) Response::error('container_id required');
        if (!$isAdmin) Response::error('Admin only', 403);
        $data = $dm->containerInspect($cid);
        Response::success(['inspect' => $data]);
    })(),

    'container-run' => (function() use ($dm, $body, $currentUser, $isAdmin, $role, $_userAccountId) {
        if ($isAdmin) {
            $accountId = (int)($body['account_id'] ?? 0);
            if (!$accountId) Response::error('account_id required');
        } else {
            $accountId = $_userAccountId ?? 0;
        }
        if (!$accountId) Response::error('No account context');
        $image = $body['image'] ?? '';
        $name  = $body['name']  ?? '';
        if (!$image || !$name) Response::error('image and name required');
        $result = $dm->runContainer($accountId, $image, $name, $body);
        audit('docker.container.run', "account:{$accountId} image:{$image}");
        Response::success($result, 'Container started');
    })(),

    // ── Images ──────────────────────────────────────────────────────────────
    'images' => (function() use ($dm, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        Response::success(['images' => $dm->listImages()]);
    })(),

    'image-pull' => (function() use ($dm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $image = $body['image'] ?? '';
        if (!$image) Response::error('image required');
        $out = $dm->pullImage($image);
        audit('docker.image.pull', "image:{$image}");
        Response::success(['output' => $out], 'Image pulled');
    })(),

    'image-remove' => (function() use ($dm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $id = $body['image_id'] ?? '';
        if (!$id) Response::error('image_id required');
        $out = $dm->removeImage($id);
        audit('docker.image.remove', "image:{$id}");
        Response::success(['output' => $out], 'Image removed');
    })(),

    // ── Volumes & Networks ───────────────────────────────────────────────────
    'volumes' => (function() use ($dm, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        Response::success(['volumes' => $dm->listVolumes()]);
    })(),

    'networks' => (function() use ($dm, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        Response::success(['networks' => $dm->listNetworks()]);
    })(),

    // ── Compose Stacks ───────────────────────────────────────────────────────
    'stacks' => (function() use ($dm, $currentUser, $isAdmin, $_userAccountId) {
        $accountId = $isAdmin ? (isset($_GET['account_id']) ? (int)$_GET['account_id'] : null)
                              : ($_userAccountId ?? null);
        Response::success(['stacks' => $dm->listStacks($accountId)]);
    })(),

    'stack-create' => (function() use ($dm, $body, $currentUser, $isAdmin, $_userAccountId) {
        $accountId = $isAdmin ? ($body['account_id'] ?? null) : ($_userAccountId ?? null);
        $name      = $body['name']         ?? '';
        $yaml      = $body['compose_yaml'] ?? '';
        if (!$name || !$yaml) Response::error('name and compose_yaml required');
        $result = $dm->createStack($accountId ? (int)$accountId : null, $name, $yaml);
        audit('docker.stack.create', "name:{$name}");
        Response::success($result, 'Stack created');
    })(),

    'stack-action' => (function() use ($dm, $body, $isAdmin, $_userAccountId) {
        $id  = (int)($body['stack_id'] ?? 0);
        $act = $body['action'] ?? '';
        if (!$id || !$act) Response::error('stack_id and action required');
        // Non-admins can only act on their own stacks
        if (!$isAdmin) {
            $stack = DB::getInstance()->fetchOne("SELECT account_id FROM docker_compose_stacks WHERE id = ?", [$id]);
            if (!$stack || (int)$stack['account_id'] !== (int)$_userAccountId) Response::error('Not found', 404);
        }
        $out = $dm->composeAction($id, $act);
        audit("docker.stack.{$act}", "stack:{$id}");
        Response::success(['output' => $out]);
    })(),

    'stack-reinstall' => (function() use ($dm, $body, $isAdmin, $_userAccountId) {
        $id = (int)($body['stack_id'] ?? 0);
        if (!$id) Response::error('stack_id required');
        if (!$isAdmin) {
            $stack = DB::getInstance()->fetchOne("SELECT account_id FROM docker_compose_stacks WHERE id = ?", [$id]);
            if (!$stack || (int)$stack['account_id'] !== (int)$_userAccountId) Response::error('Not found', 404);
        }
        // Pull latest images, bring down (removing volumes), then start fresh
        $dm->composeAction($id, 'pull');
        $dm->composeAction($id, 'down');
        $out = $dm->composeAction($id, 'up');
        audit('docker.stack.reinstall', "stack:{$id}");
        Response::success(['output' => $out], 'Stack reinstalled');
    })(),

    'stack-remove' => (function() use ($dm, $body, $isAdmin, $_userAccountId) {
        $id = (int)($body['stack_id'] ?? 0);
        if (!$id) Response::error('stack_id required');
        if (!$isAdmin) {
            $stack = DB::getInstance()->fetchOne("SELECT account_id FROM docker_compose_stacks WHERE id = ?", [$id]);
            if (!$stack || (int)$stack['account_id'] !== (int)$_userAccountId) Response::error('Not found', 404);
        }
        $dm->removeStack($id);
        audit('docker.stack.remove', "stack:{$id}");
        Response::success(null, 'Stack removed');
    })(),

    // ── Quotas ───────────────────────────────────────────────────────────────
    'quota-get' => (function() use ($dm, $currentUser, $isAdmin) {
        $userId = $isAdmin && isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$currentUser['uid'];
        Response::success(['quota' => $dm->getQuota($userId)]);
    })(),

    'quota-set' => (function() use ($dm, $body, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $userId = (int)($body['user_id'] ?? 0);
        if (!$userId) Response::error('user_id required');
        $dm->setQuota($userId, (int)($body['max_containers'] ?? 2), (int)($body['max_memory_mb'] ?? 512), (float)($body['max_cpus'] ?? 1.0));
        audit('docker.quota.set', "user:{$userId}");
        Response::success(null, 'Quota saved');
    })(),

    // ── App Catalog ──────────────────────────────────────────────────────────
    'catalog' => (function() use ($dm) {
        Response::success(['catalog' => DockerManager::getCatalog()]);
    })(),

    'launch' => (function() use ($dm, $body, $currentUser, $isAdmin, $_userAccountId) {
        $accountId = $isAdmin ? (int)($body['account_id'] ?? 0) : ($_userAccountId ?? 0);
        if (!$accountId) Response::error('account_id required');
        $appKey = $body['app_key'] ?? '';
        $params = $body['params'] ?? [];
        if (!$appKey) Response::error('app_key required');
        $result = $dm->launchFromCatalog($accountId, $appKey, $params);
        audit("docker.launch.{$appKey}", "account:{$accountId}");
        Response::success($result, ucfirst($appKey) . ' launched successfully');
    })(),

    'sync-orphans' => (function() use ($dm, $isAdmin) {
        if (!$isAdmin) Response::error('Admin only', 403);
        $result = shell_exec('docker ps -a --format "{{json .}}" 2>/dev/null') ?? '';
        $db     = \DB::getInstance();
        $added  = 0;
        foreach (explode("\n", trim($result)) as $line) {
            if (!$line) continue;
            $c = json_decode($line, true);
            if (!$c) continue;
            $cid  = $c['ID'] ?? '';
            $name = ltrim($c['Names'] ?? '', '/');
            $img  = $c['Image'] ?? '';
            $st   = $c['State'] ?? 'unknown';
            if (!$cid) continue;
            $ex = $db->fetchOne('SELECT id FROM docker_containers WHERE container_id=?', [$cid]);
            if (!$ex) {
                $db->execute('INSERT INTO docker_containers (container_id,name,image,status,account_id,created_at) VALUES (?,?,?,?,0,datetime("now"))', [$cid,$name,$img,$st]);
                $added++;
            }
        }
        Response::success(['added' => $added], "Synced $added orphaned containers");
    })(),

    default => Response::error("Unknown docker action: $action", 404),
};
