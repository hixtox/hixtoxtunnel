<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/TunnelManager.php';
require_once __DIR__ . '/../includes/SecurityManager.php';
require_once __DIR__ . '/../includes/RateLimit.php';

// Verify admin access
$auth = new Auth($db);
if (!$auth->isAdmin($userId)) {
    header('Location: /login');
    exit;
}

$tunnelManager = new TunnelManager($db);
$securityManager = new SecurityManager($db);
$rateLimit = new RateLimit($db);

// Get system metrics
$metrics = $tunnelManager->getSystemMetrics();

// Get recent security events
$securityEvents = $securityManager->getSecurityEvents(['limit' => 10]);

// Get rate limit violations
$rateViolations = $rateLimit->getViolations(['limit' => 10]);

// Get active tunnels
$activeTunnels = $tunnelManager->getActiveTunnels();
?>

<div class="admin-dashboard">
    <!-- System Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-header">
                <i class="fas fa-users"></i>
                <h3>Total Users</h3>
            </div>
            <div class="stat-value"><?= number_format($metrics['total_users']) ?></div>
            <div class="stat-footer">
                <span class="stat-label">Active Today:</span>
                <span class="stat-secondary"><?= number_format($metrics['active_users']) ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <i class="fas fa-network-wired"></i>
                <h3>Active Tunnels</h3>
            </div>
            <div class="stat-value"><?= number_format($metrics['active_tunnels']) ?></div>
            <div class="stat-footer">
                <span class="stat-label">Total:</span>
                <span class="stat-secondary"><?= number_format($metrics['total_tunnels']) ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <i class="fas fa-exchange-alt"></i>
                <h3>Total Traffic</h3>
            </div>
            <div class="stat-value"><?= formatBytes($metrics['total_traffic']) ?></div>
            <div class="stat-footer">
                <span class="stat-label">Last 24h:</span>
                <span class="stat-secondary"><?= formatBytes($metrics['daily_traffic']) ?></span>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-header">
                <i class="fas fa-shield-alt"></i>
                <h3>Security Events</h3>
            </div>
            <div class="stat-value"><?= number_format($metrics['security_events']) ?></div>
            <div class="stat-footer">
                <span class="stat-label">Blocked IPs:</span>
                <span class="stat-secondary"><?= number_format($metrics['blocked_ips']) ?></span>
            </div>
        </div>
    </div>

    <!-- Active Tunnels -->
    <div class="card">
        <div class="card-header">
            <h2>Active Tunnels</h2>
            <div class="card-actions">
                <button class="btn btn-sm btn-outline" onclick="refreshTunnels()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Tunnel Name</th>
                            <th>Local</th>
                            <th>Remote</th>
                            <th>Traffic In</th>
                            <th>Traffic Out</th>
                            <th>Connected</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeTunnels as $tunnel): ?>
                        <tr>
                            <td><?= htmlspecialchars($tunnel['user_email']) ?></td>
                            <td><?= htmlspecialchars($tunnel['name']) ?></td>
                            <td><?= $tunnel['local_host'] ?>:<?= $tunnel['local_port'] ?></td>
                            <td><?= $tunnel['remote_port'] ?></td>
                            <td><?= formatBytes($tunnel['bytes_in']) ?></td>
                            <td><?= formatBytes($tunnel['bytes_out']) ?></td>
                            <td><?= formatDuration(time() - strtotime($tunnel['started_at'])) ?></td>
                            <td>
                                <button class="btn btn-sm btn-danger" onclick="stopTunnel(<?= $tunnel['id'] ?>)">
                                    Stop
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid-2">
        <!-- Security Events -->
        <div class="card">
            <div class="card-header">
                <h2>Recent Security Events</h2>
                <div class="card-actions">
                    <a href="/admin/security" class="btn btn-sm btn-outline">View All</a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>IP</th>
                                <th>Event</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($securityEvents as $event): ?>
                            <tr>
                                <td><?= formatTime($event['created_at']) ?></td>
                                <td>
                                    <?= htmlspecialchars($event['ip_address']) ?>
                                    <?php if ($event['is_blocked']): ?>
                                    <span class="badge badge-danger">Blocked</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($event['event_type']) ?></td>
                                <td><?= htmlspecialchars($event['details']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Rate Limit Violations -->
        <div class="card">
            <div class="card-header">
                <h2>Rate Limit Violations</h2>
                <div class="card-actions">
                    <a href="/admin/rate-limits" class="btn btn-sm btn-outline">View All</a>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>IP</th>
                                <th>Type</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rateViolations as $violation): ?>
                            <tr>
                                <td><?= formatTime($violation['created_at']) ?></td>
                                <td><?= htmlspecialchars($violation['ip_address']) ?></td>
                                <td><?= htmlspecialchars($violation['limit_type']) ?></td>
                                <td><?= formatValue($violation['value'], $violation['limit_type']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function refreshTunnels() {
    location.reload();
}

async function stopTunnel(tunnelId) {
    if (!confirm('Are you sure you want to stop this tunnel?')) {
        return;
    }
    
    try {
        await fetch(`/api/admin/tunnels/${tunnelId}/stop`, {
            method: 'POST',
            headers: {
                'Authorization': `Bearer ${localStorage.getItem('token')}`
            }
        });
        
        location.reload();
    } catch (error) {
        alert('Failed to stop tunnel: ' + error.message);
    }
}

// Helper functions
function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function formatDuration(seconds) {
    if (seconds < 60) return seconds + 's';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
    if (seconds < 86400) return Math.floor(seconds / 3600) + 'h';
    return Math.floor(seconds / 86400) + 'd';
}

function formatTime(time) {
    return new Date(time).toLocaleString();
}

function formatValue(value, type) {
    if (type.startsWith('d')) {
        return formatBytes(value);
    }
    return value;
}
</script>
