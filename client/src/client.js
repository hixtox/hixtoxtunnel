const { io } = require('socket.io-client');
const axios = require('axios');
const yargs = require('yargs');
const chalk = require('chalk');
const net = require('net');
const { getStoredToken, storeToken, getServerUrl, storeServer } = require('./config');
const { displayStatus, logRequest } = require('./utils');
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

const showBanner = () => {
    console.log(chalk.cyan('HIXTUNNEL') + ' by ' + chalk.yellow('{Hixtox Lab}') + '\n');
    console.log('Start tunnel:\n' + chalk.green('hixtunnel') + '\n');
    console.log('Commands:');
    console.log('    Authentication:');
    console.log('         ' + chalk.green('hixtunnel auth') + '  Authenticate with token' + '            ' + chalk.gray('ex:  hixtunnel auth  --token 48gf78sh87fdh...'));
    console.log('         ' + chalk.green('hixtunnel auth --server') + '  Server setup' + '                  ' + chalk.gray('ex: hixtunnel auth --server  hixtoxlab.com'));
    console.log('     Protocols:');
    console.log('         HTTP:');
    console.log('            ' + chalk.green('hixtunnel -P http -p 80'));
    console.log('         TCP:');
    console.log('            ' + chalk.green('hixtunnel -P TCP -p 22') + '\n');
};

