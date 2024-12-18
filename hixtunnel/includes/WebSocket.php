<?php
require_once __DIR__ . '/vendor/autoload.php';
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class WebSocket implements MessageComponentInterface {
    protected $clients;
    protected $subscriptions;
    protected $auth;
    protected $db;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        $this->subscriptions = [];
        $this->auth = new Auth($this->db);
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->subscriptions[$conn->resourceId] = [];
        
        echo "New connection! ({$conn->resourceId})\n";
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        
        if (!isset($data['action'])) {
            return;
        }
        
        switch ($data['action']) {
            case 'authenticate':
                $this->handleAuthentication($from, $data['token']);
                break;
                
            case 'subscribe':
                $this->handleSubscription($from, $data['channel']);
                break;
                
            case 'unsubscribe':
                $this->handleUnsubscription($from, $data['channel']);
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        unset($this->subscriptions[$conn->resourceId]);
        
        echo "Connection {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "An error has occurred: {$e->getMessage()}\n";
        $conn->close();
    }

    protected function handleAuthentication($conn, $token) {
        try {
            if ($this->auth->validateToken($token)) {
                $userId = $this->auth->getUserIdFromToken($token);
                $conn->userId = $userId;
                
                $this->sendToClient($conn, [
                    'type' => 'auth',
                    'status' => 'success'
                ]);
            } else {
                $this->sendToClient($conn, [
                    'type' => 'auth',
                    'status' => 'error',
                    'message' => 'Invalid token'
                ]);
                $conn->close();
            }
        } catch (\Exception $e) {
            $this->sendToClient($conn, [
                'type' => 'auth',
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            $conn->close();
        }
    }

    protected function handleSubscription($conn, $channel) {
        if (!isset($conn->userId)) {
            $this->sendToClient($conn, [
                'type' => 'subscription',
                'status' => 'error',
                'message' => 'Not authenticated'
            ]);
            return;
        }
        
        // Validate channel access
        if (strpos($channel, 'tunnel.') === 0) {
            $tunnelId = substr($channel, 7);
            if (!$this->canAccessTunnel($conn->userId, $tunnelId)) {
                $this->sendToClient($conn, [
                    'type' => 'subscription',
                    'status' => 'error',
                    'message' => 'Access denied'
                ]);
                return;
            }
        }
        
        $this->subscriptions[$conn->resourceId][] = $channel;
        
        $this->sendToClient($conn, [
            'type' => 'subscription',
            'status' => 'success',
            'channel' => $channel
        ]);
    }

    protected function handleUnsubscription($conn, $channel) {
        $index = array_search($channel, $this->subscriptions[$conn->resourceId]);
        if ($index !== false) {
            unset($this->subscriptions[$conn->resourceId][$index]);
        }
        
        $this->sendToClient($conn, [
            'type' => 'unsubscription',
            'status' => 'success',
            'channel' => $channel
        ]);
    }

    protected function canAccessTunnel($userId, $tunnelId) {
        try {
            $tunnelManager = new TunnelManager($this->db);
            $tunnel = $tunnelManager->getTunnel($tunnelId, $userId);
            return $tunnel !== null;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function broadcast($channel, $data) {
        foreach ($this->clients as $client) {
            if (isset($this->subscriptions[$client->resourceId]) &&
                in_array($channel, $this->subscriptions[$client->resourceId])) {
                $this->sendToClient($client, $data);
            }
        }
    }

    protected function sendToClient($client, $data) {
        $client->send(json_encode($data));
    }

    public static function start() {
        $server = IoServer::factory(
            new HttpServer(
                new WsServer(
                    new self()
                )
            ),
            8080
        );
        
        echo "WebSocket server started on port 8080\n";
        $server->run();
    }
}

// Start the WebSocket server if this file is run directly
if (php_sapi_name() === 'cli') {
    WebSocket::start();
}
