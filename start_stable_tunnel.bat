@echo off
title Food Dash - Stable Cloudflare Tunnel (HTTP/2)
echo ========================================================
echo  Food Dash: Starting Stable Cloudflare Tunnel
echo  Protocol: HTTP/2 (Forced TCP to prevent UDP/QUIC drops)
echo  Local Target: http://localhost
echo ========================================================
cd /d "%~dp0"
.\cloudflared.exe tunnel --protocol http2 --url http://localhost
pause
