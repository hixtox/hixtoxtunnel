#!/bin/bash

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo -e "${GREEN}Installing HixTunnel Client...${NC}"

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "Installing Node.js..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | sudo bash -
    sudo apt-get install -y nodejs
fi

# Create directory structure
mkdir -p ~/.hixtunnel/client/{src,bin}

# Copy source files
cp -r ./client/src/* ~/.hixtunnel/client/src/

# Create package.json
cat > ~/.hixtunnel/client/package.json << 'EOL'
{
  "name": "hixtunnel-client",
  "version": "1.0.0",
  "description": "HixTunnel Client",
  "main": "src/client.js",
  "bin": {
    "hixtunnel": "./bin/hixtunnel"
  },
  "dependencies": {
    "socket.io-client": "^4.7.2",
    "axios": "^1.6.2",
    "yargs": "^17.7.2",
    "dotenv": "^16.3.1"
  }
}
EOL

# Install dependencies
cd ~/.hixtunnel/client
npm install

# Create hixtunnel binary
cat > ~/.hixtunnel/client/bin/hixtunnel << 'EOL'
#!/usr/bin/env node
require('../src/client.js');
EOL

# Make binary executable
chmod +x ~/.hixtunnel/client/bin/hixtunnel

# Install globally with proper permissions
sudo npm link
sudo chmod 755 /usr/local/bin/hixtunnel

# Create config directory with proper permissions
mkdir -p ~/.hixtunnel/config
chmod 700 ~/.hixtunnel/config

echo -e "${GREEN}HixTunnel Client installation complete!${NC}"
echo "You can now use the 'hixtunnel' command."
echo "First, authenticate with: hixtunnel auth --token YOUR_TOKEN"
echo "Then start tunneling with: hixtunnel -P http -p PORT"
