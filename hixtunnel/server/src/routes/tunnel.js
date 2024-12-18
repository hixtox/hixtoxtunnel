const express = require('express');
const router = express.Router();
const net = require('net');
const httpProxy = require('http-proxy');
const { v4: uuidv4 } = require('uuid');
const crypto = require('crypto');
const { validateToken, createTunnel, updateTunnelStatus } = require('../db');

const tunnels = new Map();
const MIN_PORT = 20000;
const MAX_PORT = 65000;

// Function to check if a port is in use
const isPortInUse = (port) => {
    return tunnels.has(port);
};

// Function to generate a random port
const generateRandomPort = () => {
    // Define port ranges to avoid common service ports
    const portRanges = [
        [20000, 25000],
        [30000, 35000],
        [40000, 45000],
        [50000, 55000],
        [60000, 65000]
    ];

    // Try up to 10 times to find an available port
    for (let attempt = 0; attempt < 10; attempt++) {
        // Pick a random range
        const rangeIndex = crypto.randomInt(0, portRanges.length);
        const [min, max] = portRanges[rangeIndex];
        
        // Generate random port within the chosen range
        const port = crypto.randomInt(min, max);
        
        // Check if port is available
        if (!isPortInUse(port)) {
            return port;
        }
    }
    
    // If we couldn't find a random port, scan ranges sequentially as fallback
    for (const [min, max] of portRanges) {
        for (let port = min; port <= max; port++) {
            if (!isPortInUse(port)) {
                return port;
            }
        }
    }
    
    throw new Error('No available ports');
};

// Create HTTP proxy server
const proxy = httpProxy.createProxyServer({
    ws: true,
    xfwd: true
});

proxy.on('error', (err, req, res) => {
    console.error('Proxy error:', err);
    if (!res.headersSent) {
        res.writeHead(502, { 'Content-Type': 'text/plain' });
        res.end('Bad Gateway');
    }
});

function createHTTPServer(publicPort, tunnel) {
    const server = express();
    
    server.use((req, res) => {
        console.log(`Proxying HTTP request to ${tunnel.localHost}:${tunnel.localPort}`);
        
        // Instead of proxying directly, we need to handle the request through socket.io
        const requestId = uuidv4();
        const request = {
            requestId,
            method: req.method,
            path: req.url,
            headers: req.headers,
            body: req.body
        };

        // Send request to client through socket.io
        tunnel.socket.emit('request', request);

        // Wait for response from client
        tunnel.socket.once(`response:${requestId}`, (response) => {
            res.status(response.status);
            
            // Set response headers
            if (response.headers) {
                Object.entries(response.headers).forEach(([key, value]) => {
                    if (key.toLowerCase() !== 'content-length') { // Skip content-length as it will be set automatically
                        res.set(key, value);
                    }
                });
            }

            // Send response body
            if (typeof response.body === 'string') {
                const contentType = response.headers && response.headers['content-type'];
                if (contentType && !contentType.includes('text/') && !contentType.includes('application/json')) {
                    // If it's binary data in base64
                    res.send(Buffer.from(response.body, 'base64'));
                } else {
                    res.send(response.body);
                }
            } else {
                res.send(response.body);
            }
        });

        // Handle timeout
        setTimeout(() => {
            if (!res.headersSent) {
                res.status(504).send('Gateway Timeout');
            }
        }, 30000);
    });

    return server.listen(publicPort, () => {
        console.log(`HTTP tunnel listening on port ${publicPort}`);
    });
}

function createTCPServer(publicPort, tunnel) {
    const server = net.createServer((socket) => {
        console.log(`New TCP connection on port ${publicPort}`);
        
        const tcpId = uuidv4();
        tunnel.tcpConnections = tunnel.tcpConnections || new Map();
        tunnel.tcpConnections.set(tcpId, socket);

        // Notify client of new connection
        tunnel.socket.emit('tcp:connect', {
            tcpId,
            remoteAddress: socket.remoteAddress,
            remotePort: socket.remotePort
        });

        // Handle data from the remote client
        socket.on('data', (chunk) => {
            tunnel.socket.emit('tcp:data', {
                tcpId,
                data: chunk.toString('base64')
            });
        });

        // Handle socket closing
        socket.on('end', () => {
            tunnel.socket.emit('tcp:end', { tcpId });
            tunnel.tcpConnections.delete(tcpId);
            if (!socket.destroyed) socket.destroy();
        });

        // Handle socket errors
        socket.on('error', (error) => {
            console.error(`TCP socket error on port ${publicPort}:`, error);
            tunnel.socket.emit('tcp:error', { tcpId, error: error.message });
            tunnel.tcpConnections.delete(tcpId);
            if (!socket.destroyed) socket.destroy();
        });

        // Clean up on socket close
        socket.on('close', () => {
            tunnel.tcpConnections.delete(tcpId);
        });
    });

    // Handle server errors
    server.on('error', (error) => {
        console.error(`TCP server error on port ${publicPort}:`, error);
    });

    server.listen(publicPort, () => {
        console.log(`TCP tunnel listening on port ${publicPort}`);
    });

    return server;
}

