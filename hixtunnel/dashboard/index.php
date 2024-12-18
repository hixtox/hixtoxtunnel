<?php
require_once '../includes/header.php';

// Ensure user is logged in
if (!isLoggedIn()) {
    redirect('/login.php');
}

// Get user's tunnels
$stmt = $pdo->prepare("SELECT * FROM tunnels WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$tunnels = $stmt->fetchAll();

// Get user's API token
$stmt = $pdo->prepare("SELECT token FROM api_tokens WHERE user_id = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$token = $stmt->fetchColumn();

// Generate new token if none exists or expired
if (!$token) {
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare("INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))");
    $stmt->execute([$_SESSION['user_id'], $token]);
}

// Get statistics
$stmt = $pdo->prepare("SELECT COUNT(*) FROM tunnels WHERE user_id = ? AND status = 'active'");
$stmt->execute([$_SESSION['user_id']]);
$activeTunnels = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COALESCE(SUM(traffic), 0) FROM tunnels WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$totalTraffic = $stmt->fetchColumn();
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <nav class="col-md-3 col-lg-2 d-md-block bg-white sidebar">
            <div class="position-sticky pt-3">
                <div class="px-3 mb-4 text-center">
                    <img src="/assets/logo.svg" alt="HixTunnel" class="mb-3" height="48">
                    <h5 class="mb-0">HixTunnel</h5>
                </div>
                
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link active" href="/dashboard">
                            <i class="bi bi-speedometer2 me-2"></i>
                            Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/profile.php">
                            <i class="bi bi-person me-2"></i>
                            Profile
                        </a>
                    </li>
                    <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/admin">
                            <i class="bi bi-shield-lock me-2"></i>
                            Admin Panel
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>
                            Logout
                        </a>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
            <!-- Stats Overview -->
            <div class="row g-4 mb-4">
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon bg-primary">
                                    <i class="bi bi-hdd-network text-white"></i>
                                </div>
                                <div class="ms-3">
                                    <h6 class="mb-0">Active Tunnels</h6>
                                    <h3 class="mb-0"><?php echo $activeTunnels; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-sm-6 col-xl-3">
                    <div class="card">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <div class="stats-icon bg-success">
                                    <i class="bi bi-arrow-left-right text-white"></i>
                                </div>
                                <div class="ms-3">
                                    <h6 class="mb-0">Total Traffic</h6>
                                    <h3 class="mb-0"><?php echo formatBytes($totalTraffic); ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tunnels Section -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-hdd-network me-2"></i>
                        Your Tunnels
                    </h5>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newTunnelModal">
                        <i class="bi bi-plus-lg me-2"></i>
                        New Tunnel
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Local Port</th>
                                    <th>Remote Port</th>
                                    <th>Status</th>
                                    <th>Traffic</th>
                                    <th>Created</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tunnels as $tunnel): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tunnel['name']); ?></td>
                                    <td><?php echo $tunnel['local_port']; ?></td>
                                    <td><?php echo $tunnel['remote_port']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $tunnel['status'] === 'active' ? 'success' : 'danger'; ?>">
                                            <?php echo ucfirst($tunnel['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatBytes($tunnel['traffic']); ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($tunnel['created_at'])); ?></td>
                                    <td>
                                        <div class="btn-group">
                                            <?php if ($tunnel['status'] === 'inactive'): ?>
                                            <button class="btn btn-sm btn-success" onclick="startTunnel(<?php echo $tunnel['id']; ?>)">
                                                <i class="bi bi-play-fill"></i>
                                            </button>
                                            <?php else: ?>
                                            <button class="btn btn-sm btn-danger" onclick="stopTunnel(<?php echo $tunnel['id']; ?>)">
                                                <i class="bi bi-stop-fill"></i>
                                            </button>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-danger" onclick="deleteTunnel(<?php echo $tunnel['id']; ?>)">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($tunnels)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="text-muted">
                                            <i class="bi bi-inbox fs-2 mb-2"></i>
                                            <p class="mb-0">No tunnels yet. Create your first tunnel to get started!</p>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- API Section -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-code-slash me-2"></i>
                        API Access
                    </h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        Use this API token to manage your tunnels programmatically.
                    </div>
                    <div class="input-group mb-3">
                        <input type="text" class="form-control" value="<?php echo substr($token, 0, 32) . '...'; ?>" readonly>
                        <button class="btn btn-outline-primary" onclick="copyToken()">
                            <i class="bi bi-clipboard"></i>
                            Copy
                        </button>
                    </div>
                    <h6>Example Usage:</h6>
                    <pre class="bg-light p-3 rounded"><code>curl -X POST https://<?php echo $_SERVER['HTTP_HOST']; ?>/api/tunnels \
    -H "Authorization: Bearer <?php echo substr($token, 0, 8); ?>..." \
    -H "Content-Type: application/json" \
    -d '{"name": "my-tunnel", "local_port": 8080}'</code></pre>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- New Tunnel Modal -->
<div class="modal fade" id="newTunnelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Tunnel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="newTunnelForm" onsubmit="createTunnel(event)">
                    <div class="mb-3">
                        <label class="form-label">Tunnel Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Local Port</label>
                        <input type="number" class="form-control" name="local_port" min="1" max="65535" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remote Port (Optional)</label>
                        <input type="number" class="form-control" name="remote_port" min="1" max="65535">
                        <small class="text-muted">Leave empty for automatic port assignment</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Create Tunnel</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Tunnel management functions
async function createTunnel(event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);
    
    try {
        const response = await fetch('/api/tunnels', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(Object.fromEntries(data))
        });
        
        if (!response.ok) throw new Error('Failed to create tunnel');
        
        location.reload();
    } catch (error) {
        alert('Error creating tunnel: ' + error.message);
    }
}

async function startTunnel(id) {
    try {
        const response = await fetch(`/api/tunnels/${id}/start`, {
            method: 'POST'
        });
        
        if (!response.ok) throw new Error('Failed to start tunnel');
        
        location.reload();
    } catch (error) {
        alert('Error starting tunnel: ' + error.message);
    }
}

async function stopTunnel(id) {
    try {
        const response = await fetch(`/api/tunnels/${id}/stop`, {
            method: 'POST'
        });
        
        if (!response.ok) throw new Error('Failed to stop tunnel');
        
        location.reload();
    } catch (error) {
        alert('Error stopping tunnel: ' + error.message);
    }
}

async function deleteTunnel(id) {
    if (!confirm('Are you sure you want to delete this tunnel?')) return;
    
    try {
        const response = await fetch(`/api/tunnels/${id}`, {
            method: 'DELETE'
        });
        
        if (!response.ok) throw new Error('Failed to delete tunnel');
        
        location.reload();
    } catch (error) {
        alert('Error deleting tunnel: ' + error.message);
    }
}

function copyToken() {
    const token = '<?php echo $token; ?>';
    navigator.clipboard.writeText(token)
        .then(() => alert('Token copied to clipboard!'))
        .catch(() => alert('Failed to copy token'));
}
</script>

<?php require_once '../includes/footer.php'; ?>