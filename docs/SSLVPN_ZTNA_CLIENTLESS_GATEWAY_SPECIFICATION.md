# Micron Firewall OS (MicronOS) — SSL-VPN & Zero Trust Network Access (ZTNA) Specification

## Future Version Architecture & Engineering Specification
### Clientless Web Application Proxy, In-Browser RDP/SSH Gateway, and Identity-Aware Micro-Segmentation

---

## 1. Executive Summary & Architectural Vision

Modern enterprise perimeter defense is rapidly evolving beyond legacy Layer 3 Virtual Private Networks (VPNs). While MicronOS currently delivers high-performance Layer 3 tunneling via **In-Kernel WireGuard**, **OpenVPN 2.6 with Dynamic 2FA**, and **IPsec IKEv2**, network-level tunneling carries inherent risk: **once authenticated, a remote client's virtual interface (`wg0`, `tun0`) is bridged into internal subnets, allowing a compromised endpoint to perform reconnaissance, port scanning, and lateral movement.**

This specification details the upcoming **Clientless SSL-VPN & Zero Trust Network Access (ZTNA)** subsystem for MicronOS. Inspired by FortiGate SSL-VPN Web Mode, Cloudflare Access, and Google BeyondCorp, this feature enables remote operators and corporate employees to securely access internal applications **strictly through a standard web browser (Chrome, Firefox, Safari, Edge) without installing any client software, VPN profiles, or browser extensions.**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      REMOTE USER WEB BROWSER (HTTPS)                        │
│                   https://portal.micronos.network/apps/                     │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ TLS 1.3 (Port 443)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        MICRONOS APPLICATION GATEWAY                          │
│                                                                             │
│  ┌─────────────────────────┐  auth_request  ┌────────────────────────────┐  │
│  │   Nginx Front-End TLS   │ ─────────────> │  CodeIgniter 4 Auth Engine │  │
│  │   & Reverse Proxy Core  │ <───────────── │  (Session / LDAP / RADIUS) │  │
│  └────────────┬────────────┘     200 OK     └────────────────────────────┘  │
│               │                                                             │
│       ┌───────┴─────────────────────────────┐                               │
│       ▼                                     ▼                               │
│  [ Web Applications ]             [ HTML5 Remote Desktop / Terminal ]       │
│  Nginx proxy_pass upstream        WebSocket Tunnel (WSS)                    │
│  (HTTP / HTTPS)                             │                               │
│       │                                     ▼                               │
│       │                           guacd (Debian C Daemon)                   │
│       │                           libguac-client-rdp / ssh / vnc            │
└───────┼─────────────────────────────────────┼───────────────────────────────┘
        │                                     │
        │ Internal HTTP/HTTPS                 │ Native RDP (3389) / SSH (22)
        ▼                                     ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                     INTERNAL CORPORATE LAN / DMZ / SERVERS                  │
│                                                                             │
│  • Financial ERP (Port 8080)             • Windows Domain Controller (RDP)  │
│  • Internal Git & Wiki (Port 443)        • Linux Production Bastion (SSH)   │
│  • IP Cameras & PLC Web UIs              • SCADA Monitoring Terminals (VNC) │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 2. Comparison: Current Layer 3 VPN vs. Upcoming Layer 7 ZTNA

| Architectural Dimension | Current MicronOS VPN (v1.x) | Upcoming MicronOS ZTNA / SSL-VPN (v2.x) | FortiGate SSL-VPN Web Mode |
| :--- | :--- | :--- | :--- |
| **OSI Layer** | Layer 3 (Network Packet Routing) | **Layer 7 (Application Proxy & Gateway)** | Layer 7 (Application Proxy) |
| **Client Requirement** | WireGuard App, OpenVPN Client, or strongSwan | **Zero Client (Any HTML5 Web Browser)** | Zero Client (Web Browser) |
| **Network Exposure** | Full IP Subnet Access (e.g. `10.10.0.0/16`) | **Zero Subnet Access (App-specific socket only)** | App-specific socket only |
| **Lateral Movement Risk** | High (Compromised laptop can scan internal IPs) | **None (Endpoints cannot route packets)** | None |
| **Web Apps (HTTP/S)** | Routed through virtual tun/wg adapter | **Reverse Proxied with SSO header injection** | Reverse Proxied with URL rewrite |
| **Windows Desktop (RDP)** | Requires Microsoft Remote Desktop client | **Rendered directly in browser tab via HTML5 Canvas** | Rendered via Java or HTML5 |
| **Linux Terminal (SSH)** | Requires PuTTY / OpenSSH client | **Interactive xterm.js console in browser tab** | Interactive web terminal |
| **Authentication Flow** | Handshake on connect (Cert / TOTP) | **Continuous Per-Request Verification & SSO** | Per-Session Login Portal |
| **Device Posture Check** | Static Certificate check | **Dynamic Device Posture (IP, Cert, User-Agent)** | FortiClient EMS integration |

