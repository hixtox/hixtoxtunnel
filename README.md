# HixTunnel Client

A powerful CLI tool for creating and managing secure tunnels with HixTunnel server.

## Features

- Create TCP and HTTP tunnels
- Real-time tunnel monitoring
- Automatic reconnection
- Secure authentication
- Traffic statistics
- Easy configuration

## Installation

1. Install the required dependencies:
```bash
pip install -r requirements.txt
```

2. Make the script executable:
```bash
chmod +x hixtunnel.py
```

3. (Optional) Create a symlink for global access:
```bash
sudo ln -s $(pwd)/hixtunnel.py /usr/local/bin/hixtunnel
```

## Usage

### Authentication

Login to your HixTunnel server:
```bash
./hixtunnel.py login
```

### Creating Tunnels

Create a TCP tunnel:
```bash
./hixtunnel.py tunnel 8080
```

Create a tunnel with specific remote port:
```bash
./hixtunnel.py tunnel 8080 --remote-port 80
```

Create an HTTP tunnel:
```bash
./hixtunnel.py tunnel 8080 --type http
```

### Managing Tunnels

List all active tunnels:
```bash
./hixtunnel.py list
```

Stop a specific tunnel:
```bash
./hixtunnel.py stop <tunnel_id>
```

### Configuration

Configure client settings:
```bash
./hixtunnel.py config
```

## Configuration Options

- `server_url`: HixTunnel server URL
- `auto_reconnect`: Automatically reconnect on connection loss
- `connection_timeout`: Connection timeout in seconds
- `log_level`: Logging level (debug, info, warning, error)

Configuration is stored in `~/.hixtunnel/config.yaml`

## Security

- All communications are encrypted
- Authentication tokens are stored securely
- Automatic session management
- Secure credential handling

## Troubleshooting

If you encounter any issues:

1. Check your connection to the HixTunnel server
2. Verify your authentication status
3. Ensure the local port is available
4. Check the server logs for any errors
5. Verify your firewall settings

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.