function setupTunnelHandlers(io) {
    io.on('connection', async (socket) => {
        console.log('Client connected');
        
        socket.on('register', async (data) => {
            try {
                const userId = await validateToken(data.token);
                if (!userId) {
                    socket.emit('error', { message: 'Invalid token' });
                    socket.disconnect();
                    return;
                }

                const tunnelId = uuidv4();
                let publicPort;

                // Try to use requested port if provided and available
                if (data.requestedPort && !isPortInUse(data.requestedPort)) {
                    // Validate requested port is within allowed ranges
                    const portRanges = [
                        [20000, 25000],
                        [30000, 35000],
                        [40000, 45000],
                        [50000, 55000],
                        [60000, 65000]
                    ];
                    const isValidPort = portRanges.some(([min, max]) => 
                        data.requestedPort >= min && data.requestedPort <= max
                    );
                    if (isValidPort) {
                        publicPort = data.requestedPort;
                    }
                }

                // If requested port is not available or not provided, generate a random one
                if (!publicPort) {
                    publicPort = generateRandomPort();
                }
                
                const tunnelData = {
                    id: tunnelId,
                    userId,
                    protocol: data.protocol,
                    localPort: data.port,
                    remotePort: publicPort,
                    localHost: data.host || 'localhost',
                    status: 'active'
                };

                if (!await createTunnel(tunnelData)) {
                    socket.emit('error', { message: 'Failed to create tunnel' });
                    return;
                }

                const tunnel = {
                    socket,
                    ...tunnelData
                };
                
                tunnels.set(publicPort, tunnel);

                if (data.protocol === 'tcp') {
                    tunnel.tcpServer = createTCPServer(publicPort, tunnel);
                    socket.emit('registered', {
                        tunnelId,
                        publicPort,
                        publicUrl: `tcp://16.170.173.161:${publicPort}`
                    });
                } else {
                    tunnel.httpServer = createHTTPServer(publicPort, tunnel);
                    socket.emit('registered', {
                        tunnelId,
                        publicPort,
                        publicUrl: `http://16.170.173.161:${publicPort}`
                    });
                }
            } catch (error) {
                console.error('Error creating tunnel:', error);
                socket.emit('error', { message: 'Failed to create tunnel' });
            }
        });

        socket.on('tcp:data', (data) => {
            for (const tunnel of tunnels.values()) {
                if (tunnel.socket === socket && tunnel.tcpConnections) {
                    const tcpSocket = tunnel.tcpConnections.get(data.tcpId);
                    if (tcpSocket && !tcpSocket.destroyed) {
                        try {
                            tcpSocket.write(Buffer.from(data.data, 'base64'));
                        } catch (error) {
                            console.error('Error writing to TCP socket:', error);
                            if (!tcpSocket.destroyed) tcpSocket.destroy();
                            tunnel.tcpConnections.delete(data.tcpId);
                        }
                    }
                }
            }
        });

        socket.on('disconnect', async () => {
            for (const [port, tunnel] of tunnels.entries()) {
                if (tunnel.socket === socket) {
                    try {
                        await updateTunnelStatus(tunnel.id, 'inactive');
                        
                        if (tunnel.tcpServer) {
                            tunnel.tcpServer.close();
                        }
                        if (tunnel.httpServer) {
                            tunnel.httpServer.close();
                        }
                        if (tunnel.tcpConnections) {
                            for (const tcpSocket of tunnel.tcpConnections.values()) {
                                tcpSocket.destroy();
                            }
                        }
                        
                        tunnels.delete(port);
                    } catch (error) {
                        console.error('Error updating tunnel status:', error);
                    }
                }
            }
            console.log('Client disconnected');
        });
    });
}

module.exports = { setupTunnelHandlers };
