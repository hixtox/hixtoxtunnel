#!/usr/bin/env python3
import click
import requests
import json
import os
import sys
import asyncio
import websockets
import signal
from config import Config
from pathlib import Path

def save_token(token):
    env_file = Path('.env')
    if env_file.exists():
        with open(env_file, 'r') as f:
            lines = f.readlines()
        
        token_set = False
        with open(env_file, 'w') as f:
            for line in lines:
                if line.startswith('HIXTUNNEL_API_TOKEN='):
                    f.write(f'HIXTUNNEL_API_TOKEN={token}\n')
                    token_set = True
                else:
                    f.write(line)
            if not token_set:
                f.write(f'HIXTUNNEL_API_TOKEN={token}\n')
    else:
        with open(env_file, 'w') as f:
            f.write(f'HIXTUNNEL_API_TOKEN={token}\n')

class TunnelClient:
    def __init__(self, api_token, server_url):
        self.api_token = api_token
        self.server_url = server_url.rstrip('/')
        self.active_tunnels = {}

    async def create_tunnel(self, protocol, local_host, local_port):
        headers = {
            'Authorization': f'Bearer {self.api_token}',
            'Content-Type': 'application/json'
        }
        
        data = {
            'protocol': protocol,
            'local_host': local_host,
            'local_port': local_port
        }
        
        response = requests.post(
            f'{self.server_url}/api/tunnel.php',
            headers=headers,
            json=data
        )
        
        if response.status_code != 200:
            raise Exception(f"Failed to create tunnel: {response.json().get('message', 'Unknown error')}")
        
        tunnel_data = response.json()
        tunnel_id = tunnel_data['tunnel_id']
        remote_port = tunnel_data['remote_port']
        
        click.echo(f"Tunnel created: {protocol}://{self.server_url}:{remote_port} -> {local_host}:{local_port}")
        
        if protocol == 'tcp':
            await self._handle_tcp_tunnel(local_host, local_port, remote_port)
        else:
            await self._handle_http_tunnel(local_host, local_port, remote_port)
        
        return tunnel_id

    async def _handle_tcp_tunnel(self, local_host, local_port, remote_port):
        async def forward(reader, writer):
            try:
                while True:
                    data = await reader.read(8192)
                    if not data:
                        break
                    writer.write(data)
                    await writer.drain()
            except Exception as e:
                click.echo(f"Error in TCP forwarding: {e}", err=True)
            finally:
                writer.close()
                await writer.wait_closed()

        server = await asyncio.start_server(
            lambda r, w: forward(r, w),
            local_host,
            local_port
        )
        
        async with server:
            await server.serve_forever()

    async def _handle_http_tunnel(self, local_host, local_port, remote_port):
        async def proxy_request(websocket):
            async for message in websocket:
                try:
                    data = json.loads(message)
                    async with aiohttp.ClientSession() as session:
                        async with session.request(
                            method=data['method'],
                            url=f"http://{local_host}:{local_port}{data['path']}",
                            headers=data['headers'],
                            data=data.get('body')
                        ) as response:
                            response_data = {
                                'status': response.status,
                                'headers': dict(response.headers),
                                'body': await response.text()
                            }
                            await websocket.send(json.dumps(response_data))
                except Exception as e:
                    click.echo(f"Error handling HTTP request: {e}", err=True)

        async with websockets.connect(
            f"ws://{self.server_url}:{remote_port}"
        ) as websocket:
            await proxy_request(websocket)

    def stop_tunnel(self, tunnel_id):
        headers = {
            'Authorization': f'Bearer {self.api_token}',
        }
        
        response = requests.delete(
            f'{self.server_url}/api/tunnel.php?id={tunnel_id}',
            headers=headers
        )
        
        if response.status_code != 200:
            raise Exception(f"Failed to stop tunnel: {response.json().get('message', 'Unknown error')}")
        
        click.echo(f"Tunnel {tunnel_id} stopped")

@click.group()
def cli():
    """HixTunnel Client - Expose your local services to the internet"""
    pass

@cli.command()
@click.option('--token', prompt='Enter your API token', help='Your HixTunnel API token')
def config(token):
    """Configure your API token"""
    try:
        save_token(token)
        click.echo(f"API token configured successfully!")
    except Exception as e:
        click.echo(f"Error saving token: {e}", err=True)
        sys.exit(1)

@cli.command()
@click.option('--protocol', type=click.Choice(['http', 'tcp']), required=True, help='Protocol to tunnel')
@click.option('--local-port', type=int, required=True, help='Local port to tunnel')
@click.option('--local-host', default='localhost', help='Local host to tunnel')
def start(protocol, local_port, local_host):
    """Start a new tunnel"""
    try:
        config = Config()
    except ValueError as e:
        click.echo("API token not configured. Please run 'python hixtunnel.py config' first.", err=True)
        sys.exit(1)

    client = TunnelClient(config.api_token, config.server_url)
    
    async def run():
        try:
            tunnel_id = await client.create_tunnel(protocol, local_host, local_port)
            click.echo(f"Tunnel {tunnel_id} is running. Press Ctrl+C to stop.")
            
            def signal_handler(sig, frame):
                client.stop_tunnel(tunnel_id)
                sys.exit(0)
            
            signal.signal(signal.SIGINT, signal_handler)
            signal.pause()
            
        except Exception as e:
            click.echo(f"Error: {e}", err=True)
            sys.exit(1)
    
    asyncio.run(run())

@cli.command()
@click.argument('tunnel-id', type=int)
def stop(tunnel_id):
    """Stop a running tunnel"""
    try:
        config = Config()
    except ValueError as e:
        click.echo("API token not configured. Please run 'python hixtunnel.py config' first.", err=True)
        sys.exit(1)

    client = TunnelClient(config.api_token, config.server_url)
    
    try:
        client.stop_tunnel(tunnel_id)
    except Exception as e:
        click.echo(f"Error: {e}", err=True)
        sys.exit(1)

if __name__ == '__main__':
    cli()