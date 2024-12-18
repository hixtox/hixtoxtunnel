# HixTunnel Installation Guide (Ubuntu)

## Prerequisites
- Ubuntu Server 20.04 LTS or newer
- Root access or sudo privileges
- Git installed (`apt install git`)

## Quick Installation

```bash
# 1. Clone the repository
git clone https://github.com/yourusername/hixtunnel.git
cd hixtunnel

# 2. Make scripts executable
chmod +x installserver.sh setup.sh

# 3. Run the installation script
sudo ./installserver.sh
```

## Manual Installation Steps

If you prefer to install manually or if the automatic installation fails, follow these steps:

### 1. System Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y curl wget git unzip nginx mariadb-server php8.1-fpm php8.1-mysql \
    php8.1-curl php8.1-json php8.1-mbstring redis

# Install Node.js 20.x
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### 2. Database Setup

```bash
# Secure MySQL installation
sudo mysql_secure_installation

# Create database and user
sudo mysql -e "CREATE DATABASE hixtunnel;"
sudo mysql -e "CREATE USER 'hixtunnel'@'localhost' IDENTIFIED BY 'YourSecurePassword';"
sudo mysql -e "GRANT ALL PRIVILEGES ON hixtunnel.* TO 'hixtunnel'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"

# Import database schema
sudo mysql hixtunnel < database.sql
```

### 3. Redis Configuration

```bash
# Configure Redis
sudo sed -i 's/# maxmemory <bytes>/maxmemory 512mb/' /etc/redis/redis.conf
sudo sed -i 's/# maxmemory-policy noeviction/maxmemory-policy allkeys-lru/' /etc/redis/redis.conf
sudo systemctl restart redis
```

### 4. Application Setup

```bash
# Create application directory
sudo mkdir -p /var/www/hixtunnel
sudo cp -r * /var/www/hixtunnel/

# Set permissions
sudo chown -R www-data:www-data /var/www/hixtunnel
sudo chmod -R 755 /var/www/hixtunnel

# Create logs directory
sudo mkdir -p /var/log/hixtunnel
sudo chown -R www-data:www-data /var/log/hixtunnel
sudo chmod 755 /var/log/hixtunnel
```

### 5. Environment Configuration

```bash
# Copy and edit environment file
sudo cp /var/www/hixtunnel/.env.example /var/www/hixtunnel/.env
sudo nano /var/www/hixtunnel/.env

# Update the following values:
# DB_HOST=localhost
# DB_USER=hixtunnel
# DB_PASSWORD=YourSecurePassword
# DB_NAME=hixtunnel
```

### 6. Nginx Configuration

```bash
# Create Nginx configuration
sudo nano /etc/nginx/sites-available/hixtunnel

# Add the following configuration:
server {
    listen 80;
    server_name your_domain.com;
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

# Enable the site
sudo ln -s /etc/nginx/sites-available/hixtunnel /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl restart nginx
```

### 7. Service Configuration

```bash
# Copy service file
sudo cp hixtunnel.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable hixtunnel
sudo systemctl start hixtunnel
```

## Post-Installation

1. Access the web interface at `http://your_domain.com`
2. Log in with default credentials:
   - Username: `admin`
   - Password: `password`
3. **IMPORTANT**: Change the admin password immediately!

## Verify Installation

```bash
# Check service status
sudo systemctl status hixtunnel

# Check logs
sudo tail -f /var/log/hixtunnel/service.log
sudo tail -f /var/log/hixtunnel/error.log

# Check Nginx logs
sudo tail -f /var/log/nginx/hixtunnel.access.log
sudo tail -f /var/log/nginx/hixtunnel.error.log
```

## Troubleshooting

1. **Service won't start:**
   - Check logs: `sudo journalctl -u hixtunnel -f`
   - Verify permissions: `sudo chown -R www-data:www-data /var/www/hixtunnel`

2. **Database connection issues:**
   - Verify MySQL service: `sudo systemctl status mysql`
   - Check credentials in `.env`

3. **Web interface not accessible:**
   - Check Nginx status: `sudo systemctl status nginx`
   - Verify firewall settings: `sudo ufw status`

4. **Redis connection issues:**
   - Check Redis service: `sudo systemctl status redis`
   - Verify Redis configuration: `redis-cli ping`

## Security Recommendations

1. Enable SSL/TLS with Let's Encrypt:
```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your_domain.com
```

2. Configure UFW firewall:
```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

3. Secure Redis:
```bash
sudo nano /etc/redis/redis.conf
# Set bind 127.0.0.1
# Set requirepass YourSecurePassword
sudo systemctl restart redis
```

4. Regular updates:
```bash
sudo apt update && sudo apt upgrade -y
```

## Maintenance

1. **Backup database:**
```bash
mysqldump -u hixtunnel -p hixtunnel > backup_$(date +%Y%m%d).sql
```

2. **Monitor disk space:**
```bash
df -h
du -sh /var/www/hixtunnel
du -sh /var/log/hixtunnel
```

3. **Log rotation:**
```bash
sudo nano /etc/logrotate.d/hixtunnel
# Add configuration:
/var/log/hixtunnel/*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    create 0640 www-data www-data
}
```

For additional support or bug reports, please visit our GitHub repository or contact support.
