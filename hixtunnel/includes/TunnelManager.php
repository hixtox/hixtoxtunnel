<?php
class TunnelManager {
    protected $db;
    protected $ws;

    public function __construct($db) {
        $this->db = $db;
        $this->ws = new WebSocket();
    }

    public function getTunnels($userId) {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   COALESCE(SUM(tm.bytes_in), 0) as total_bytes_in,
                   COALESCE(SUM(tm.bytes_out), 0) as total_bytes_out
            FROM tunnels t
            LEFT JOIN tunnel_metrics tm ON t.id = tm.tunnel_id
            WHERE t.user_id = ?
            GROUP BY t.id
            ORDER BY t.created_at DESC
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTunnel($tunnelId, $userId) {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   COALESCE(SUM(tm.bytes_in), 0) as total_bytes_in,
                   COALESCE(SUM(tm.bytes_out), 0) as total_bytes_out
            FROM tunnels t
            LEFT JOIN tunnel_metrics tm ON t.id = tm.tunnel_id
            WHERE t.id = ? AND t.user_id = ?
            GROUP BY t.id
        ");
        
        $stmt->execute([$tunnelId, $userId]);
        $tunnel = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tunnel) {
            throw new Exception('Tunnel not found', 404);
        }
        
        return $tunnel;
    }

    public function createTunnel($data, $userId) {
        $stmt = $this->db->prepare("
            INSERT INTO tunnels (
                user_id, name, description, local_host, local_port,
                remote_port, protocol, auth_enabled, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $data['name'],
            $data['description'] ?? null,
            $data['local_host'],
            $data['local_port'],
            $data['remote_port'],
            $data['protocol'],
            $data['auth_enabled'] ?? false
        ]);
        
        $tunnelId = $this->db->lastInsertId();
        
        // Broadcast tunnel creation
        $this->ws->broadcast('user.' . $userId, [
            'type' => 'tunnel_created',
            'tunnel' => $this->getTunnel($tunnelId, $userId)
        ]);
        
        return $this->getTunnel($tunnelId, $userId);
    }

    public function updateTunnel($tunnelId, $data, $userId) {
        // Verify ownership
        $this->getTunnel($tunnelId, $userId);
        
        $stmt = $this->db->prepare("
            UPDATE tunnels
            SET name = ?,
                description = ?,
                local_host = ?,
                local_port = ?,
                remote_port = ?,
                protocol = ?,
                auth_enabled = ?,
                updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([
            $data['name'],
            $data['description'] ?? null,
            $data['local_host'],
            $data['local_port'],
            $data['remote_port'],
            $data['protocol'],
            $data['auth_enabled'] ?? false,
            $tunnelId,
            $userId
        ]);
        
        $tunnel = $this->getTunnel($tunnelId, $userId);
        
        // Broadcast tunnel update
        $this->ws->broadcast('tunnel.' . $tunnelId, [
            'type' => 'tunnel_updated',
            'tunnel' => $tunnel
        ]);
        
        return $tunnel;
    }

    public function deleteTunnel($tunnelId, $userId) {
        // Verify ownership
        $this->getTunnel($tunnelId, $userId);
        
        // Stop the tunnel if it's running
        $this->stopTunnel($tunnelId, $userId);
        
        // Delete tunnel data
        $stmt = $this->db->prepare("DELETE FROM tunnels WHERE id = ? AND user_id = ?");
        $stmt->execute([$tunnelId, $userId]);
        
        // Broadcast tunnel deletion
        $this->ws->broadcast('user.' . $userId, [
            'type' => 'tunnel_deleted',
            'tunnel_id' => $tunnelId
        ]);
        
        return ['success' => true];
    }

    public function startTunnel($tunnelId, $userId) {
        $tunnel = $this->getTunnel($tunnelId, $userId);
        
        // Update tunnel status
        $stmt = $this->db->prepare("
            UPDATE tunnels
            SET status = 'active',
                started_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$tunnelId, $userId]);
        
        // Start the actual tunnel process
        $this->startTunnelProcess($tunnel);
        
        // Broadcast tunnel start
        $this->ws->broadcast('tunnel.' . $tunnelId, [
            'type' => 'tunnel_started',
            'tunnel' => $this->getTunnel($tunnelId, $userId)
        ]);
        
        return ['success' => true];
    }

