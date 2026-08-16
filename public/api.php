<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Strict']);
require_once dirname(__DIR__) . '/src/Http.php';
require_once dirname(__DIR__) . '/src/bootstrap.php';

use function IpDesigner\Http\routePath;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(['data'=>$data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function error_response(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error'=>['message'=>$message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new InvalidArgumentException('Request enthält kein gültiges JSON.');
    return $data;
}

function id_from(array $parts, int $index): ?int
{
    return isset($parts[$index]) && ctype_digit($parts[$index]) ? (int)$parts[$index] : null;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','HEAD','OPTIONS'], true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) error_response('Ungültiges CSRF-Token.', 403);
    }
    $path = routePath();
    $path = preg_replace('#^/api/?#', '', $path);
    $parts = $path === '' ? [] : explode('/', trim($path, '/'));
    $resource = $parts[0] ?? '';
    $id = id_from($parts, 1);
    $action = $parts[2] ?? '';
    $repo = app_repository();

    if ($method === 'GET') {
        if ($resource === 'planner' && ($parts[1] ?? '') === 'analyze' && id_from($parts, 2)) respond(app_planner()->analysis((int)$parts[2]));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'host-candidates') respond(app_planner()->hostCandidates((int)($_GET['site_id'] ?? 0)));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'route-suggestions' && id_from($parts, 2)) respond($repo->routeSuggestions((int)$parts[2]));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'replans') respond(app_replanner()->history((int)($_GET['address_space_id'] ?? 0)));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'rollback-preview' && id_from($parts, 2)) respond(app_replanner()->rollbackPreview((int)$parts[2]));
        $result = match ($resource) {
            'dashboard' => $repo->dashboard(),
            'sites' => $repo->sites(),
            'blocks' => $repo->blocks(isset($_GET['site_id']) ? (int)$_GET['site_id'] : null),
            'subnets' => $repo->subnets(isset($_GET['block_id']) ? (int)$_GET['block_id'] : null),
            'hosts' => $repo->hosts($_GET),
            'routers' => $repo->routers(),
            'static-routes' => $repo->routerRoutes((int)($_GET['router_id'] ?? 0)),
            'interfaces' => $repo->interfaces(isset($_GET['host_id']) ? (int)$_GET['host_id'] : null),
            'virtual-networks' => $repo->virtualNetworks(),
            'vpn-interfaces' => $repo->vpnInterfaces(isset($_GET['virtual_network_id'])?(int)$_GET['virtual_network_id']:null,isset($_GET['router_id'])?(int)$_GET['router_id']:null),
            'services' => $repo->services($_GET),
            'reservations' => $repo->reservations(isset($_GET['network_id']) ? (int)$_GET['network_id'] : (isset($_GET['subnet_id']) ? (int)$_GET['subnet_id'] : null)),
            'address-spaces' => app_planner()->spaces(),
            'networks' => app_planner()->networks(isset($_GET['address_space_id']) ? (int)$_GET['address_space_id'] : null),
            'relations' => $repo->relations(),
            'objects' => $repo->objectOptions(),
            'topology' => $repo->topology(isset($_GET['site_id']) && $_GET['site_id'] !== '' ? (int)$_GET['site_id'] : null),
            default => throw new InvalidArgumentException('Unbekannter API-Endpunkt.'),
        };
        respond($result);
    }

    if ($method === 'POST' || $method === 'PATCH') {
        $input = body();
        if ($resource === 'tools' && ($parts[1] ?? '') === 'ipv4') respond(app_ipv4_calculator()->calculate($input));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'replan-preview') respond(app_replanner()->preview($input));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'apply-replan') respond(app_replanner()->apply($input), 201);
        if ($resource === 'planner' && ($parts[1] ?? '') === 'site-move-preview') respond(app_replanner()->previewSiteMove($input));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'apply-site-move') respond(app_replanner()->applySiteMove($input), 201);
        if ($resource === 'planner' && ($parts[1] ?? '') === 'apply-rollback') respond(app_replanner()->applyRollback($input), 201);
        if ($resource === 'planner' && ($parts[1] ?? '') === 'network') respond(app_planner()->recommendNetwork($input));
        if ($resource === 'planner' && ($parts[1] ?? '') === 'apply-network') respond(app_planner()->applyNetwork($input), 201);
        if ($resource === 'planner' && ($parts[1] ?? '') === 'apply-host') respond(app_planner()->applyHost($input), 201);
        if ($resource === 'networks' && $id && $action === 'split') respond(app_planner()->applySplit($id, (int)($input['parts'] ?? 2)));
        if ($resource === 'networks' && $id && $action === 'expand') respond(app_planner()->applyExpansion($id));
        if ($resource === 'blocks' && $id && $action === 'plan') respond($repo->planSubnets($id, $input['requests'] ?? []));
        if ($resource === 'blocks' && $id && $action === 'apply-plan') respond($repo->applySubnetPlan($id, $input['requests'] ?? []), 201);
        if ($resource === 'topology' && $parts[1] === 'positions') { $repo->savePositions($input); respond(['saved'=>true]); }
        $result = match ($resource) {
            'sites' => $repo->saveSite($input, $id),
            'blocks' => $repo->saveBlock($input, $id),
            'subnets' => $repo->saveSubnet($input, $id),
            'hosts' => $repo->saveHost($input, $id),
            'routers' => $repo->saveRouter($input, $id),
            'static-routes' => $repo->saveStaticRoute($input, $id),
            'interfaces' => $repo->saveInterface($input, $id),
            'virtual-networks' => $repo->saveVirtualNetwork($input, $id),
            'vpn-interfaces' => $repo->saveVpnInterface($input, $id),
            'services' => $repo->saveService($input, $id),
            'reservations' => $repo->saveReservation($input, $id),
            'relations' => $repo->saveRelation($input, $id),
            'address-spaces' => app_planner()->saveSpace($input, $id),
            'networks' => $id ? app_planner()->saveNetwork($input, $id) : throw new InvalidArgumentException('Netz-ID fehlt.'),
            default => throw new InvalidArgumentException('Unbekannter API-Endpunkt.'),
        };
        respond($result, $method === 'POST' && !$id ? 201 : 200);
    }

    if ($method === 'DELETE' && $resource === 'topology' && ($parts[1] ?? '') === 'positions') {
        $repo->deletePositions(trim((string)($_GET['scope'] ?? 'global')) ?: 'global');
        respond(['deleted'=>true]);
    }
    if ($method === 'DELETE' && $id) {
        if ($resource === 'address-spaces') { app_planner()->deleteSpace($id); respond(['deleted'=>true]); }
        if ($resource === 'networks') { app_planner()->deleteNetwork($id); respond(['deleted'=>true]); }
        if ($resource === 'virtual-networks') { $repo->deleteVirtualNetwork($id); respond(['deleted'=>true]); }
        $repo->delete($resource, $id);
        respond(['deleted'=>true]);
    }
    error_response('Methode oder Endpunkt wird nicht unterstützt.', 405);
} catch (InvalidArgumentException $error) {
    error_response($error->getMessage(), 422);
} catch (DomainException $error) {
    error_response($error->getMessage(), 409);
} catch (PDOException $error) {
    $message = str_contains($error->getMessage(), 'UNIQUE constraint failed') ? 'Ein gleichnamiger oder identischer Datensatz existiert bereits.' : 'Datenbankoperation fehlgeschlagen.';
    error_response($message, 409);
} catch (Throwable $error) {
    error_log((string)$error);
    error_response('Interner Serverfehler.', 500);
}
