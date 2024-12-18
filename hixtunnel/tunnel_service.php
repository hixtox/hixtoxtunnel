<?php
require_once __DIR__ . '/includes/config.php';

function log_message($message) {
    echo date('Y-m-d H:i:s') . " - $message\n";
}

function update_tunnel_status($tunnel_id, $status) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE tunnels SET status = ? WHERE id = ?");
    $stmt->execute([$status, $tunnel_id]);
}

function create_tunnel($tunnel) {
    $local_port = $tunnel['local_port'];
    $remote_port = $tunnel['remote_port'];
    
    try {
        // Create SSH tunnel
        $command = "ssh -N -R {$remote_port}:localhost:{$local_port} tunnel@localhost -o StrictHostKeyChecking=no";
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ], $pipes);
        
        if (is_resource($process)) {
            update_tunnel_status($tunnel['id'], 'active');
            log_message("Tunnel created: localhost:{$local_port} -> remote:{$remote_port}");
            return $process;
        }
    } catch (Exception $e) {
        log_message("Error creating tunnel: " . $e->getMessage());
    }
    
    return false;
}

// Main service loop
log_message("Starting HixTunnel service...");

$active_tunnels = [];

while (true) {
    try {
        // Get all tunnels that should be active
        $stmt = $pdo->prepare("SELECT * FROM tunnels WHERE status = 'active'");
        $stmt->execute();
        $tunnels = $stmt->fetchAll();
        
        // Create new tunnels
        foreach ($tunnels as $tunnel) {
            $tunnel_id = $tunnel['id'];
            if (!isset($active_tunnels[$tunnel_id])) {
                $process = create_tunnel($tunnel);
                if ($process) {
                    $active_tunnels[$tunnel_id] = $process;
                }
            }
        }
        
        // Check and clean up inactive tunnels
        foreach ($active_tunnels as $tunnel_id => $process) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                proc_close($process);
                unset($active_tunnels[$tunnel_id]);
                update_tunnel_status($tunnel_id, 'inactive');
                log_message("Tunnel $tunnel_id stopped");
            }
        }
        
        // Sleep for a bit
        sleep(5);
        
    } catch (Exception $e) {
        log_message("Service error: " . $e->getMessage());
        sleep(10); // Wait longer on error
    }
}
