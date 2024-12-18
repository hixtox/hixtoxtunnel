#!/bin/bash

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${GREEN}Installing HixTunnel Server...${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo -e "${RED}Please run as root${NC}"
    exit 1
fi

# Function to check command status
check_status() {
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ $1 successful${NC}"
    else
        echo -e "${RED}✗ $1 failed${NC}"
        exit 1
    fi
}

# Function to check version requirements
check_version() {
    local cmd=$1
    local version=$2
    local current_version=$3
    
    if ! command -v $cmd &> /dev/null; then
        echo -e "${RED}$cmd is not installed${NC}"
        return 1
    fi
    
    if [ "$(printf '%s\n' "$version" "$current_version" | sort -V | head -n1)" != "$version" ]; then
        echo -e "${RED}$cmd version $current_version is less than required version $version${NC}"
        return 1
    fi
    
    echo -e "${GREEN}$cmd version $current_version meets requirements${NC}"
    return 0
}

# Function to kill processes using port 8080
cleanup_port() {
    echo "Cleaning up port 8080..."
    
    # Stop the service first if it exists
    systemctl stop hixtunnel 2>/dev/null
    
    # Find and kill any process using port 8080
    local pid=$(lsof -ti:8080)
    if [ ! -z "$pid" ]; then
        echo "Killing process(es) using port 8080: $pid"
        kill -9 $pid 2>/dev/null
    fi
    
    # Double check with netstat
    local netstat_pid=$(netstat -tlpn 2>/dev/null | grep ":8080" | awk '{print $7}' | cut -d'/' -f1)
    if [ ! -z "$netstat_pid" ]; then
        echo "Killing additional process(es): $netstat_pid"
        kill -9 $netstat_pid 2>/dev/null
    fi
}

# Create backup directory
BACKUP_DIR="/var/backups/hixtunnel/$(date +%Y%m%d_%H%M%S)"
mkdir -p $BACKUP_DIR

# Backup existing installation if any
if [ -d "/var/www/hixtunnel" ]; then
    echo "Backing up existing installation..."
    cp -r /var/www/hixtunnel/* $BACKUP_DIR/
    check_status "Backup"
fi

# Install system dependencies
echo "Installing system dependencies..."
apt-get update
check_status "System update"

apt-get install -y curl wget git unzip nginx mariadb-server php8.1-fpm php8.1-mysql php8.1-curl php8.1-json php8.1-mbstring redis
check_status "Dependencies installation"

# Install Node.js
echo "Installing Node.js..."
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs
check_status "Node.js installation"

# Check versions
node_version=$(node -v | cut -d'v' -f2)
php_version=$(php -v | head -n1 | cut -d' ' -f2 | cut -d'.' -f1,2)
check_version "node" "14.0.0" "$node_version"
check_version "php" "8.1" "$php_version"

# Setup Redis
echo "Configuring Redis..."
sed -i 's/# maxmemory <bytes>/maxmemory 512mb/' /etc/redis/redis.conf
sed -i 's/# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
systemctl restart redis
check_status "Redis configuration"

# Create log directory
echo "Creating log directory..."
mkdir -p /var/log/hixtunnel
chown -R www-data:www-data /var/log/hixtunnel
chmod 755 /var/log/hixtunnel
check_status "Log directory setup"

# Setup MySQL
echo "Setting up MySQL..."
mysql -e "CREATE DATABASE IF NOT EXISTS hixtunnel;"
mysql -e "CREATE USER IF NOT EXISTS 'hixtunnel'@'localhost' IDENTIFIED BY 'HixTunnel@123';"
mysql -e "GRANT ALL PRIVILEGES ON hixtunnel.* TO 'hixtunnel'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"
check_status "MySQL setup"

# Import database schema
echo "Importing database schema..."
mysql hixtunnel < database.sql
check_status "Database import"

# Copy files
echo "Installing HixTunnel files..."
mkdir -p /var/www/hixtunnel
cp -r * /var/www/hixtunnel/
chown -R www-data:www-data /var/www/hixtunnel
chmod -R 755 /var/www/hixtunnel
check_status "File installation"

# Setup systemd service
echo "Setting up systemd service..."
cp hixtunnel.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable hixtunnel
systemctl start hixtunnel
check_status "Service setup"

# Configure Nginx
echo "Configuring Nginx..."
cat > /etc/nginx/sites-available/hixtunnel << 'EOL'
server {
    listen 80;
    server_name _;
    root /var/www/hixtunnel;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }

    access_log /var/log/nginx/hixtunnel.access.log;
    error_log /var/log/nginx/hixtunnel.error.log;
}
EOL

ln -sf /etc/nginx/sites-available/hixtunnel /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl restart nginx
check_status "Nginx configuration"

echo -e "${GREEN}Installation complete!${NC}"
echo -e "You can now access HixTunnel at: ${YELLOW}http://YOUR_SERVER_IP${NC}"
echo -e "Default admin credentials:"
echo -e "Username: ${YELLOW}admin${NC}"
echo -e "Password: ${YELLOW}password${NC}"
echo -e "${RED}Please change the admin password immediately after logging in!${NC}"

# Create environment file from template
if [ -f ".env.example" ]; then
    cp .env.example .env
    check_status "Environment file creation"
fi
