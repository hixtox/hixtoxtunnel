#!/bin/bash

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

echo -e "${GREEN}Setting up HixTunnel...${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run as root${NC}"
    exit 1
fi

# Get the actual user who called sudo
REAL_USER=${SUDO_USER:-$USER}

# Fix permissions for all files
chmod 755 installclient.sh setup.sh
chmod -R 755 client

# Fix ownership
chown -R $REAL_USER:$REAL_USER .

echo -e "${GREEN}Setup complete!${NC}"
echo "Now you can run:"
echo "2. sudo ./installclient.sh  (to install the client)"
