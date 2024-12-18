<?php

/**
 * Check if the current user is an admin
 */
function isAdmin() {
    global $pdo;
    if (!isLoggedIn()) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Generate a random string
 */
function generateRandomString($length = 32) {
    return bin2hex(random_bytes($length / 2));
}

/**
 * Format bytes to human readable format
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= (1 << (10 * $pow));
    
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get time ago string
 */
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $now = time();
    $diff = $now - $timestamp;
    
    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}

/**
 * Validate email address
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Check if a port is valid
 */
function isValidPort($port) {
    return is_numeric($port) && $port >= 1 && $port <= 65535;
}

/**
 * Check if a tunnel is owned by the current user
 */
function isTunnelOwner($tunnel_id) {
    global $pdo;
    if (!isLoggedIn()) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tunnels WHERE id = ? AND user_id = ?");
    $stmt->execute([$tunnel_id, $_SESSION['user_id']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Get user by ID
 */
function getUserById($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Get tunnel by ID
 */
function getTunnelById($tunnel_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM tunnels WHERE id = ?");
    $stmt->execute([$tunnel_id]);
    return $stmt->fetch();
}

/**
 * Create CSRF token
 */
function createCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Format bytes to human readable format
 */
function formatBytesDashboard($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Check if current user is admin
 */
function isAdminDashboard() {
    global $pdo;
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Get user by ID
 */
function getUserByIdDashboard($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Get tunnel by ID
 */
function getTunnelByIdDashboard($id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT * FROM tunnels WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * Log an action
 */
function logActionDashboard($action, $description = null, $tunnel_id = null) {
    global $pdo;
    
    $stmt = $pdo->prepare("INSERT INTO logs (user_id, tunnel_id, action, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $tunnel_id,
        $action,
        $description
    ]);
}

/**
 * Generate a random port number
 */
function generateRandomPortDashboard() {
    // Start with a range of ports that are typically available
    $min = 10000;
    $max = 65535;
    
    global $pdo;
    
    // Keep trying until we find an unused port
    do {
        $port = rand($min, $max);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tunnels WHERE remote_port = ?");
        $stmt->execute([$port]);
    } while ($stmt->fetchColumn() > 0);
    
    return $port;
}

/**
 * Validate tunnel configuration
 */
function validateTunnelConfigDashboard($local_port, $remote_port = null) {
    $errors = [];
    
    // Validate local port
    if ($local_port < 1 || $local_port > 65535) {
        $errors[] = "Local port must be between 1 and 65535";
    }
    
    // Validate remote port if specified
    if ($remote_port !== null) {
        if ($remote_port < 1 || $remote_port > 65535) {
            $errors[] = "Remote port must be between 1 and 65535";
        }
        
        // Check if remote port is already in use
        global $pdo;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tunnels WHERE remote_port = ?");
        $stmt->execute([$remote_port]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Remote port $remote_port is already in use";
        }
    }
    
    return $errors;
}

/**
 * Get user's active tunnels count
 */
function getActiveTunnelsCountDashboard($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tunnels WHERE user_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

/**
 * Get user's total traffic
 */
function getUserTotalTrafficDashboard($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(traffic), 0) FROM tunnels WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

/**
 * Update tunnel traffic
 */
function updateTunnelTrafficDashboard($tunnel_id, $bytes) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE tunnels SET traffic = traffic + ? WHERE id = ?");
    $stmt->execute([$bytes, $tunnel_id]);
}

/**
 * Update tunnel status
 */
function updateTunnelStatusDashboard($tunnel_id, $status) {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE tunnels SET status = ? WHERE id = ?");
    $stmt->execute([$status, $tunnel_id]);
    
    // Log the status change
    logActionDashboard(
        $status === 'active' ? 'tunnel_started' : 'tunnel_stopped',
        "Tunnel status changed to $status",
        $tunnel_id
    );
}

/**
 * Delete tunnel
 */
function deleteTunnelDashboard($tunnel_id) {
    global $pdo;
    
    // Get tunnel info for logging
    $tunnel = getTunnelByIdDashboard($tunnel_id);
    if (!$tunnel) return false;
    
    // Delete the tunnel
    $stmt = $pdo->prepare("DELETE FROM tunnels WHERE id = ?");
    $stmt->execute([$tunnel_id]);
    
    // Log the deletion
    logActionDashboard(
        'tunnel_deleted',
        "Deleted tunnel '{$tunnel['name']}'",
        $tunnel_id
    );
    
    return true;
}

/**
 * Create new tunnel
 */
function createTunnelDashboard($name, $local_port, $remote_port = null) {
    global $pdo;
    
    // Validate ports
    $errors = validateTunnelConfigDashboard($local_port, $remote_port);
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    // Generate random remote port if not specified
    if ($remote_port === null) {
        $remote_port = generateRandomPortDashboard();
    }
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO tunnels (user_id, name, local_port, remote_port, status)
            VALUES (?, ?, ?, ?, 'inactive')
        ");
        
        $stmt->execute([
            $_SESSION['user_id'],
            $name,
            $local_port,
            $remote_port
        ]);
        
        $tunnel_id = $pdo->lastInsertId();
        
        // Log the creation
        logActionDashboard(
            'tunnel_created',
            "Created new tunnel '$name'",
            $tunnel_id
        );
        
        return [
            'success' => true,
            'tunnel_id' => $tunnel_id,
            'remote_port' => $remote_port
        ];
    } catch (PDOException $e) {
        return [
            'success' => false,
            'errors' => ['Failed to create tunnel: ' . $e->getMessage()]
        ];
    }
}
