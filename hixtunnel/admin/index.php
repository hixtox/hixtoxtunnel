<?php
require_once '../includes/header.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('/login.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_user') {
        $user_id = $_POST['user_id'] ?? '';
        if (!empty($user_id) && $user_id != $_SESSION['user_id']) {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            try {
                $stmt->execute([$user_id]);
                $success = 'User deleted successfully';
            } catch (PDOException $e) {
                $error = 'Failed to delete user';
            }
        }
    } elseif ($action === 'toggle_admin') {
        $user_id = $_POST['user_id'] ?? '';
        $is_admin = $_POST['is_admin'] ?? 0;
        if (!empty($user_id) && $user_id != $_SESSION['user_id']) {
            $stmt = $pdo->prepare("UPDATE users SET is_admin = ? WHERE id = ?");
            try {
                $stmt->execute([$is_admin ? 0 : 1, $user_id]);
                $success = 'User role updated successfully';
            } catch (PDOException $e) {
                $error = 'Failed to update user role';
            }
        }
    } elseif ($action === 'delete_tunnel') {
        $tunnel_id = $_POST['tunnel_id'] ?? '';
        if (!empty($tunnel_id)) {
            $stmt = $pdo->prepare("DELETE FROM tunnels WHERE id = ?");
            try {
                $stmt->execute([$tunnel_id]);
                $success = 'Tunnel deleted successfully';
            } catch (PDOException $e) {
                $error = 'Failed to delete tunnel';
            }
        }
    }
}

// Get system stats
$stats = [
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'active_users' => $pdo->query("SELECT COUNT(DISTINCT user_id) FROM tunnels WHERE status = 'active'")->fetchColumn(),
    'total_tunnels' => $pdo->query("SELECT COUNT(*) FROM tunnels")->fetchColumn(),
    'active_tunnels' => $pdo->query("SELECT COUNT(*) FROM tunnels WHERE status = 'active'")->fetchColumn()
];

// Get users list
$users = $pdo->query("
    SELECT u.*, 
           COUNT(t.id) as tunnel_count,
           SUM(CASE WHEN t.status = 'active' THEN 1 ELSE 0 END) as active_tunnels
    FROM users u
    LEFT JOIN tunnels t ON u.id = t.user_id
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetchAll();

// Get active tunnels
$tunnels = $pdo->query("
    SELECT t.*, u.username 
    FROM tunnels t
    JOIN users u ON t.user_id = u.id
    WHERE t.status = 'active'
    ORDER BY t.created_at DESC
")->fetchAll();
?>

<div class="container-fluid py-4">
    <?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success">
        <i class="bi bi-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
    </div>
    <?php endif; ?>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon">
                        <i class="bi bi-people"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo number_format($stats['total_users']); ?></h3>
                        <p class="stat-label">Total Users</p>
                    </div>
                    <div class="stat-chart">
                        <div class="stat-trend up">
                            <i class="bi bi-arrow-up"></i>
                            <span><?php echo number_format($stats['active_users']); ?> active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon">
                        <i class="bi bi-hdd-network"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value"><?php echo number_format($stats['total_tunnels']); ?></h3>
                        <p class="stat-label">Total Tunnels</p>
                    </div>
                    <div class="stat-chart">
                        <div class="stat-trend up">
                            <i class="bi bi-arrow-up"></i>
                            <span><?php echo number_format($stats['active_tunnels']); ?> active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon">
                        <i class="bi bi-lightning"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value">
                            <?php 
                            $uptime = exec("uptime -p");
                            echo $uptime;
                            ?>
                        </h3>
                        <p class="stat-label">System Uptime</p>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-sm-6 col-xl-3">
            <div class="card stat-card">
                <div class="card-body">
                    <div class="stat-icon">
                        <i class="bi bi-cpu"></i>
                    </div>
                    <div class="stat-content">
                        <h3 class="stat-value">
                            <?php 
                            $load = sys_getloadavg();
                            echo number_format($load[0], 2);
                            ?>
                        </h3>
                        <p class="stat-label">System Load</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Users Management -->
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-people"></i>
                        Users Management
                    </h5>
                    <div class="card-actions">
                        <div class="input-group">
                            <span class="input-icon">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   placeholder="Search users..."
                                   onkeyup="filterTable(this.value, 'usersTable')">
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Email</th>
                                    <th>Tunnels</th>
                                    <th>Joined</th>
                                    <th>Role</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="avatar">
                                                <i class="bi bi-person"></i>
                                            </div>
                                            <?php echo htmlspecialchars($user['username']); ?>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo $user['active_tunnels']; ?> / <?php echo $user['tunnel_count']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php if ($user['is_admin']): ?>
                                            <span class="badge bg-danger">Admin</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">User</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <div class="btn-group">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="toggle_admin">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <input type="hidden" name="is_admin" value="<?php echo $user['is_admin']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <?php echo $user['is_admin'] ? 'Remove Admin' : 'Make Admin'; ?>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this user?')">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Active Tunnels -->
        <div class="col-xl-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-hdd-network"></i>
                        Active Tunnels
                    </h5>
                    <div class="card-actions">
                        <div class="input-group">
                            <span class="input-icon">
                                <i class="bi bi-search"></i>
                            </span>
                            <input type="text" 
                                   class="form-control" 
                                   placeholder="Search tunnels..."
                                   onkeyup="filterTable(this.value, 'tunnelsTable')">
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover" id="tunnelsTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Local Port</th>
                                    <th>Remote Port</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tunnels as $tunnel): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tunnel['username']); ?></td>
                                    <td><?php echo $tunnel['local_port']; ?></td>
                                    <td><?php echo $tunnel['remote_port']; ?></td>
                                    <td>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this tunnel?')">
                                            <input type="hidden" name="action" value="delete_tunnel">
                                            <input type="hidden" name="tunnel_id" value="<?php echo $tunnel['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function filterTable(query, tableId) {
    query = query.toLowerCase();
    const table = document.getElementById(tableId);
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query) ? '' : 'none';
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>