---

## 3. Layer 7 Reverse Proxy & Identity-Aware Web Proxy (IAP)

The ZTNA Web Application Gateway publishes internal web applications through secure external subpaths or dedicated domain names without requiring public IP addresses on the backend servers.

```
Incoming Request: https://portal.micronos.network/proxy/erp/dashboard
   │
   ▼
[ Nginx Reverse Proxy ]
   │
   ├─► 1. Subrequest to /api/ztna/verify-auth (CodeIgniter 4)
   │      - Validates session cookie or bearer token
   │      - Verifies User has permission: 'ztna.app.erp.access'
   │      - Returns HTTP 200 OK + Identity Headers
   │
   ├─► 2. Injects Enterprise Headers:
   │      X-Forwarded-For:       <Client IP>
   │      X-Forwarded-Proto:     https
   │      X-Remote-User:         john.doe
   │      X-Remote-Email:        john.doe@micronos.corp
   │      X-Remote-Groups:       Finance-Admins,Engineering
   │
   └─► 3. proxy_pass http://192.168.10.50:8080/dashboard
```

### 3.1 Nginx Inbound Configuration Architecture
The control plane compiler generates scoped server blocks in `/etc/nginx/sites-available/micron-ztna.conf`:

```nginx
# MicronOS Identity-Aware Reverse Proxy (Generated by ZtnaCompiler)
server {
    listen 443 ssl http2;
    server_name ztna.micronos.network;

    ssl_certificate     /etc/micron/pki/certs/gateway.crt;
    ssl_certificate_key /etc/micron/pki/private/gateway.key;
    ssl_protocols       TLSv1.2 TLSv1.3;

    # Internal Authentication Verification Subrequest
    location = /api/ztna/verify-auth {
        internal;
        proxy_pass http://127.0.0.1:80/api/ztna/verify;
        proxy_pass_request_body off;
        proxy_set_header Content-Length "";
        proxy_set_header X-Original-URI $request_uri;
        proxy_set_header X-Client-IP $remote_addr;
    }

    # Protected Application: Financial ERP
    location /proxy/erp/ {
        auth_request /api/ztna/verify-auth;
        auth_request_set $auth_user $upstream_http_x_remote_user;
        auth_request_set $auth_groups $upstream_http_x_remote_groups;

        proxy_pass http://192.168.10.50:8080/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Remote-User $auth_user;
        proxy_set_header X-Remote-Groups $auth_groups;
        proxy_read_timeout 300s;
    }
}
```

---

## 4. Clientless HTML5 Remote Desktop & Console Gateway (RDP, SSH, VNC)

For administrative and desktop protocols, MicronOS leverages the battle-tested, high-performance **Apache Guacamole native daemon (`guacd`)**.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          USER BROWSER TAB                                   │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │ HTML5 Canvas / xterm.js (Guacamole JavaScript Protocol Client)        │  │
│  └───────────────────────────────────┬───────────────────────────────────┘  │
└──────────────────────────────────────┼──────────────────────────────────────┘
                                       │ WebSocket (WSS)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                    MICRONOS WEBSOCKET PROXY (Port 443)                      │
│                    /api/ztna/tunnel?token=<SessionToken>                    │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Guacamole Instruction Protocol
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                  guacd (Debian C Daemon: 127.0.0.1:4822)                    │
│                                                                             │
│  ┌───────────────────────┐ ┌───────────────────────┐ ┌───────────────────┐  │
│  │ libguac-client-rdp    │ │ libguac-client-ssh    │ │ libguac-client-vnc│  │
│  │ (FreeRDP Core Engine) │ │ (libssh2 / libssh)    │ │ (LibVNCServer)    │  │
│  └───────────┬───────────┘ └───────────┬───────────┘ └─────────┬─────────┘  │
└──────────────┼─────────────────────────┼───────────────────────┼────────────┘
               │                         │                       │
               │ RDP (Port 3389)         │ SSH (Port 22)         │ VNC (5900)
               ▼                         ▼                       ▼
      [ Windows Server ]         [ Linux Firewall Host ]   [ SCADA PLC Screen ]
