<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/TunnelManager.php';
require_once __DIR__ . '/../includes/WebSocket.php';

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Initialize components
$db = new Database();
$auth = new Auth($db);
$tunnelManager = new TunnelManager($db);
$ws = new WebSocket();

// Parse the request
$request = parse_url($_SERVER['REQUEST_URI']);
$path = trim($request['path'], '/');
$pathParts = explode('/', $path);
$endpoint = $pathParts[1] ?? '';
$resourceId = $pathParts[2] ?? null;
$action = $pathParts[3] ?? null;

// Get request data
$data = json_decode(file_get_contents('php://input'), true) ?? [];
$method = $_SERVER['REQUEST_METHOD'];

try {
    // Authentication middleware
    $publicEndpoints = ['auth/login', 'auth/register', 'auth/forgot-password', 'auth/reset-password'];
    if (!in_array("$endpoint/$resourceId", $publicEndpoints)) {
        $token = getBearerToken();
        if (!$token || !$auth->validateToken($token)) {
            throw new Exception('Unauthorized', 401);
        }
        $userId = $auth->getUserIdFromToken($token);
    }

    // Route the request
    switch ($endpoint) {
        case 'auth':
            handleAuthEndpoint($resourceId, $method, $data, $auth);
            break;
            
        case 'tunnels':
            handleTunnelEndpoint($resourceId, $action, $method, $data, $tunnelManager, $userId);
            break;
            
        case 'metrics':
            handleMetricsEndpoint($resourceId, $method, $data, $tunnelManager, $userId);
            break;
            
        case 'api-tokens':
            handleApiTokenEndpoint($resourceId, $method, $data, $auth, $userId);
            break;
            
        case 'settings':
            handleSettingsEndpoint($resourceId, $method, $data, $auth, $userId);
            break;
            
        case 'admin':
            if (!$auth->isAdmin($userId)) {
                throw new Exception('Forbidden', 403);
            }
            handleAdminEndpoint($resourceId, $action, $method, $data, $auth, $tunnelManager);
            break;
            
        default:
            throw new Exception('Not Found', 404);
    }
} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}

function handleAuthEndpoint($action, $method, $data, $auth) {
    switch ($action) {
        case 'login':
            if ($method !== 'POST') throw new Exception('Method Not Allowed', 405);
            $result = $auth->login($data['email'], $data['password']);
            echo json_encode($result);
            break;
            
        case 'register':
            if ($method !== 'POST') throw new Exception('Method Not Allowed', 405);
            $result = $auth->register($data);
            echo json_encode($result);
            break;
            
        case 'forgot-password':
            if ($method !== 'POST') throw new Exception('Method Not Allowed', 405);
            $result = $auth->forgotPassword($data['email']);
            echo json_encode($result);
            break;
            
        case 'reset-password':
            if ($method !== 'POST') throw new Exception('Method Not Allowed', 405);
            $result = $auth->resetPassword($data['token'], $data['password']);
            echo json_encode($result);
            break;
            
        default:
            throw new Exception('Not Found', 404);
    }
}

function handleTunnelEndpoint($tunnelId, $action, $method, $data, $tunnelManager, $userId) {
    switch ($method) {
        case 'GET':
            if ($tunnelId) {
                $tunnel = $tunnelManager->getTunnel($tunnelId, $userId);
                echo json_encode($tunnel);
            } else {
                $tunnels = $tunnelManager->getTunnels($userId);
                echo json_encode($tunnels);
            }
            break;
            
        case 'POST':
            if ($action === 'start') {
                $result = $tunnelManager->startTunnel($tunnelId, $userId);
                echo json_encode($result);
            } elseif ($action === 'stop') {
                $result = $tunnelManager->stopTunnel($tunnelId, $userId);
                echo json_encode($result);
            } else {
                $tunnel = $tunnelManager->createTunnel($data, $userId);
                echo json_encode($tunnel);
            }
            break;
            
        case 'PUT':
            $tunnel = $tunnelManager->updateTunnel($tunnelId, $data, $userId);
            echo json_encode($tunnel);
            break;
            
        case 'DELETE':
            $result = $tunnelManager->deleteTunnel($tunnelId, $userId);
            echo json_encode($result);
            break;
            
        default:
            throw new Exception('Method Not Allowed', 405);
    }
}

