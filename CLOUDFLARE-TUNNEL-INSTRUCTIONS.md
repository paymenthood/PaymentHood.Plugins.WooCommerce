# Cloudflare Tunnel Setup For Local WordPress

This file explains how to install Cloudflare Tunnel on Windows and run your local XAMPP WordPress site through a public URL.

## 1. Local Site Structure

Your local setup is assumed to be:

```text
XAMPP web root: C:\xampp\htdocs
WordPress root: C:\xampp\htdocs\wordpress
Local server: http://localhost
Local site: http://localhost/wordpress/
Local admin: http://localhost/wordpress/wp-admin/
```

## 2. Install Cloudflare Tunnel On Windows

Option 1: install with `winget`

```powershell
winget install --id Cloudflare.cloudflared
```

Option 2: install with Chocolatey

```powershell
choco install cloudflared
```

## 3. Verify Installation

Run:

```powershell
cloudflared --version
```

If installed correctly, you will see the `cloudflared` version.

## 4. Start XAMPP And WordPress

Before starting the tunnel, make sure:

1. Apache is running in XAMPP.
2. MySQL is running in XAMPP.
3. Your local site opens in the browser.

Check these URLs locally:

```text
http://localhost/wordpress/
http://localhost/wordpress/wp-admin/
```

## 5. Start The Cloudflare Tunnel

Open PowerShell and run:

```powershell
cd C:\xampp\htdocs
cloudflared tunnel --url http://localhost
```

Important:

- Use `http://localhost`
- Do not use `http://localhost/wordpress`

Using `http://localhost` keeps the `/wordpress` path working correctly in the public URL.

## 6. Copy The Public URL

After the command starts, Cloudflare prints a URL like this:

```text
https://example-name.trycloudflare.com
```

This is your public base URL.

## 7. Use The Correct Public URLs

If your public tunnel URL is:

```text
https://example-name.trycloudflare.com
```

Then your public WordPress URLs are:

```text
Site: https://example-name.trycloudflare.com/wordpress/
Admin: https://example-name.trycloudflare.com/wordpress/wp-admin/
Login: https://example-name.trycloudflare.com/wordpress/wp-login.php
Webhook: https://example-name.trycloudflare.com/wordpress/?wc-api=payment_webhook
```

## 8. Keep The Tunnel Running

Keep the PowerShell window open while using the public URL.

If you close the terminal, the tunnel stops.

## 9. Stop The Tunnel

To stop the tunnel:

1. Go to the PowerShell window running `cloudflared`.
2. Press `Ctrl+C`.

## 10. Full Command Sequence

Run these commands line by line:

```powershell
winget install --id Cloudflare.cloudflared
cloudflared --version
cd C:\xampp\htdocs
cloudflared tunnel --url http://localhost
```

## 11. Quick Test Checklist

After the tunnel starts:

1. Open `https://YOUR-TUNNEL.trycloudflare.com/wordpress/`
2. Open `https://YOUR-TUNNEL.trycloudflare.com/wordpress/wp-admin/`
3. Check that WordPress loads.
4. Check that the PaymentHood admin warning about localhost webhook reachability is gone when accessed through the public tunnel URL.

## 12. Common Mistakes

### Wrong tunnel target

Wrong:

```powershell
cloudflared tunnel --url http://localhost/wordpress
```

Correct:

```powershell
cloudflared tunnel --url http://localhost
```

### Tunnel closed

If the public URL stops working, check whether the `cloudflared` terminal window is still open.

### XAMPP not running

If the tunnel opens but the site fails, make sure Apache and MySQL are running.

## 13. One-Click Batch File Example

If you want a simple Windows batch file, create a file named `run-cloudflare-tunnel.bat` with this content:

```bat
@echo off
cd /d C:\xampp\htdocs
cloudflared tunnel --url http://localhost
pause
```

Then double-click the batch file to start the tunnel.

## 14. PowerShell Script Example

If you prefer PowerShell, create a file named `run-cloudflare-tunnel.ps1` with this content:

```powershell
Set-Location C:\xampp\htdocs
cloudflared tunnel --url http://localhost
```

Run it with:

```powershell
powershell -ExecutionPolicy Bypass -File .\run-cloudflare-tunnel.ps1
```