```

### 4.1 Key Security Advantages of `guacd`
1. **Zero Native Ports on WAN:** Ports 3389 (RDP) and 22 (SSH) are never reachable from the internet. All traffic passes strictly over HTTPS (443).
2. **Credential Cloaking (Vault Integration):** The firewall injects domain service account credentials into the RDP handshake on the fly. The remote employee does not need to know the administrative Windows password.
3. **Session Keystroke Logging & Video Auditing:** The gateway records screen activity directly into encrypted MPEG-4 / Guacamole session log streams stored in `/var/log/micron/ztna/sessions/` for compliance and forensics.
4. **Clipboard & File Transfer Isolation:** Bi-directional clipboard sharing and file upload/download can be selectively permitted or strictly disabled per user group to prevent data exfiltration.

---

## 5. Control Plane & Data Plane Decoupling (`micrond` Integration)

In adherence to MicronOS architectural tenets:
1. **The Web Management Interface never touches daemons directly.**
2. **All configuration changes are staged in SQLite/MariaDB with transactional rollback.**
3. **`micrond` system control daemon validates and enforces system state.**

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         WEB MANAGEMENT PLANE (PHP / CI4)                    │
│  - System Administrator configures ZTNA Application in GUI (/ztna)          │
│  - Sets Name: "Core DC01 RDP", Target: 10.0.0.10:3389, Role: "SecurityAdmin"│
│  - Writes to SQLite staged candidate table: `ztna_applications`             │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Staged Candidate Commit
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        TRANSACTIONAL COMMIT WATCHDOG                        │
│  - Validates syntax and port allocations                                    │
│  - Sends IPC JSON-RPC request to /run/micrond.sock                          │
└──────────────────────────────────────┬──────────────────────────────────────┘
                                       │ Unix Domain Socket IPC (JSON-RPC)
                                       ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        ROOT CONTROL DAEMON (micrond)                        │
│                                                                             │
│  1. Invokes ZtnaCompiler:                                                   │
│     - Renders /etc/nginx/sites-available/micron-ztna.conf                   │
│     - Updates /etc/guacamole/guacd.conf connection ACLs                     │
│  2. Tests Nginx syntax: `nginx -t -c /etc/nginx/nginx.conf`                 │
│  3. Performs atomic reload: `systemctl reload nginx`                        │
│  4. Manages guacd service:  `systemctl restart guacd`                       │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 6. Database Schema Design (SQLite 3 & MariaDB)

### 6.1 Applications Table (`ztna_applications`)
```sql
CREATE TABLE ztna_applications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    app_uuid VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(128) NOT NULL,
    category VARCHAR(64) DEFAULT 'General',     -- 'Web', 'Infrastructure', 'Databases'
    app_type VARCHAR(16) NOT NULL,              -- 'web', 'rdp', 'ssh', 'vnc'
    url_slug VARCHAR(64) UNIQUE NOT NULL,       -- e.g. 'finance-erp', 'dc01-rdp'
    internal_host VARCHAR(255) NOT NULL,        -- IP or internal FQDN
    internal_port INTEGER NOT NULL,             -- 80, 443, 3389, 22, 5900
    ssl_verify BOOLEAN DEFAULT 0,               -- Verify backend TLS certificate
    sso_enabled BOOLEAN DEFAULT 0,              -- Pass authenticated user identity headers
    clipboard_mode VARCHAR(16) DEFAULT 'both',  -- 'disabled', 'read', 'write', 'both'
    file_transfer BOOLEAN DEFAULT 0,            -- Enable Guacamole virtual SFTP drive
    screen_recording BOOLEAN DEFAULT 0,         -- Record session for security audit
    icon VARCHAR(64) DEFAULT 'server',          -- UI display icon
    enabled BOOLEAN DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 6.2 Access Policies Table (`ztna_policies`)
```sql
CREATE TABLE ztna_policies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    policy_name VARCHAR(128) NOT NULL,
    app_id INTEGER NOT NULL REFERENCES ztna_applications(id) ON DELETE CASCADE,
    role_id INTEGER NULL REFERENCES roles(id) ON DELETE SET NULL,
    external_group VARCHAR(128) NULL,           -- Active Directory / LDAP Group
    require_mfa BOOLEAN DEFAULT 1,              -- Enforce TOTP / 2FA verification
    allowed_source_ips TEXT DEFAULT '0.0.0.0/0',-- CIDR restrictions
    schedule_id INTEGER NULL,                   -- Optional time-based access window
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### 6.3 Active Sessions Table (`ztna_sessions`)
```sql
CREATE TABLE ztna_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_token VARCHAR(128) UNIQUE NOT NULL,
    user_id INTEGER NOT NULL REFERENCES users(id),
    app_id INTEGER NOT NULL REFERENCES ztna_applications(id),
    client_ip VARCHAR(64) NOT NULL,
    user_agent TEXT,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
    bytes_transmitted BIGINT DEFAULT 0,
    is_active BOOLEAN DEFAULT 1
);
```

---

## 7. Complete Debian 13 (Trixie) Package Selection Manifest

To bake this capability into the upcoming release, the following packages must be included in `config/package-lists/micronos.list.chroot`:

```ini
# ============================================================
# Section: ZTNA & Clientless Gateway Daemons (New in v2.0)
# ============================================================