function handleMetricsEndpoint($tunnelId, $method, $data, $tunnelManager, $userId) {
    if ($method !== 'GET') throw new Exception('Method Not Allowed', 405);
    
    $period = $_GET['period'] ?? '1h';
    
    if ($tunnelId) {
        $metrics = $tunnelManager->getTunnelMetrics($tunnelId, $userId, $period);
    } else {
        $metrics = $tunnelManager->getAllMetrics($userId, $period);
    }
    
    echo json_encode($metrics);
}

function handleApiTokenEndpoint($tokenId, $method, $data, $auth, $userId) {
    switch ($method) {
        case 'GET':
            if ($tokenId) {
                $token = $auth->getApiToken($tokenId, $userId);
                echo json_encode($token);
            } else {
                $tokens = $auth->getApiTokens($userId);
                echo json_encode($tokens);
            }
            break;
            
        case 'POST':
            $token = $auth->createApiToken($data['name'], $data['permissions'], $userId);
            echo json_encode($token);
            break;
            
        case 'DELETE':
            $result = $auth->deleteApiToken($tokenId, $userId);
            echo json_encode($result);
            break;
            
        default:
            throw new Exception('Method Not Allowed', 405);
    }
}

function handleSettingsEndpoint($section, $method, $data, $auth, $userId) {
    if ($method !== 'GET' && $method !== 'PUT') {
        throw new Exception('Method Not Allowed', 405);
    }
    
    switch ($section) {
        case 'profile':
            if ($method === 'GET') {
                $profile = $auth->getUserProfile($userId);
                echo json_encode($profile);
            } else {
                $result = $auth->updateUserProfile($userId, $data);
                echo json_encode($result);
            }
            break;
            
        case 'password':
            if ($method !== 'PUT') throw new Exception('Method Not Allowed', 405);
            $result = $auth->changePassword($userId, $data['current_password'], $data['new_password']);
            echo json_encode($result);
            break;
            
        case 'notifications':
            if ($method === 'GET') {
                $settings = $auth->getNotificationSettings($userId);
                echo json_encode($settings);
            } else {
                $result = $auth->updateNotificationSettings($userId, $data);
                echo json_encode($result);
            }
            break;
            
        default:
            throw new Exception('Not Found', 404);
    }
}

function handleAdminEndpoint($resource, $action, $method, $data, $auth, $tunnelManager) {
    switch ($resource) {
        case 'users':
            if ($method === 'GET') {
                if ($action) {
                    $user = $auth->getUser($action);
                    echo json_encode($user);
                } else {
                    $users = $auth->getUsers();
                    echo json_encode($users);
                }
            } elseif ($method === 'POST') {
                $user = $auth->createUser($data);
                echo json_encode($user);
            } elseif ($method === 'PUT') {
                $user = $auth->updateUser($action, $data);
                echo json_encode($user);
            } elseif ($method === 'DELETE') {
                $result = $auth->deleteUser($action);
                echo json_encode($result);
            }
            break;
            
        case 'metrics':
            if ($method !== 'GET') throw new Exception('Method Not Allowed', 405);
            $metrics = $tunnelManager->getSystemMetrics();
            echo json_encode($metrics);
            break;
            
        case 'audit-logs':
            if ($method !== 'GET') throw new Exception('Method Not Allowed', 405);
            $logs = $auth->getAuditLogs();
            echo json_encode($logs);
            break;
            
        default:
            throw new Exception('Not Found', 404);
    }
}

function getBearerToken() {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }
    }
    return null;
}
