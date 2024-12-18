<?php
require_once 'includes/header.php';

if (!isLoggedIn()) {
    redirect('/login.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $current_password = $_POST['current_password'] ?? '';
        
        if (empty($username) || empty($email)) {
            $error = 'Username and email are required';
        } else {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!password_verify($current_password, $user['password'])) {
                $error = 'Current password is incorrect';
            } else {
                // Update profile
                $stmt = $pdo->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                try {
                    $stmt->execute([$username, $email, $_SESSION['user_id']]);
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    $success = 'Profile updated successfully';
                } catch (PDOException $e) {
                    $error = 'Failed to update profile. Please try again.';
                }
            }
        }
    } elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error = 'All password fields are required';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match';
        } elseif (strlen($new_password) < 8) {
            $error = 'Password must be at least 8 characters long';
        } else {
            // Verify current password
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            
            if (!password_verify($current_password, $user['password'])) {
                $error = 'Current password is incorrect';
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                try {
                    $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                    $success = 'Password changed successfully';
                } catch (PDOException $e) {
                    $error = 'Failed to change password. Please try again.';
                }
            }
        }
    }
}

// Get user data
$stmt = $pdo->prepare("SELECT username, email, created_at FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
?>

<div class="container py-5">
    <div class="row">
        <!-- Profile Sidebar -->
        <div class="col-lg-3">
            <div class="card profile-card">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <i class="bi bi-person-circle"></i>
                        <div class="profile-status online"></div>
                    </div>
                    <h4 class="profile-name"><?php echo htmlspecialchars($user['username']); ?></h4>
                    <p class="profile-email"><?php echo htmlspecialchars($user['email']); ?></p>
                </div>
                <div class="profile-info">
                    <div class="info-item">
                        <i class="bi bi-calendar3"></i>
                        <div>
                            <label>Member Since</label>
                            <span><?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                        </div>
                    </div>
                    <div class="info-item">
                        <i class="bi bi-hdd-network"></i>
                        <div>
                            <label>Active Tunnels</label>
                            <span id="activeTunnels">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Content -->
        <div class="col-lg-9">
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

            <!-- Profile Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-person-gear"></i>
                        Profile Settings
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="profile-form">
                        <input type="hidden" name="action" value="update_profile">
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Username</label>
                                <div class="input-group">
                                    <span class="input-icon">
                                        <i class="bi bi-person"></i>
                                    </span>
                                    <input type="text" 
                                           name="username" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($user['username']); ?>" 
                                           required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address</label>
                                <div class="input-group">
                                    <span class="input-icon">
                                        <i class="bi bi-envelope"></i>
                                    </span>
                                    <input type="email" 
                                           name="email" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($user['email']); ?>" 
                                           required>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input type="password" 
                                       name="current_password" 
                                       class="form-control" 
                                       placeholder="Enter current password to save changes" 
                                       required>
                                <button type="button" class="btn btn-icon" onclick="togglePassword(this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i>
                            Save Changes
                        </button>
                    </form>
                </div>
            </div>

            <!-- Change Password -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="bi bi-shield-lock"></i>
                        Change Password
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="profile-form">
                        <input type="hidden" name="action" value="change_password">
                        
                        <div class="mb-3">
                            <label class="form-label">Current Password</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input type="password" 
                                       name="current_password" 
                                       class="form-control" 
                                       required>
                                <button type="button" class="btn btn-icon" onclick="togglePassword(this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">New Password</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="bi bi-lock"></i>
                                </span>
                                <input type="password" 
                                       name="new_password" 
                                       class="form-control" 
                                       required
                                       onkeyup="checkPasswordStrength(this)">
                                <button type="button" class="btn btn-icon" onclick="togglePassword(this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength">
                                <div class="strength-meter"></div>
                                <span class="strength-text"></span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Confirm New Password</label>
                            <div class="input-group">
                                <span class="input-icon">
                                    <i class="bi bi-lock-fill"></i>
                                </span>
                                <input type="password" 
                                       name="confirm_password" 
                                       class="form-control" 
                                       required>
                                <button type="button" class="btn btn-icon" onclick="togglePassword(this)">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-shield-check"></i>
                            Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Update active tunnels count
fetch('/api/tunnels/count')
    .then(response => response.json())
    .then(data => {
        document.getElementById('activeTunnels').textContent = data.count;
    })
    .catch(() => {
        document.getElementById('activeTunnels').textContent = '0';
    });

// Password visibility toggle
function togglePassword(button) {
    const input = button.previousElementSibling;
    const icon = button.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}

// Password strength checker
function checkPasswordStrength(input) {
    const password = input.value;
    const meter = document.querySelector('.strength-meter');
    const text = document.querySelector('.strength-text');
    
    let strength = 0;
    const checks = {
        length: password.length >= 8,
        lowercase: /[a-z]/.test(password),
        uppercase: /[A-Z]/.test(password),
        numbers: /\d/.test(password),
        special: /[^A-Za-z0-9]/.test(password)
    };
    
    strength = Object.values(checks).filter(Boolean).length;
    
    meter.className = 'strength-meter';
    meter.classList.add(`strength-${strength}`);
    
    const strengthTexts = [
        'Very weak',
        'Weak',
        'Fair',
        'Good',
        'Strong'
    ];
    
    text.textContent = strengthTexts[strength - 1] || '';
}
</script>

<?php require_once 'includes/footer.php'; ?>