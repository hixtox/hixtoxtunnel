const { io } = require('socket.io-client');
const axios = require('axios');
const yargs = require('yargs/yargs');
const { hideBin } = require('yargs/helpers');
const net = require('net');
const { getStoredToken, storeToken } = require('./config');
const { displayStatus, logRequest } = require('./utils');

const SERVER_URL = 'http://16.170.173.161:8080';

const argv = yargs(hideBin(process.argv))
    .command('auth', 'Authenticate with token', {
        token: {
            alias: 't',
            describe: 'Authentication token',
            demandOption: true
        }
    })
    .command('$0', 'Start tunnel', {
        'protocol': {
            alias: 'P',
            describe: 'Protocol (http/tcp)',
            default: 'http'
        },
        'port': {
            alias: 'p',
            describe: 'Local port to tunnel',
            demandOption: true
        },
        'host': {
            alias: 'H',
            describe: 'Local host to tunnel',
            default: 'localhost'
        }
    })
    .help()
    .argv;

if (argv._[0] === 'auth') {
    if (storeToken(argv.token)) {
        console.log('Token Updated Successfully, and ready to go.');
        process.exit(0);
    } else {
        console.error('Failed to store token');
        process.exit(1);
    }
}

const token = getStoredToken();
if (!token) {
    console.error('No token found. Please run "hixtunnel auth --token <your-token>" first');
    process.exit(1);
}

console.log('Connecting to tunnel server...');

const socket = io(SERVER_URL, {
    reconnection: true,
    reconnectionDelay: 1000,
    reconnectionDelayMax: 5000,
    reconnectionAttempts: 5
});

const tcpConnections = new Map();

socket.on('connect', () => {
    console.log('Connected to server, registering tunnel...');
    socket.emit('register', {
        host: argv.host,
        port: argv.port,
        token: token,
        protocol: argv.protocol
    });
});

socket.on('registered', async (data) => {
    try {
        const response = await axios.get(`${SERVER_URL}/api/user`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        const username = response.data.username;
        
        const localService = `${argv.protocol}://${argv.host}:${argv.port}`;
        displayStatus(username, localService, data.publicUrl);
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
            const response = await axios({
                method: request.method,
                url: `http://${argv.host}:${argv.port}${request.path}`,
                headers: request.headers,
                data: request.body,
                validateStatus: false
            });
            
            socket.emit(`response:${request.requestId}`, {
                status: response.status,
                headers: response.headers,
                body: response.data
            });

            logRequest(request.method, request.path, response.status);
        } catch (error) {
            console.error('Error forwarding request:', error);
            socket.emit(`response:${request.requestId}`, {
                status: 502,
                headers: { 'Content-Type': 'text/plain' },
                body: 'Bad Gateway'
            });
            logRequest(request.method, request.path, 502);
        }
    }
});

socket.on('tcp:connect', (data) => {
    const client = new net.Socket();
    
    client.connect(argv.port, argv.host, () => {
        console.log(`TCP connection established to local service: ${argv.host}:${argv.port}`);
        tcpConnections.set(data.tcpId, client);
    });

    client.on('data', (data) => {
        socket.emit('tcp:data', {
            tcpId: data.tcpId,
            data: data.toString('base64')
        });
    });

    client.on('end', () => {
        socket.emit('tcp:end', { tcpId: data.tcpId });
        tcpConnections.delete(data.tcpId);
    });

    client.on('error', (error) => {
        console.error(`TCP client error:`, error);
        socket.emit('tcp:error', { tcpId: data.tcpId, error: error.message });
        tcpConnections.delete(data.tcpId);
    });
});

socket.on('tcp:data', (data) => {
    const client = tcpConnections.get(data.tcpId);
    if (client) {
        client.write(Buffer.from(data.data, 'base64'));
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
