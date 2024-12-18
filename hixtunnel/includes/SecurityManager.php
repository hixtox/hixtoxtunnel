<?php
class SecurityManager {
    protected $db;
    protected $cache;
    protected $config;

    // Security rules
    protected $rules = [
        'ip_blacklist' => [],
        'country_blacklist' => [],
        'user_agent_blacklist' => [],
        'request_patterns' => [],
        'payload_patterns' => []
    ];

    public function __construct($db) {
        $this->db = $db;
        $this->cache = new Redis();
        $this->cache->connect('127.0.0.1', 6379);
        $this->loadSecurityRules();
    }

    public function checkIp($ip) {
        // Check if IP is blocked
        if ($this->isIpBlocked($ip)) {
            throw new Exception('IP address is blocked');
        }

        // Check country restrictions
        $country = $this->getCountryCode($ip);
        if ($this->isCountryBlocked($country)) {
            $this->blockIp($ip, 'Country blocked');
            throw new Exception('Access denied from your country');
        }

        // Check for suspicious activity
        if ($this->detectSuspiciousActivity($ip)) {
            $this->blockIp($ip, 'Suspicious activity');
            throw new Exception('Suspicious activity detected');
        }

        return true;
    }

    public function validateData($data) {
        // Check for malicious patterns
        foreach ($this->rules['payload_patterns'] as $pattern) {
            if (preg_match($pattern, $data)) {
                return false;
            }
        }

        // Check for SQL injection attempts
        if ($this->detectSqlInjection($data)) {
            return false;
        }

        // Check for XSS attempts
        if ($this->detectXss($data)) {
            return false;
        }

        // Check for command injection attempts
        if ($this->detectCommandInjection($data)) {
            return false;
        }

        return true;
    }

    public function blockIp($ip, $reason) {
        $stmt = $this->db->prepare("
            INSERT INTO ip_blocks (ip_address, reason, created_at, expires_at)
            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ");

        $stmt->execute([$ip, $reason]);

        // Add to cache
        $this->cache->setex("ip_block:$ip", 86400, $reason);
    }

    public function unblockIp($ip) {
        $stmt = $this->db->prepare("
            DELETE FROM ip_blocks
            WHERE ip_address = ?
        ");

        $stmt->execute([$ip]);

        // Remove from cache
        $this->cache->del("ip_block:$ip");
    }

    public function addSecurityRule($type, $value, $description = '') {
        $stmt = $this->db->prepare("
            INSERT INTO security_rules (type, value, description, created_at)
            VALUES (?, ?, ?, NOW())
        ");

        $stmt->execute([$type, $value, $description]);
        $this->loadSecurityRules();
    }

    public function removeSecurityRule($ruleId) {
        $stmt = $this->db->prepare("
            DELETE FROM security_rules
            WHERE id = ?
        ");

        $stmt->execute([$ruleId]);
        $this->loadSecurityRules();
    }

    protected function loadSecurityRules() {
        $stmt = $this->db->prepare("
            SELECT type, value
            FROM security_rules
            WHERE active = 1
        ");

        $stmt->execute();
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rules as $rule) {
            $this->rules[$rule['type']][] = $rule['value'];
        }
    }

    protected function isIpBlocked($ip) {
        // Check cache first
        if ($this->cache->exists("ip_block:$ip")) {
            return true;
        }

        // Check database
        $stmt = $this->db->prepare("
            SELECT 1
            FROM ip_blocks
            WHERE ip_address = ? AND expires_at > NOW()
        ");

        $stmt->execute([$ip]);
        return $stmt->fetch() !== false;
    }

    protected function isCountryBlocked($countryCode) {
        return in_array($countryCode, $this->rules['country_blacklist']);
    }

    protected function detectSuspiciousActivity($ip) {
        $window = 60; // 1 minute
        $maxRequests = 100;

        $requests = $this->cache->get("requests:$ip");
        if (!$requests) {
            $this->cache->setex("requests:$ip", $window, 1);
            return false;
        }

        if ($requests > $maxRequests) {
            return true;
        }

        $this->cache->incr("requests:$ip");
        return false;
    }

    protected function detectSqlInjection($data) {
        $patterns = [
            '/\b(UNION|SELECT|INSERT|UPDATE|DELETE|DROP)\b/i',
            '/[\'"]\s*OR\s*[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?/i',
            '/[\'"]\s*;\s*DROP\s+TABLE\s+/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }

        return false;
    }

    protected function detectXss($data) {
        $patterns = [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/javascript:/i',
            '/on\w+\s*=\s*[\'"].*?[\'"]/',
            '/<\s*img[^>]+src\s*=\s*[\'"]data:/'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }

        return false;
    }

    protected function detectCommandInjection($data) {
        $patterns = [
            '/[&|;`\$]/',
            '/\b(cat|grep|chmod|mkdir|rm|mv|cp|wget|curl)\b/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $data)) {
                return true;
            }
        }

        return false;
    }

    protected function getCountryCode($ip) {
        // Use MaxMind GeoIP2 or similar service
        // This is a placeholder
        return 'US';
    }

    public function getSecurityEvents($filters = []) {
        $sql = "
            SELECT se.*, t.name as tunnel_name, u.email as user_email
            FROM security_events se
            LEFT JOIN tunnels t ON se.tunnel_id = t.id
            LEFT JOIN users u ON t.user_id = u.id
            WHERE 1=1
        ";

        $params = [];

        if (isset($filters['tunnel_id'])) {
            $sql .= " AND se.tunnel_id = ?";
            $params[] = $filters['tunnel_id'];
        }

        if (isset($filters['ip_address'])) {
            $sql .= " AND se.ip_address = ?";
            $params[] = $filters['ip_address'];
        }

        if (isset($filters['event_type'])) {
            $sql .= " AND se.event_type = ?";
            $params[] = $filters['event_type'];
        }

        if (isset($filters['start_date'])) {
            $sql .= " AND se.created_at >= ?";
            $params[] = $filters['start_date'];
        }

        if (isset($filters['end_date'])) {
            $sql .= " AND se.created_at <= ?";
            $params[] = $filters['end_date'];
        }

        $sql .= " ORDER BY se.created_at DESC";

        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getBlockedIps() {
        $stmt = $this->db->prepare("
            SELECT ip_address, reason, created_at, expires_at
            FROM ip_blocks
            WHERE expires_at > NOW()
            ORDER BY created_at DESC
        ");

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSecurityRules() {
        $stmt = $this->db->prepare("
            SELECT id, type, value, description, created_at
            FROM security_rules
            WHERE active = 1
            ORDER BY type, created_at DESC
        ");

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
