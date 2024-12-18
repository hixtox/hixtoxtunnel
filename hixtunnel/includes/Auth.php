<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Auth {
    protected $db;
    protected $jwtKey;
    protected $tokenExpiration;

    public function __construct($db) {
        $this->db = $db;
        $this->jwtKey = getenv('JWT_SECRET_KEY');
        $this->tokenExpiration = 86400; // 24 hours
    }

    public function login($email, $password) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !password_verify($password, $user['password'])) {
            throw new Exception('Invalid credentials', 401);
        }
        
        // Create session
        $token = $this->createToken($user);
        $this->createSession($user['id'], $token);
        
        unset($user['password']);
        return [
            'user' => $user,
            'token' => $token
        ];
    }

    public function register($data) {
        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address', 400);
        }
        
        // Check if email exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            throw new Exception('Email already exists', 400);
        }
        
        // Validate password
        if (strlen($data['password']) < 8) {
            throw new Exception('Password must be at least 8 characters', 400);
        }
        
        // Create user
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, password, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT)
        ]);
        
        return ['success' => true];
    }

    public function forgotPassword($email) {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $token = bin2hex(random_bytes(32));
            
            $stmt = $this->db->prepare("
                INSERT INTO password_resets (user_id, token, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))
            ");
            
            $stmt->execute([$user['id'], $token]);
            
            // Send password reset email
            $this->sendPasswordResetEmail($email, $token);
        }
        
        return ['success' => true];
    }

    public function resetPassword($token, $password) {
        $stmt = $this->db->prepare("
            SELECT user_id
            FROM password_resets
            WHERE token = ? AND expires_at > NOW() AND used = 0
        ");
        
        $stmt->execute([$token]);
        $reset = $stmt->fetch();
        
        if (!$reset) {
            throw new Exception('Invalid or expired token', 400);
        }
        
        // Update password
        $stmt = $this->db->prepare("
            UPDATE users
            SET password = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $reset['user_id']
        ]);
        
        // Mark token as used
        $stmt = $this->db->prepare("
            UPDATE password_resets
            SET used = 1
            WHERE token = ?
        ");
        
        $stmt->execute([$token]);
        
        return ['success' => true];
    }

    public function validateToken($token) {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtKey, 'HS256'));
            
            // Verify session
            $stmt = $this->db->prepare("
                SELECT id
                FROM user_sessions
                WHERE user_id = ? AND token = ? AND expires_at > NOW()
            ");
            
            $stmt->execute([$decoded->sub, $token]);
            return $stmt->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getUserIdFromToken($token) {
        $decoded = JWT::decode($token, new Key($this->jwtKey, 'HS256'));
        return $decoded->sub;
    }

    public function isAdmin($userId) {
        $stmt = $this->db->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        return $user && $user['is_admin'];
    }

    public function getApiTokens($userId) {
        $stmt = $this->db->prepare("
            SELECT id, name, permissions, last_used_at, created_at
            FROM api_tokens
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createApiToken($name, $permissions, $userId) {
        $token = bin2hex(random_bytes(32));
        
        $stmt = $this->db->prepare("
            INSERT INTO api_tokens (user_id, name, token, permissions, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $name,
            hash('sha256', $token),
            json_encode($permissions)
        ]);
        
        return [
            'id' => $this->db->lastInsertId(),
            'name' => $name,
            'token' => $token,
            'permissions' => $permissions
        ];
    }

    public function deleteApiToken($tokenId, $userId) {
        $stmt = $this->db->prepare("DELETE FROM api_tokens WHERE id = ? AND user_id = ?");
        $stmt->execute([$tokenId, $userId]);
        return ['success' => true];
    }

    public function getUserProfile($userId) {
        $stmt = $this->db->prepare("
            SELECT id, name, email, created_at, is_admin
            FROM users
            WHERE id = ?
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateUserProfile($userId, $data) {
        $stmt = $this->db->prepare("
            UPDATE users
            SET name = ?, email = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['name'],
            $data['email'],
            $userId
        ]);
        
        return $this->getUserProfile($userId);
    }

    public function changePassword($userId, $currentPassword, $newPassword) {
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!password_verify($currentPassword, $user['password'])) {
            throw new Exception('Current password is incorrect', 400);
        }
        
        $stmt = $this->db->prepare("
            UPDATE users
            SET password = ?, updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            password_hash($newPassword, PASSWORD_DEFAULT),
            $userId
        ]);
        
        return ['success' => true];
    }

    public function getNotificationSettings($userId) {
        $stmt = $this->db->prepare("
            SELECT email_notifications, tunnel_alerts, usage_reports
            FROM user_settings
            WHERE user_id = ?
        ");
        
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'email_notifications' => true,
            'tunnel_alerts' => true,
            'usage_reports' => true
        ];
    }

    public function updateNotificationSettings($userId, $settings) {
        $stmt = $this->db->prepare("
            INSERT INTO user_settings (user_id, email_notifications, tunnel_alerts, usage_reports)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            email_notifications = VALUES(email_notifications),
            tunnel_alerts = VALUES(tunnel_alerts),
            usage_reports = VALUES(usage_reports)
        ");
        
        $stmt->execute([
            $userId,
            $settings['email_notifications'],
            $settings['tunnel_alerts'],
            $settings['usage_reports']
        ]);
        
        return $this->getNotificationSettings($userId);
    }

    protected function createToken($user) {
        $payload = [
            'sub' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'is_admin' => $user['is_admin'],
            'iat' => time(),
            'exp' => time() + $this->tokenExpiration
        ];
        
        return JWT::encode($payload, $this->jwtKey, 'HS256');
    }

    protected function createSession($userId, $token) {
        $stmt = $this->db->prepare("
            INSERT INTO user_sessions (user_id, token, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        
        $stmt->execute([
            $userId,
            $token,
            $this->tokenExpiration
        ]);
    }

    protected function sendPasswordResetEmail($email, $token) {
        // Implementation depends on your email service
        // This is a placeholder for the actual email sending
    }

    public function getUsers() {
        $stmt = $this->db->prepare("
            SELECT id, name, email, is_admin, created_at, updated_at
            FROM users
            ORDER BY created_at DESC
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUser($userId) {
        $stmt = $this->db->prepare("
            SELECT id, name, email, is_admin, created_at, updated_at
            FROM users
            WHERE id = ?
        ");
        
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('User not found', 404);
        }
        
        return $user;
    }

    public function createUser($data) {
        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address', 400);
        }
        
        // Check if email exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$data['email']]);
        if ($stmt->fetch()) {
            throw new Exception('Email already exists', 400);
        }
        
        // Create user
        $stmt = $this->db->prepare("
            INSERT INTO users (name, email, password, is_admin, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $data['name'],
            $data['email'],
            password_hash($data['password'], PASSWORD_DEFAULT),
            $data['is_admin'] ?? false
        ]);
        
        return $this->getUser($this->db->lastInsertId());
    }

    public function updateUser($userId, $data) {
        $stmt = $this->db->prepare("
            UPDATE users
            SET name = ?,
                email = ?,
                is_admin = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $data['name'],
            $data['email'],
            $data['is_admin'] ?? false,
            $userId
        ]);
        
        return $this->getUser($userId);
    }

    public function deleteUser($userId) {
        // Delete user sessions
        $stmt = $this->db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Delete API tokens
        $stmt = $this->db->prepare("DELETE FROM api_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Delete user settings
        $stmt = $this->db->prepare("DELETE FROM user_settings WHERE user_id = ?");
        $stmt->execute([$userId]);
        
        // Delete user
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        return ['success' => true];
    }

    public function getAuditLogs() {
        $stmt = $this->db->prepare("
            SELECT al.*, u.name as user_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            ORDER BY al.created_at DESC
            LIMIT 1000
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function logAudit($userId, $action, $details = null) {
        $stmt = $this->db->prepare("
            INSERT INTO audit_logs (user_id, action, details, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $userId,
            $action,
            $details ? json_encode($details) : null
        ]);
    }
}
