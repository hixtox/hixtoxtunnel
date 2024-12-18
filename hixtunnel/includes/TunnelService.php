<?php
class TunnelService {
    protected $db;
    protected $tunnelManager;
    protected $rateLimit;
    protected $securityManager;
    protected $activeConnections = [];
    protected $metrics = [];

    public function __construct($db) {
        $this->db = $db;
        $this->tunnelManager = new TunnelManager($db);
        $this->rateLimit = new RateLimit($db);
        $this->securityManager = new SecurityManager($db);
    }

    public function startTunnel($tunnelId, $userId) {
        $tunnel = $this->tunnelManager->getTunnel($tunnelId, $userId);
        if (!$tunnel) {
            throw new Exception('Tunnel not found');
        }

        // Check if tunnel is already running
        if (isset($this->activeConnections[$tunnelId])) {
            throw new Exception('Tunnel is already running');
        }

        // Create socket for remote port
        $remoteSocket = stream_socket_server(
            "tcp://0.0.0.0:{$tunnel['remote_port']}",
            $errno,
            $errstr
        );

        if (!$remoteSocket) {
            throw new Exception("Failed to create remote socket: $errstr");
        }

        // Set non-blocking mode
        stream_set_blocking($remoteSocket, false);

        // Store connection info
        $this->activeConnections[$tunnelId] = [
            'socket' => $remoteSocket,
            'clients' => [],
            'metrics' => [
                'bytes_in' => 0,
                'bytes_out' => 0,
                'connections' => 0,
                'errors' => 0,
                'start_time' => time()
            ]
        ];

        // Start monitoring thread
        $this->startMonitoringThread($tunnelId);

        return true;
    }

    public function handleConnections() {
        while (true) {
            foreach ($this->activeConnections as $tunnelId => $tunnel) {
                $remoteSocket = $tunnel['socket'];
                
                // Accept new connections
                if ($client = @stream_socket_accept($remoteSocket, 0)) {
                    // Get client IP
                    $clientInfo = stream_socket_get_name($client, true);
                    $clientIp = explode(':', $clientInfo)[0];

                    // Security checks
                    try {
                        $this->securityManager->checkIp($clientIp);
                        $this->rateLimit->checkLimit($clientIp);
                    } catch (Exception $e) {
                        fclose($client);
                        $this->logSecurityEvent($tunnelId, $clientIp, $e->getMessage());
                        continue;
                    }

                    // Connect to local service
                    $localClient = stream_socket_client(
                        "tcp://{$tunnel['local_host']}:{$tunnel['local_port']}",
                        $errno,
                        $errstr
                    );

                    if (!$localClient) {
                        fclose($client);
                        $this->logError($tunnelId, "Failed to connect to local service: $errstr");
                        continue;
                    }

                    // Set non-blocking mode
                    stream_set_blocking($client, false);
                    stream_set_blocking($localClient, false);

                    // Store client connection
                    $connectionId = uniqid();
                    $this->activeConnections[$tunnelId]['clients'][$connectionId] = [
                        'remote' => $client,
                        'local' => $localClient,
                        'buffers' => [
                            'remote' => '',
                            'local' => ''
                        ],
                        'metrics' => [
                            'bytes_in' => 0,
                            'bytes_out' => 0,
                            'start_time' => microtime(true)
                        ],
                        'ip' => $clientIp
                    ];

                    // Update metrics
                    $this->activeConnections[$tunnelId]['metrics']['connections']++;
                }

                // Handle existing connections
                foreach ($tunnel['clients'] as $connectionId => $connection) {
                    $this->handleConnection($tunnelId, $connectionId);
                }
            }

            // Small delay to prevent CPU overload
            usleep(10000); // 10ms
        }
    }

    protected function handleConnection($tunnelId, $connectionId) {
        $connection = &$this->activeConnections[$tunnelId]['clients'][$connectionId];
        $remote = $connection['remote'];
        $local = $connection['local'];

        // Check if connections are still valid
        if (!is_resource($remote) || !is_resource($local)) {
            $this->closeConnection($tunnelId, $connectionId);
            return;
        }

        // Handle remote -> local
        $data = fread($remote, 8192);
        if ($data !== false) {
            if (strlen($data) > 0) {
                // Apply rate limiting
                if (!$this->rateLimit->checkDataLimit($connection['ip'], strlen($data))) {
                    $this->closeConnection($tunnelId, $connectionId);
                    return;
                }

                // Security checks on data
                if (!$this->securityManager->validateData($data)) {
                    $this->closeConnection($tunnelId, $connectionId);
                    $this->logSecurityEvent($tunnelId, $connection['ip'], 'Malicious data detected');
                    return;
                }

                $connection['buffers']['remote'] .= $data;
                $connection['metrics']['bytes_in'] += strlen($data);
                $this->activeConnections[$tunnelId]['metrics']['bytes_in'] += strlen($data);
            }
        }

        // Handle local -> remote
        $data = fread($local, 8192);
        if ($data !== false) {
            if (strlen($data) > 0) {
                $connection['buffers']['local'] .= $data;
                $connection['metrics']['bytes_out'] += strlen($data);
                $this->activeConnections[$tunnelId]['metrics']['bytes_out'] += strlen($data);
            }
        }

        // Send buffered data
        if (strlen($connection['buffers']['remote']) > 0) {
            $written = fwrite($local, $connection['buffers']['remote']);
            if ($written > 0) {
                $connection['buffers']['remote'] = substr($connection['buffers']['remote'], $written);
            }
        }

        if (strlen($connection['buffers']['local']) > 0) {
            $written = fwrite($remote, $connection['buffers']['local']);
            if ($written > 0) {
                $connection['buffers']['local'] = substr($connection['buffers']['local'], $written);
            }
        }

        // Check for connection end
        if (feof($remote) || feof($local)) {
            $this->closeConnection($tunnelId, $connectionId);
        }
    }

