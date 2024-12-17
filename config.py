import os
from dotenv import load_dotenv

class Config:
    def __init__(self):
        load_dotenv()
        
        self.api_token = os.getenv('HIXTUNNEL_API_TOKEN')
        if not self.api_token:
            raise ValueError("HIXTUNNEL_API_TOKEN environment variable is not set")
        
        self.server_url = os.getenv('HIXTUNNEL_SERVER_URL', 'http://16.170.173.161')