const argv = yargs
    .command('auth', 'Authenticate with token', (yargs) => {
        return yargs
            .option('token', {
                describe: 'Authentication token',
                type: 'string'
            })
            .option('server', {
                describe: 'Server URL',
                type: 'string'
            });
    }, (argv) => {
        if (argv.server) {
            const serverUrl = argv.server.startsWith('http://') || argv.server.startsWith('https://') ? argv.server : `http://${argv.server}`;
            if (storeServer(serverUrl)) {
                console.log(chalk.green('Server URL set successfully'));
                process.exit(0);
            } else {
                console.error(chalk.red('Failed to store server URL'));
                process.exit(1);
            }
        }
        if (!argv.token) {
            console.error(chalk.red('Token is required'));
            process.exit(1);
        }
        if (storeToken(argv.token)) {
            console.log(chalk.green('Token updated successfully'));
            process.exit(0);
        } else {
            console.error(chalk.red('Failed to store token'));
            process.exit(1);
        }
    })
    .command('$0', 'Start tunnel', (yargs) => {
        return yargs
            .option('protocol', {
                alias: 'P',
                describe: 'Protocol (http/tcp)',
                default: 'http'
            })
            .option('port', {
                alias: 'p',
                describe: 'Local port to tunnel',
                required: true,
                type: 'number'
            })
            .option('host', {
                alias: 'H',
                describe: 'Local host to tunnel',
                default: 'localhost'
            });
    }, (argv) => {
        const token = getStoredToken();
        if (!token) {
            console.error(chalk.red('No token found. Please run "hixtunnel auth --token <your-token>" first'));
            process.exit(1);
        }

        const SERVER_URL = getServerUrl();
        console.log(`Connecting to tunnel server at ${SERVER_URL}...`);

        const socket = io(SERVER_URL, {
            reconnection: true,
            reconnectionDelay: 1000,
            reconnectionDelayMax: 5000,
            reconnectionAttempts: 5
        });

        const tcpConnections = new Map();

        socket.on('connect', () => {
            console.log('Connected to server, registering tunnel...');
            const randomPort = generateRandomPort();
            socket.emit('register', {
                host: argv.host,
                port: argv.port,
                token: token,
                protocol: argv.protocol,
                requestedPort: randomPort // Request a specific random port
            });
        });

        socket.on('registered', async (data) => {
            try {
                const response = await axios.get(`${SERVER_URL}/api/user`, {
                    headers: { 'Authorization': `Bearer ${token}` }
                });
                const username = response.data.username;
                
                displayStatus(
                    username,
                    argv.protocol,
                    argv.port,
                    argv.host,
                    data.publicPort
                );
            } catch (error) {
                console.error('Error getting user info:', error.message);
                process.exit(1);
            }
        });

        socket.on('connect_error', (error) => {
            console.error('Connection error:', error.message);
            console.log('Please check if the server is running and try again.');
        });

        socket.on('error', (error) => {
            console.error('Server error:', error.message);
            if (error.message === 'Invalid token') {
                console.log('Please check your token and try authenticating again:');
                console.log('hixtunnel auth --token YOUR_TOKEN');
            }
            process.exit(1);
        });

        socket.on('disconnect', (reason) => {
            console.log(`Disconnected from server: ${reason}`);
            if (reason === 'io server disconnect') {
                console.log('Server disconnected the client. Please check your token and try again.');
                process.exit(1);
            }
        });

        socket.on('request', async (request) => {
            if (argv.protocol === 'http') {
                try {
                    console.log(`Forwarding request: ${request.method} ${request.path}`);
                    
                    // Forward request to local service
                    const response = await axios({
                        method: request.method,
                        url: `http://${argv.host}:${argv.port}${request.path}`,
                        headers: request.headers,
                        data: request.body,
                        responseType: 'arraybuffer',
                        validateStatus: false,
                        maxRedirects: 0 // Prevent automatic redirects
                    });
                    
                    // Convert binary response to base64 if needed
                    let responseBody = response.data;
                    if (Buffer.isBuffer(responseBody)) {
                        const contentType = response.headers['content-type'];
                        if (contentType && !contentType.includes('text/') && !contentType.includes('application/json')) {
                            responseBody = responseBody.toString('base64');
                        } else {
                            responseBody = responseBody.toString('utf-8');
                        }
                    }

                    // Send response back to server
                    socket.emit(`response:${request.requestId}`, {
                        status: response.status,
                        headers: response.headers,
                        body: responseBody
                    });

                    logRequest(request.method, request.path, response.status);
                } catch (error) {
                    console.error('Error forwarding request:', error.message);
                    
                    // Send error response back to server
                    socket.emit(`response:${request.requestId}`, {
                        status: error.response?.status || 502,
                        headers: { 'Content-Type': 'text/plain' },
                        body: `Error: ${error.message}`
                    });

                    logRequest(request.method, request.path, error.response?.status || 502);
                }
            }
        });

        socket.on('tcp:connect', (data) => {
            const client = new net.Socket();
            
            client.connect(argv.port, argv.host, () => {
                console.log(`TCP connection established to local service: ${argv.host}:${argv.port}`);
                tcpConnections.set(data.tcpId, client);
            });

            client.on('data', (chunk) => {
                socket.emit('tcp:data', {
                    tcpId: data.tcpId,
                    data: chunk.toString('base64')
                });
            });

            client.on('end', () => {
                socket.emit('tcp:end', { tcpId: data.tcpId });
                tcpConnections.delete(data.tcpId);
                client.destroy();
            });

            client.on('error', (error) => {
                console.error(`TCP client error:`, error);
                socket.emit('tcp:error', { tcpId: data.tcpId, error: error.message });
                tcpConnections.delete(data.tcpId);
                client.destroy();
            });
        });

        socket.on('tcp:data', (data) => {
            const client = tcpConnections.get(data.tcpId);
            if (client) {
                try {
                    client.write(Buffer.from(data.data, 'base64'));
                } catch (error) {
                    console.error('Error writing to TCP socket:', error);
                    client.destroy();
                    tcpConnections.delete(data.tcpId);
                }
            }
        });

        socket.on('tcp:end', (data) => {
            const client = tcpConnections.get(data.tcpId);
            if (client) {
                client.end();
                tcpConnections.delete(data.tcpId);
            }
        });

        process.on('SIGINT', () => {
            console.log('\nShutting down tunnel...');
            for (const client of tcpConnections.values()) {
                client.destroy();
            }
            socket.disconnect();
            process.exit(0);
        });
    })
    .help()
    .version()
    .wrap(null);

// Show banner before parsing
showBanner();

// Parse arguments
yargs.parse();