# 1. Reverse Proxy & WebSocket Core
nginx                       # Full Nginx replacing nginx-light (provides ngx_http_auth_request_module)

# 2. Apache Guacamole Native Proxy Daemon
guacd                       # Native C protocol translation daemon
libguac-client-rdp0         # High-speed FreeRDP client plugin
libguac-client-ssh0         # SSH terminal client plugin
libguac-client-vnc0         # VNC client plugin
libfreerdp2-2               # RDP protocol implementation
libssh2-1                   # SSH2 transport library
libvncclient1               # VNC protocol library

# 3. Audio & Video Codec Acceleration (for RDP Screen Encoding)
libwebp7                    # WebP high-compression tile rendering
libjpeg62-turbo             # Fast JPEG encoding for active desktop frames
libpng16-16                 # Lossless PNG image encoding
libvorbis0a                 # Ogg Vorbis audio streaming
libpulse0                   # PulseAudio bridge for Windows sound forwarding
```

---

## 8. Hardware Sizing & Performance Benchmarks

Unlike kernel-space WireGuard which routes packets with negligible memory consumption (< 10 MB RAM), **clientless RDP transcoding decodes remote framebuffers and compresses graphical tiles into HTML5 streams.** Hardware must be sized accordingly:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    RESOURCE PROFILE BY REMOTE WORKLOAD                      │
│                                                                             │
│  • WireGuard Tunnel (L3) : ~0.05 MB RAM per peer  | Wire-speed CPU (Kernel)│
│  • Reverse Proxy (Web App): ~0.20 MB RAM per user  | < 0.1% CPU (Async IO)  │
│  • SSH Console (xterm.js): ~2.50 MB RAM per user  | < 0.5% CPU (Text stream)│
│  • RDP Desktop (guacd)   : ~25-45 MB RAM per user | 3-8% CPU Core (Video)   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Hardware Sizing Guidelines
| Deployment Tier | Appliance Specifications | Max Concurrent WireGuard | Max Concurrent Web Apps | Max Concurrent Browser RDP |
| :--- | :--- | :--- | :--- | :--- |
| **SOHO / Branch** | 4-Core Celeron / Atom, 4 GB RAM | 250 peers | 500 sessions | 10–15 sessions |
| **Mid-Market Enterprise** | 8-Core Intel Core / Xeon, 16 GB RAM | 2,500 peers | 5,000 sessions | 50–75 sessions |
| **Data Center / HQ** | 16-Core AMD EPYC / Xeon, 32 GB RAM | 10,000+ peers | 25,000+ sessions | 200–300 sessions |

---

## 9. Security Hardening & Zero Trust Policy Compliance

1. **Strict Transport Security (HSTS):** Preloaded HSTS with subdomains to prevent downgrade attacks.
2. **WebSocket Origin Validation:** All WebSocket handshakes validate `Origin` and `Host` headers to eliminate Cross-Site WebSocket Hijacking (CSWSH).
3. **Session Expiry & Re-Authentication:** Active sessions automatically lock after configurable inactivity (default: 15 minutes). Sensitive applications enforce re-prompt of TOTP / password.
4. **Kill-Switch IPC Endpoint:** Administrators can terminate any active RDP, SSH, or Web App session in real-time directly from the Live States Inspector (`/diag/states`) via `micrond` JSON-RPC `ztna.session.kill`.
5. **Separation of Duties:** Administrators managing firewall packet rules (`SecurityAdmin`) can be isolated from ZTNA application publishers (`AppAdmin`) through MicronOS's fine-grained RBAC model.

---

## 10. Summary & Implementation Roadmap

| Milestone | Phase Focus | Key Deliverables |
| :--- | :--- | :--- |
| **Phase 1** | **Packaging & IPC** | Add `guacd` + `nginx` to live-build ISO; register `micrond` ZTNA service handlers. |
| **Phase 2** | **Database & Compiler** | Run SQLite migrations (`ztna_applications`, `ztna_policies`); create `ZtnaCompiler.php`. |
| **Phase 3** | **Identity-Aware Web Proxy** | Build `/api/ztna/verify` authentication endpoint; enable reverse proxy SSO injection. |
| **Phase 4** | **HTML5 RDP & SSH Gateway** | Integrate Guacamole WebSocket bridge; build web-based desktop canvas. |
| **Phase 5** | **User Portal UI** | Modern tile-based launcher dashboard in `/vpn/portal` with live session management. |
