<?php
class RateLimit {
    protected $db;
    protected $cache;
    
    // Default limits
    protected $limits = [
        'requests_per_second' => 10,
        'requests_per_minute' => 100,
        'requests_per_hour' => 1000,
        'data_per_second' => 1048576, // 1MB
        'data_per_minute' => 10485760, // 10MB
        'data_per_hour' => 104857600 // 100MB
    ];

    public function __construct($db) {
        $this->db = $db;
        $this->cache = new Redis();
        $this->cache->connect('127.0.0.1', 6379);
        $this->loadLimits();
    }

    public function checkLimit($ip) {
        // Check requests per second
        if (!$this->checkWindow($ip, 'rps', 1, $this->limits['requests_per_second'])) {
            throw new Exception('Too many requests per second');
        }

        // Check requests per minute
        if (!$this->checkWindow($ip, 'rpm', 60, $this->limits['requests_per_minute'])) {
            throw new Exception('Too many requests per minute');
        }

        // Check requests per hour
        if (!$this->checkWindow($ip, 'rph', 3600, $this->limits['requests_per_hour'])) {
            throw new Exception('Too many requests per hour');
        }

        return true;
    }

    public function checkDataLimit($ip, $bytes) {
        // Check data per second
        if (!$this->checkDataWindow($ip, 'dps', 1, $this->limits['data_per_second'], $bytes)) {
            throw new Exception('Data transfer limit exceeded (per second)');
        }

        // Check data per minute
        if (!$this->checkDataWindow($ip, 'dpm', 60, $this->limits['data_per_minute'], $bytes)) {
            throw new Exception('Data transfer limit exceeded (per minute)');
        }

        // Check data per hour
        if (!$this->checkDataWindow($ip, 'dph', 3600, $this->limits['data_per_hour'], $bytes)) {
            throw new Exception('Data transfer limit exceeded (per hour)');
        }

        return true;
    }

    protected function checkWindow($ip, $type, $window, $limit) {
        $key = "rate:{$ip}:{$type}";
        
        // Get current count
        $count = $this->cache->get($key);
        
        if ($count === false) {
            // First request in window
            $this->cache->setex($key, $window, 1);
            return true;
        }
        
        if ($count >= $limit) {
            // Log rate limit violation
            $this->logViolation($ip, $type, $count);
            return false;
        }
        
        // Increment counter
        $this->cache->incr($key);
        return true;
    }

    protected function checkDataWindow($ip, $type, $window, $limit, $bytes) {
        $key = "rate:{$ip}:{$type}";
        
        // Get current usage
        $usage = $this->cache->get($key);
        
        if ($usage === false) {
            // First transfer in window
            $this->cache->setex($key, $window, $bytes);
            return true;
        }
        
        if ($usage + $bytes > $limit) {
            // Log rate limit violation
            $this->logViolation($ip, $type, $usage + $bytes);
            return false;
        }
        
        // Add bytes to usage
        $this->cache->incrBy($key, $bytes);
        return true;
    }

    protected function loadLimits() {
        $stmt = $this->db->prepare("
            SELECT type, value
            FROM rate_limits
            WHERE active = 1
        ");
        
        $stmt->execute();
        $limits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($limits as $limit) {
            $this->limits[$limit['type']] = $limit['value'];
        }
    }

    public function updateLimit($type, $value) {
        $stmt = $this->db->prepare("
            INSERT INTO rate_limits (type, value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            value = VALUES(value),
            updated_at = VALUES(updated_at)
        ");
        
        $stmt->execute([$type, $value]);
        $this->loadLimits();
    }

    protected function logViolation($ip, $type, $value) {
        $stmt = $this->db->prepare("
            INSERT INTO rate_limit_violations (
                ip_address, limit_type, value, created_at
            ) VALUES (?, ?, ?, NOW())
        ");
        
        $stmt->execute([$ip, $type, $value]);
    }

    public function getViolations($filters = []) {
        $sql = "
            SELECT rlv.*, t.name as tunnel_name
            FROM rate_limit_violations rlv
            LEFT JOIN tunnels t ON rlv.tunnel_id = t.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if (isset($filters['ip_address'])) {
            $sql .= " AND rlv.ip_address = ?";
            $params[] = $filters['ip_address'];
        }
        
        if (isset($filters['limit_type'])) {
            $sql .= " AND rlv.limit_type = ?";
            $params[] = $filters['limit_type'];
        }
        
        if (isset($filters['start_date'])) {
            $sql .= " AND rlv.created_at >= ?";
            $params[] = $filters['start_date'];
        }
        
        if (isset($filters['end_date'])) {
            $sql .= " AND rlv.created_at <= ?";
            $params[] = $filters['end_date'];
        }
        
        $sql .= " ORDER BY rlv.created_at DESC";
        
        if (isset($filters['limit'])) {
            $sql .= " LIMIT ?";
            $params[] = $filters['limit'];
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLimits() {
        $stmt = $this->db->prepare("
            SELECT type, value, updated_at
            FROM rate_limits
            WHERE active = 1
            ORDER BY type
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function clearLimits($ip) {
        $patterns = [
            "rate:{$ip}:rps",
            "rate:{$ip}:rpm",
            "rate:{$ip}:rph",
            "rate:{$ip}:dps",
            "rate:{$ip}:dpm",
            "rate:{$ip}:dph"
        ];
        
        foreach ($patterns as $pattern) {
            $this->cache->del($pattern);
        }
    }
}
