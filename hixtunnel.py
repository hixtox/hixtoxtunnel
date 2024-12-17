#!/usr/bin/env python3

import argparse
import sys
import requests
import asyncio
import websockets
import json
import signal
import os
import subprocess
import yaml
from rich.console import Console
from rich.panel import Panel
from rich.prompt import Prompt

console = Console()

CONFIG_DIR = os.path.expanduser("~/.hixtunnel")
CONFIG_FILE = os.path.join(CONFIG_DIR, "config.yaml")

def setup_client():
    """Setup the client by making it executable and creating a symlink"""
    try:
        # Create config directory if it doesn't exist
        if not os.path.exists(CONFIG_DIR):
            os.makedirs(CONFIG_DIR)
            
        # Get the absolute path of the script
        script_path = os.path.abspath(__file__)
        
        # Make the script executable
        os.chmod(script_path, 0o755)
        
        # Create symlink in /usr/local/bin
        symlink_path = "/usr/local/bin/hixtunnel"
        if os.path.exists(symlink_path):
            os.remove(symlink_path)
        os.symlink(script_path, symlink_path)
        
        # Create default config if it doesn't exist
        if not os.path.exists(CONFIG_FILE):
            default_config = {
                "server": "http://localhost:8080",  # Default to local development server
                "token": None
            }
            save_config(default_config)
        
        console.print("[green]HixTunnel client setup completed successfully![/green]")
        console.print("You can now use 'hixtunnel' command from anywhere.")
    except Exception as e:
        console.print(f"[red]Setup error: {str(e)}[/red]")
        console.print("[yellow]Try running with sudo if permission denied[/yellow]")

def save_config(config):
    """Save configuration to file"""
    try:
        with open(CONFIG_FILE, 'w') as f:
            yaml.dump(config, f)
        os.chmod(CONFIG_FILE, 0o600)  # Set secure file permissions
        return True
    except Exception as e:
        console.print(f"[red]Error saving config: {str(e)}[/red]")
        return False

def load_config():
    """Load configuration from file"""
    try:
        if os.path.exists(CONFIG_FILE):
            with open(CONFIG_FILE, 'r') as f:
                return yaml.safe_load(f)
    except Exception:
        pass
    return {"server": "http://localhost:8080", "token": None}

class HixTunnel:
    def __init__(self):
        self.config = load_config()
        self.server = self.config.get("server", "http://localhost:8080")
        self.token = self.config.get("token")
        self.ws = None
        self.running = True
        
        if not self.token:
            console.print("[red]No API token found. Please set your token first:[/red]")
            console.print("hixtunnel --token YOUR_API_TOKEN")
            sys.exit(1)

    async def create_tunnel(self, protocol, host, port):
        try:
            # Create tunnel request with token authentication
            headers = {"Authorization": f"Bearer {self.token}"}
            response = requests.post(
                f"{self.server}/api/tunnel/create",
                json={
                    "protocol": protocol.lower(),
                    "local_port": port,
                    "local_host": host
                },
                headers=headers
            )
            
            if response.status_code == 401:
                console.print("[red]Invalid or expired API token. Please update your token:[/red]")
                console.print("hixtunnel --token YOUR_API_TOKEN")
                return
            
            if response.status_code != 200:
                console.print(f"[red]Error: {response.json().get('error', 'Unknown error')}[/red]")
                return
            
            tunnel_data = response.json()
            tunnel_id = tunnel_data["tunnel_id"]
            remote_url = tunnel_data["url"]
            
            # Print tunnel URL with appropriate protocol
            protocol_prefix = "http://" if protocol.lower() == "http" else "tcp://"
            console.print(f"[green]Connected, tunneling to: {protocol_prefix}{remote_url}[/green]")
            
            # Handle WebSocket connection
            ws_url = f"ws{'s' if 'https' in self.server else ''}://{self.server.split('://')[-1]}/ws/tunnel/{tunnel_id}"
            
            async with websockets.connect(ws_url, extra_headers={"Authorization": f"Bearer {self.token}"}) as websocket:
                self.ws = websocket
                
                # Set up signal handlers
                signal.signal(signal.SIGINT, self.signal_handler)
                signal.signal(signal.SIGTERM, self.signal_handler)
                
                while self.running:
                    try:
                        message = await websocket.recv()
                        data = json.loads(message)
                        
                        if data.get("type") == "error":
                            console.print(f"[red]Error: {data.get('message')}[/red]")
                            break
                            
                    except websockets.exceptions.ConnectionClosed:
                        break
                    except Exception as e:
                        console.print(f"[red]Connection error: {str(e)}[/red]")
                        break
                
        except Exception as e:
            console.print(f"[red]Error: {str(e)}[/red]")
            sys.exit(1)

    def signal_handler(self, signum, frame):
        self.running = False
        if self.ws:
            asyncio.create_task(self.ws.close())
        console.print("\n[yellow]Shutting down tunnel...[/yellow]")
        sys.exit(0)

def main():
    parser = argparse.ArgumentParser(description="HixTunnel - Secure Tunneling Service")
    parser.add_argument("-P", "--protocol", choices=["HTTP", "TCP"],
                      help="Protocol to use (HTTP or TCP)")
    parser.add_argument("-H", "--host", default="localhost",
                      help="Local host to tunnel from (default: localhost)")
    parser.add_argument("-p", "--port", type=int,
                      help="Local port to tunnel from")
    parser.add_argument("-s", "--server",
                      help="Set HixTunnel server URL")
    parser.add_argument("--setup", action="store_true",
                      help="Setup the client (make executable and create symlink)")
    parser.add_argument("--token",
                      help="Set your API token")
    parser.add_argument("--show-config", action="store_true",
                      help="Display current configuration")

    args = parser.parse_args()

    # Handle setup
    if args.setup:
        setup_client()
        return

    # Handle configuration
    config = load_config()
    
    if args.server:
        config["server"] = args.server
        if save_config(config):
            console.print(f"[green]Server URL updated to: {args.server}[/green]")
        return

    if args.token:
        config["token"] = args.token
        if save_config(config):
            console.print("[green]API token saved successfully![/green]")
        return

    if args.show_config:
        console.print("Current configuration:")
        console.print(f"Server: {config.get('server', 'Not set')}")
        console.print(f"Token: {config.get('token', 'Not set')}")
        return

    # Check if we have all required arguments for tunnel creation
    if not all([args.protocol, args.port]):
        parser.print_help()
        return

    tunnel = HixTunnel()
    
    try:
        asyncio.run(tunnel.create_tunnel(args.protocol, args.host, args.port))
    except KeyboardInterrupt:
        console.print("\n[yellow]Tunnel closed by user[/yellow]")
        sys.exit(0)

if __name__ == "__main__":
    # If this is the first run, automatically setup the client
    if not os.access(__file__, os.X_OK):
        setup_client()
    main()
