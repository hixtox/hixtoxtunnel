<?php
require_once 'includes/header.php';

if (isLoggedIn()) {
    redirect('/dashboard');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = 'Please fill in all fields';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        // Check if username exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Username already exists';
        } else {
            // Check if email exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn() > 0) {
                $error = 'Email already exists';
            } else {
                // Create user
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                try {
                    $stmt->execute([$username, $email, $hashed_password]);
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    $_SESSION['username'] = $username;
                    $_SESSION['email'] = $email;
                    redirect('/dashboard');
                } catch (PDOException $e) {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
    }
}
?>

<div class="auth-container">
    <div class="auth-card">
        <div class="auth-header">
            <img src="/assets/logo.svg" alt="HixTunnel" class="auth-logo">
            <h1>Create Account</h1>
            <p class="text-muted">Join HixTunnel to start creating secure tunnels</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle"></i>
            <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <div class="form-group">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-icon">
                        <i class="bi bi-person"></i>
                    </span>
                    <input type="text" 
                           name="username" 
                           class="form-control" 
                           placeholder="Choose a username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Email</label>
                <div class="input-group">
                    <span class="input-icon">
                        <i class="bi bi-envelope"></i>
                    </span>
                    <input type="email" 
                           name="email" 
                           class="form-control" 
                           placeholder="Enter your email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                           required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-icon">
                        <i class="bi bi-lock"></i>
                    </span>
                    <input type="password" 
                           name="password" 
                           class="form-control" 
                           placeholder="Create a password"
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

            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <div class="input-group">
                    <span class="input-icon">
                        <i class="bi bi-lock-fill"></i>
                    </span>
                    <input type="password" 
                           name="confirm_password" 
                           class="form-control" 
                           placeholder="Confirm your password"
                           required>
                    <button type="button" class="btn btn-icon" onclick="togglePassword(this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
            </div>

            <div class="form-group">
                <div class="form-check">
                    <input type="checkbox" class="form-check-input" id="terms" required>
                    <label class="form-check-label" for="terms">
                        I agree to the <a href="/terms.php">Terms of Service</a> and <a href="/privacy.php">Privacy Policy</a>
                    </label>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-person-plus"></i>
                Create Account
            </button>
        </form>

        <div class="auth-footer">
            <p>Already have an account? <a href="/login.php">Sign in</a></p>
        </div>
    </div>
</div>

<script>
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

function checkPasswordStrength(input) {
    const password = input.value;
    const meter = document.querySelector('.strength-meter');
    const text = document.querySelector('.strength-text');
    
    // Calculate strength
    let strength = 0;
    const checks = {
        length: password.length >= 8,
        lowercase: /[a-z]/.test(password),
        uppercase: /[A-Z]/.test(password),
        numbers: /\d/.test(password),
        special: /[^A-Za-z0-9]/.test(password)
    };
    
    strength = Object.values(checks).filter(Boolean).length;
    
    // Update UI
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