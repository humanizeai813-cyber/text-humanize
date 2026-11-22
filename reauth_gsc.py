import os
from google_auth_oauthlib.flow import InstalledAppFlow
from platformdirs import user_config_dir

CLIENT_SECRETS = r"C:\Users\onlym\OneDrive\Documents\client_secrets.json"
SCOPES = ["https://www.googleapis.com/auth/webmasters"]

config_dir = user_config_dir("mcp-gsc")
os.makedirs(config_dir, exist_ok=True)
token_file = os.path.join(config_dir, "token.json")

print("=" * 60)
print("GOOGLE SEARCH CONSOLE MCP REAUTHENTICATION")
print("=" * 60)
print(f"Opening browser for Google OAuth authorization...")
print(f"Token will be saved to: {token_file}")
print("Please log in and click 'Allow' in your browser window.")
print("=" * 60)

flow = InstalledAppFlow.from_client_secrets_file(CLIENT_SECRETS, SCOPES)
creds = flow.run_local_server(port=0)

with open(token_file, "w") as f:
    f.write(creds.to_json())

print("\n" + "=" * 60)
print("SUCCESS: Google Search Console is now authorized and connected!")
print("=" * 60)
