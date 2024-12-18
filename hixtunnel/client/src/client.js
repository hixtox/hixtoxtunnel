const { io } = require('socket.io-client');
const axios = require('axios');
const yargs = require('yargs');
const chalk = require('chalk');
const net = require('net');
const { getStoredToken, storeToken, getServerUrl, storeServer } = require('./config');
const { displayStatus, logRequest, displayMetrics } = require('./utils');
const crypto = require('crypto');

// Function to generate a random port number
const generateRandomPort = () => {
    // Define port ranges to choose from
    const portRanges = [
        [20000, 25000],
        [30000, 35000],
        [40000, 45000],
        [50000, 55000],
        [60000, 65000]
    ];

    // Pick a random range
    const rangeIndex = crypto.randomInt(0, portRanges.length);
    const [min, max] = portRanges[rangeIndex];
    
    // Generate random port within the chosen range
    return crypto.randomInt(min, max);
};

// Track metrics
let metrics = {
    bytesIn: 0,
    bytesOut: 0,
    requests: 0,
    errors: 0,
    responseTime: 0
};

// Function to update metrics
const updateMetrics = (type, value) => {
    switch (type) {
        case 'bytesIn':
            metrics.bytesIn += value;
            break;
        case 'bytesOut':
            metrics.bytesOut += value;
            break;
        case 'request':
            metrics.requests++;
            break;
        case 'error':
            metrics.errors++;
            break;
        case 'responseTime':
            metrics.responseTime = (metrics.responseTime * (metrics.requests - 1) + value) / metrics.requests;
            break;
    }
};

// Function to send metrics to server
const sendMetrics = async (socket, tunnelId) => {
    try {
        socket.emit('metrics', {
            tunnelId,
            metrics: { ...metrics }
        });
        
        // Reset certain metrics after sending
        metrics.requests = 0;
        metrics.errors = 0;
        metrics.responseTime = 0;
    } catch (error) {
        console.error('Error sending metrics:', error);
    }
};

const showBanner = () => {
    console.log(chalk.cyan('HIXTUNNEL') + ' by ' + chalk.yellow('{Hixtox Lab}') + '\n');
    console.log('Start tunnel:\n' + chalk.green('hixtunnel -P [http/tcp] -p PORT') + '\n');
    console.log('Options:');
    console.log('  -t, --token     Your API token');
    console.log('  -s, --server    Server URL (e.g., http://localhost:8080)');
    console.log('  -P, --protocol  Protocol (http, tcp)');
    console.log('  -p, --port      Local port to forward');
    console.log('  -H, --host      Local host (default: localhost)');
    console.log('  -n, --name      Tunnel name');
    console.log('  -m, --metrics   Show metrics (default: true)\n');
};

const main = async () => {
    const argv = yargs
        .option('protocol', {
            alias: 'P',
            describe: 'Protocol (http, tcp)',
            type: 'string',
            default: 'http'
        })
        .option('port', {
            alias: 'p',
            describe: 'Local port to forward',
            type: 'number',
            required: true
        })
        .option('host', {
            alias: 'H',
            describe: 'Local host',
            type: 'string',
            default: 'localhost'
        })
        .option('name', {
            alias: 'n',
            describe: 'Tunnel name',
            type: 'string'
        })
        .option('token', {
            alias: 't',
            describe: 'Your API token',
            type: 'string'
        })
        .option('server', {
            alias: 's',
            describe: 'Server URL',
            type: 'string'
        })
        .option('metrics', {
            alias: 'm',
            describe: 'Show metrics',
            type: 'boolean',
            default: true
        })
        .argv;

    showBanner();

    // Get or store token
    const token = argv.token || getStoredToken();
    if (!token) {
        console.error(chalk.red('Error: API token is required'));
        process.exit(1);
    }
    if (argv.token) {
        storeToken(argv.token);
    }

    // Get or store server URL
    const serverUrl = argv.server || getServerUrl();
    if (!serverUrl) {
        console.error(chalk.red('Error: Server URL is required'));
        process.exit(1);
    }
    if (argv.server) {
        storeServer(argv.server);
    }

    // Connect to server
    const socket = io(serverUrl, {
        auth: { token }
    });

    // Handle connection
    socket.on('connect', async () => {
        try {
            // Generate random port if not specified
            const localPort = argv.port || generateRandomPort();
            const localHost = argv.host;
            const protocol = argv.protocol;
            const name = argv.name || `${protocol}-${localPort}`;

            // Create tunnel
            const response = await axios.post(`${serverUrl}/api/tunnels`, {
                name,
                protocol,
                localPort,
                localHost
            }, {
                headers: { Authorization: `Bearer ${token}` }
            });

            const tunnelId = response.data.id;
            const publicPort = response.data.remotePort;

            // Display status
            displayStatus(response.data.username, protocol, localPort, localHost, publicPort);

            // Start metrics display if enabled
            if (argv.metrics) {
                setInterval(() => {
                    displayMetrics(metrics);
                }, 1000);
            }

            // Send metrics to server periodically
            setInterval(() => {
                sendMetrics(socket, tunnelId);
            }, 5000);

            // Handle incoming connections
            socket.on('connection', async (data) => {
                const startTime = Date.now();
                const client = new net.Socket();

                client.connect(localPort, localHost, () => {
                    socket.emit('ready', { connectionId: data.connectionId });
                });

                client.on('data', (data) => {
                    updateMetrics('bytesOut', data.length);
                    socket.emit('data', {
                        connectionId: data.connectionId,
                        data: data
                    });
                });

                client.on('end', () => {
                    socket.emit('end', { connectionId: data.connectionId });
                    const responseTime = Date.now() - startTime;
                    updateMetrics('responseTime', responseTime);
                });

                client.on('error', (error) => {
                    console.error('Local connection error:', error);
                    socket.emit('error', {
                        connectionId: data.connectionId,
                        error: error.message
                    });
                    updateMetrics('error', 1);
                });

                socket.on(`data:${data.connectionId}`, (data) => {
                    updateMetrics('bytesIn', data.length);
                    client.write(data);
                });

                socket.on(`end:${data.connectionId}`, () => {
                    client.end();
                });
            });

        } catch (error) {
            console.error('Failed to create tunnel:', error.response?.data || error.message);
            process.exit(1);
        }
    });

    // Handle errors
    socket.on('connect_error', (error) => {
        console.error('Connection error:', error.message);
        process.exit(1);
    });

    socket.on('error', (error) => {
        console.error('Socket error:', error);
    });

    // Handle disconnection
    socket.on('disconnect', (reason) => {
        if (reason === 'io server disconnect') {
            console.error('Disconnected by server');
            process.exit(1);
        }
        console.log('Disconnected:', reason);
    });
};

// Start the client
main().catch(error => {
    console.error('Unexpected error:', error);
    process.exit(1);
});