    protected function closeConnection($tunnelId, $connectionId) {
        $connection = $this->activeConnections[$tunnelId]['clients'][$connectionId];
        
        // Close sockets
        if (is_resource($connection['remote'])) {
            fclose($connection['remote']);
        }
        if (is_resource($connection['local'])) {
            fclose($connection['local']);
        }

        // Log final metrics
        $duration = microtime(true) - $connection['metrics']['start_time'];
        $this->logMetrics($tunnelId, [
            'bytes_in' => $connection['metrics']['bytes_in'],
            'bytes_out' => $connection['metrics']['bytes_out'],
            'duration' => $duration,
            'client_ip' => $connection['ip']
        ]);

        // Remove connection
        unset($this->activeConnections[$tunnelId]['clients'][$connectionId]);
    }

    public function stopTunnel($tunnelId) {
        if (!isset($this->activeConnections[$tunnelId])) {
            return false;
        }

        // Close all client connections
        foreach ($this->activeConnections[$tunnelId]['clients'] as $connectionId => $connection) {
            $this->closeConnection($tunnelId, $connectionId);
        }

        // Close remote socket
        if (is_resource($this->activeConnections[$tunnelId]['socket'])) {
            fclose($this->activeConnections[$tunnelId]['socket']);
        }

        // Log final tunnel metrics
        $this->logTunnelMetrics($tunnelId);

        // Remove tunnel
        unset($this->activeConnections[$tunnelId]);

        return true;
    }

    protected function startMonitoringThread($tunnelId) {
        // Start a separate process for monitoring
        $pid = pcntl_fork();
        
        if ($pid == -1) {
            throw new Exception('Failed to start monitoring process');
        } else if ($pid) {
            // Parent process
            return $pid;
        } else {
            // Child process
            while (true) {
                if (!isset($this->activeConnections[$tunnelId])) {
                    exit(0);
                }

                $this->updateMetrics($tunnelId);
                sleep(1);
            }
        }
    }

    protected function updateMetrics($tunnelId) {
        if (!isset($this->activeConnections[$tunnelId])) {
            return;
        }

        $metrics = $this->activeConnections[$tunnelId]['metrics'];
        $this->tunnelManager->updateMetrics($tunnelId, $metrics);

        // Reset counters
        $this->activeConnections[$tunnelId]['metrics']['bytes_in'] = 0;
        $this->activeConnections[$tunnelId]['metrics']['bytes_out'] = 0;
    }

    protected function logMetrics($tunnelId, $metrics) {
        $stmt = $this->db->prepare("
            INSERT INTO tunnel_metrics (
                tunnel_id, timestamp, bytes_in, bytes_out,
                duration, client_ip
            ) VALUES (?, NOW(), ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tunnelId,
            $metrics['bytes_in'],
            $metrics['bytes_out'],
            $metrics['duration'],
            $metrics['client_ip']
        ]);
    }

    protected function logTunnelMetrics($tunnelId) {
        $metrics = $this->activeConnections[$tunnelId]['metrics'];
        $duration = time() - $metrics['start_time'];

        $stmt = $this->db->prepare("
            INSERT INTO tunnel_sessions (
                tunnel_id, start_time, end_time, total_bytes_in,
                total_bytes_out, total_connections, total_errors
            ) VALUES (?, FROM_UNIXTIME(?), NOW(), ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tunnelId,
            $metrics['start_time'],
            $metrics['bytes_in'],
            $metrics['bytes_out'],
            $metrics['connections'],
            $metrics['errors']
        ]);
    }

    protected function logError($tunnelId, $error) {
        $stmt = $this->db->prepare("
            INSERT INTO tunnel_errors (
                tunnel_id, error_message, created_at
            ) VALUES (?, ?, NOW())
        ");

        $stmt->execute([$tunnelId, $error]);
        $this->activeConnections[$tunnelId]['metrics']['errors']++;
    }

    protected function logSecurityEvent($tunnelId, $ip, $reason) {
        $stmt = $this->db->prepare("
            INSERT INTO security_events (
                tunnel_id, ip_address, event_type, details, created_at
            ) VALUES (?, ?, 'block', ?, NOW())
        ");

        $stmt->execute([$tunnelId, $ip, $reason]);
    }
}