    public function stopTunnel($tunnelId, $userId) {
        $tunnel = $this->getTunnel($tunnelId, $userId);
        
        // Update tunnel status
        $stmt = $this->db->prepare("
            UPDATE tunnels
            SET status = 'inactive',
                stopped_at = NOW()
            WHERE id = ? AND user_id = ?
        ");
        
        $stmt->execute([$tunnelId, $userId]);
        
        // Stop the actual tunnel process
        $this->stopTunnelProcess($tunnel);
        
        // Broadcast tunnel stop
        $this->ws->broadcast('tunnel.' . $tunnelId, [
            'type' => 'tunnel_stopped',
            'tunnel' => $this->getTunnel($tunnelId, $userId)
        ]);
        
        return ['success' => true];
    }

    public function getTunnelMetrics($tunnelId, $userId, $period = '1h') {
        // Verify ownership
        $this->getTunnel($tunnelId, $userId);
        
        $interval = $this->getPeriodInterval($period);
        
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:00') as time,
                SUM(bytes_in) as bytes_in,
                SUM(bytes_out) as bytes_out,
                AVG(response_time) as avg_response_time,
                COUNT(CASE WHEN status_code >= 400 THEN 1 END) as errors,
                COUNT(*) as total_requests
            FROM tunnel_metrics
            WHERE tunnel_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            GROUP BY DATE_FORMAT(timestamp, '%Y-%m-%d %H:%i:00')
            ORDER BY time ASC
        ");
        
        $stmt->execute([$tunnelId, $interval]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllMetrics($userId, $period = '1h') {
        $interval = $this->getPeriodInterval($period);
        
        $stmt = $this->db->prepare("
            SELECT 
                DATE_FORMAT(tm.timestamp, '%Y-%m-%d %H:%i:00') as time,
                SUM(tm.bytes_in) as bytes_in,
                SUM(tm.bytes_out) as bytes_out,
                AVG(tm.response_time) as avg_response_time,
                COUNT(CASE WHEN tm.status_code >= 400 THEN 1 END) as errors,
                COUNT(*) as total_requests
            FROM tunnel_metrics tm
            JOIN tunnels t ON tm.tunnel_id = t.id
            WHERE t.user_id = ? AND tm.timestamp >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            GROUP BY DATE_FORMAT(tm.timestamp, '%Y-%m-%d %H:%i:00')
            ORDER BY time ASC
        ");
        
        $stmt->execute([$userId, $interval]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSystemMetrics() {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(DISTINCT t.id) as total_tunnels,
                COUNT(DISTINCT CASE WHEN t.status = 'active' THEN t.id END) as active_tunnels,
                COUNT(DISTINCT t.user_id) as total_users,
                SUM(tm.bytes_in + tm.bytes_out) as total_traffic,
                AVG(tm.response_time) as avg_response_time,
                COUNT(CASE WHEN tm.status_code >= 400 THEN 1 END) * 100.0 / COUNT(*) as error_rate
            FROM tunnels t
            LEFT JOIN tunnel_metrics tm ON t.id = tm.tunnel_id
        ");
        
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    protected function startTunnelProcess($tunnel) {
        // Implementation depends on your tunneling technology
        // This is a placeholder for the actual tunnel process start
    }

    protected function stopTunnelProcess($tunnel) {
        // Implementation depends on your tunneling technology
        // This is a placeholder for the actual tunnel process stop
    }

    protected function getPeriodInterval($period) {
        switch ($period) {
            case '1h':
                return 3600;
            case '24h':
                return 86400;
            case '7d':
                return 604800;
            case '30d':
                return 2592000;
            default:
                return 3600;
        }
    }

    public function logMetrics($tunnelId, $metrics) {
        $stmt = $this->db->prepare("
            INSERT INTO tunnel_metrics (
                tunnel_id, timestamp, bytes_in, bytes_out,
                response_time, status_code
            ) VALUES (?, NOW(), ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $tunnelId,
            $metrics['bytes_in'],
            $metrics['bytes_out'],
            $metrics['response_time'],
            $metrics['status_code']
        ]);
        
        // Broadcast metrics update
        $this->ws->broadcast('tunnel.' . $tunnelId, [
            'type' => 'metrics_update',
            'metrics' => $metrics
        ]);
    }
}
