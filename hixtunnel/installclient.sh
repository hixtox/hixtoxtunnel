#!/bin/bash

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo -e "${GREEN}Installing HixTunnel Client...${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run as root${NC}"
    exit 1
fi

# Get the actual user who called sudo
REAL_USER=${SUDO_USER:-$USER}
REAL_HOME=$(getent passwd $REAL_USER | cut -d: -f6)

# Check if Node.js is installed
if ! command -v node &> /dev/null; then
    echo "Installing Node.js..."
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

# Create directory structure
mkdir -p $REAL_HOME/.hixtunnel/client/{src,bin}
mkdir -p $REAL_HOME/.hixtunnel/config

# Copy source files
cp -r ./client/src/* $REAL_HOME/.hixtunnel/client/src/

# Create package.json
cat > $REAL_HOME/.hixtunnel/client/package.json << 'EOL'
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
cd $REAL_HOME/.hixtunnel/client
npm install

# Create the client launcher script
cat > $REAL_HOME/.hixtunnel/client/hixtunnel.sh << EOL
#!/bin/bash
exec node "$REAL_HOME/.hixtunnel/client/src/client.js" "\$@"
EOL

chmod +x $REAL_HOME/.hixtunnel/client/hixtunnel.sh

# Create symlink in /usr/local/bin
ln -sf $REAL_HOME/.hixtunnel/client/hixtunnel.sh /usr/local/bin/hixtunnel

# Set proper permissions
chown -R $REAL_USER:$REAL_USER $REAL_HOME/.hixtunnel
chmod -R 755 $REAL_HOME/.hixtunnel/client
chmod 700 $REAL_HOME/.hixtunnel/config

# Verify permissions
ls -la /usr/local/bin/hixtunnel
ls -la $REAL_HOME/.hixtunnel/client/hixtunnel.sh

echo -e "${GREEN}HixTunnel Client installation complete!${NC}"
echo "You can now use the 'hixtunnel' command."
echo "First, authenticate with: hixtunnel auth --token YOUR_TOKEN"
echo "Then start tunneling with: hixtunnel -P http -p PORT"
