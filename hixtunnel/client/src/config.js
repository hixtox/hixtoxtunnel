const fs = require('fs');
const os = require('os');
const path = require('path');

const CONFIG_DIR = path.join(os.homedir(), '.hixtunnel');
const TOKEN_FILE = path.join(CONFIG_DIR, 'token');
const SERVER_FILE = path.join(CONFIG_DIR, 'server');

const DEFAULT_SERVER = '16.170.173.161';

function ensureConfigDir() {
    if (!fs.existsSync(CONFIG_DIR)) {
        fs.mkdirSync(CONFIG_DIR, { recursive: true });
    }
}

function getStoredToken() {
    try {
        ensureConfigDir();
        if (fs.existsSync(TOKEN_FILE)) {
            return fs.readFileSync(TOKEN_FILE, 'utf8').trim();
        }
    } catch (error) {
        console.error('Error reading token:', error.message);
    }
    return null;
}

function storeToken(token) {
    try {
        ensureConfigDir();
        fs.writeFileSync(TOKEN_FILE, token);
        return true;
    } catch (error) {
        console.error('Error storing token:', error.message);
        return false;
    }
}

function getServerUrl() {
    try {
        ensureConfigDir();
        if (fs.existsSync(SERVER_FILE)) {
            const server = fs.readFileSync(SERVER_FILE, 'utf8').trim();
            // If server includes port, use it as is
            if (server.includes(':')) {
                return `http://${server}`;
            }
            // Otherwise add default port
            return `http://${server}:8080`;
        }
    } catch (error) {
        console.error('Error reading server configuration:', error.message);
    }
    return `http://${DEFAULT_SERVER}:8080`;
}

function getServerHost() {
    try {
        ensureConfigDir();
        if (fs.existsSync(SERVER_FILE)) {
            const server = fs.readFileSync(SERVER_FILE, 'utf8').trim();
            // Return just the host part if port is included
            return server.split(':')[0];
        }
    } catch (error) {
        console.error('Error reading server configuration:', error.message);
    }
    return DEFAULT_SERVER;
}

function storeServer(server) {
    try {
        ensureConfigDir();
        // Store server without port if it's the default port
        if (server.endsWith(':8080')) {
            server = server.replace(':8080', '');
        }
        fs.writeFileSync(SERVER_FILE, server);
        return true;
    } catch (error) {
        console.error('Error storing server configuration:', error.message);
        return false;
    }
}

module.exports = {
    getStoredToken,
    storeToken,
    getServerUrl,
    getServerHost,
    storeServer
};
