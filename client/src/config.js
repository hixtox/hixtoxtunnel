const fs = require('fs');
const path = require('path');
const os = require('os');

const CONFIG_DIR = path.join(os.homedir(), '.hixtunnel');
const CONFIG_FILE = path.join(CONFIG_DIR, 'config.json');

// Ensure config directory exists
if (!fs.existsSync(CONFIG_DIR)) {
    fs.mkdirSync(CONFIG_DIR, { recursive: true });
}

function getStoredToken() {
    try {
        if (fs.existsSync(CONFIG_FILE)) {
            const config = JSON.parse(fs.readFileSync(CONFIG_FILE));
            return config.token;
        }
    } catch (error) {
        console.error('Error reading token:', error);
    }
    return null;
}

function storeToken(token) {
    try {
        fs.writeFileSync(CONFIG_FILE, JSON.stringify({ token }));
        return true;
    } catch (error) {
        console.error('Error storing token:', error);
        return false;
    }
}

module.exports = {
    getStoredToken,
    storeToken
};
