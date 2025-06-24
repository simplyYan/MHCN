import subprocess
import requests
import time
import base64
import re
import os
import signal

GITHUB_TOKEN = "<TOKEN>"
GITHUB_REPO = "simplyYan/MHCN"
GITHUB_FILE_PATH = "server/config.mhcn"
GITHUB_BRANCH = "main"
METRICS_URL = "http://127.0.0.1:45678/metrics"

def get_cloudflared_url():
    for _ in range(20):  
        try:
            res = requests.get(METRICS_URL, timeout=2)
            if res.status_code == 200:
                match = re.search(r"https://[a-zA-Z0-9\-]+\.trycloudflare\.com", res.text)
                if match:
                    return match.group(0)
        except Exception:
            pass
        time.sleep(1)
    return None

def update_github_file(url):
    b64_url = base64.b64encode(url.encode()).decode()
    headers = {
        "Authorization": f"token {GITHUB_TOKEN}",
        "Accept": "application/vnd.github.v3+json"
    }
    get_url = f"https://api.github.com/repos/{GITHUB_REPO}/contents/{GITHUB_FILE_PATH}"
    r = requests.get(get_url, headers=headers)
    if r.status_code != 200:
        print("Failed to get file SHA:", r.json())
        return

    sha = r.json()["sha"]
    data = {
        "message": "Updating Cloudflare tunnel URL",
        "content": b64_url,
        "sha": sha,
        "branch": GITHUB_BRANCH
    }
    r = requests.put(get_url, headers=headers, json=data)
    if r.status_code == 200:
        print("GitHub file updated successfully!")
    else:
        print("Failed to update GitHub file:", r.json())

def is_tunnel_alive(url):
    try:
        res = requests.get(url, timeout=5)
        if res.status_code == 200 or res.status_code == 404:
            return True  
    except Exception:
        pass
    return False

while True:
    print("Starting new tunnel...")
    proc = subprocess.Popen(
        ["cloudflared", "tunnel", "--url", "http://localhost:8080", "--metrics", "127.0.0.1:45678"],
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL
    )

    print("Waiting for tunnel URL from metrics endpoint...")
    url = get_cloudflared_url()
    if not url:
        print("URL not found. Restarting tunnel...")
        proc.terminate()
        time.sleep(5)
        continue

    print("Tunnel URL obtained:", url)
    update_github_file(url)

    while True:
        if proc.poll() is not None:
            print("Tunnel process has exited unexpectedly.")
            break

        if not is_tunnel_alive(url):
            print(f"Tunnel URL {url} seems to be down. Restarting tunnel...")

            try:
                proc.terminate()
                time.sleep(2)
                if proc.poll() is None:
                    proc.kill()
            except Exception as e:
                print(f"Error terminating process: {e}")
            break

        time.sleep(10)

    print("Tunnel will restart in 3 seconds...\n")
    time.sleep(3)
