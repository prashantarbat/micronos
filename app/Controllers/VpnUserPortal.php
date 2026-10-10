<?php

namespace App\Controllers;

use App\Models\PkiCertificateModel;
use App\Models\PkiAuthorityModel;
use App\Libraries\IpcClient;

class VpnUserPortal extends BaseController
{
    protected PkiCertificateModel $certModel;
    protected PkiAuthorityModel $caModel;
    protected IpcClient $ipc;
    protected $db;

    public function __construct()
    {
        $this->db        = \Config\Database::connect();
        $this->certModel = new PkiCertificateModel();
        $this->caModel   = new PkiAuthorityModel();
        $this->ipc       = \Config\Services::ipc();
    }

    public function index()
    {
        $ovpnServers = $this->db->table('openvpn_servers')->get()->getResultArray();
        $wgTunnels   = $this->db->table('wireguard_tunnels')->get()->getResultArray();
        $userCerts   = $this->certModel->where('cert_type', 'client')->orWhere('cert_type', 'user')->findAll();

        $currentUser = session()->get('user') ?? [
            'username' => 'admin',
            'email'    => 'admin@micronos.local',
        ];

        $data = [
            'title'       => 'VPN Road Warrior Self-Service Portal',
            'ovpnServers' => $ovpnServers,
            'wgTunnels'   => $wgTunnels,
            'userCerts'   => $userCerts,
            'user'        => $currentUser,
        ];

        return view('vpn/portal', $data);
    }

    public function downloadOvpn($serverId = null)
    {
        $serverId = $serverId ?: $this->request->getGet('server');
        $query = $this->db->table('openvpn_servers');
        $server = $serverId ? $query->where('id', $serverId)->get()->getRowArray() : $query->get()->getRowArray();
        $ca     = $this->caModel->first();
        $cert   = $this->certModel->where('cert_type', 'client')->first();

        $gwHost = $this->request->getServer('SERVER_NAME') ?: 'vpn.micronos.corp';
        $port   = !empty($server['listen_port']) ? $server['listen_port'] : (!empty($server['port']) ? $server['port'] : 1194);
        $proto  = !empty($server['protocol']) ? strtolower($server['protocol']) : (!empty($server['proto']) ? strtolower($server['proto']) : 'udp');

        $caText   = $ca ? trim($ca['certificate']) : '# Default CA';
        $certText = $cert ? trim($cert['certificate']) : '# Client Cert';
        $keyText  = $cert ? trim($cert['private_key']) : '# Client Key';

        $ovpnConfig = <<<OVPN
client
dev tun
proto {$proto}
remote {$gwHost} {$port}
resolv-retry infinite
nobind
persist-key
persist-tun
remote-cert-tls server
cipher AES-256-GCM
auth SHA256
verb 3
mute-replay-warnings

<ca>
{$caText}
</ca>

<cert>
{$certText}
</cert>

<key>
{$keyText}
</key>
OVPN;

        $filename = "micronos_roadwarrior_{$proto}_{$port}.ovpn";
        return $this->response->setHeader('Content-Type', 'application/x-openvpn-profile')
                              ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
                              ->setBody($ovpnConfig);
    }

    public function downloadWireguard($tunnelId = null)
    {
        $tunnelId = $tunnelId ?: $this->request->getGet('tunnel');
        $query = $this->db->table('wireguard_tunnels');
        $tunnel = $tunnelId ? $query->where('id', $tunnelId)->get()->getRowArray() : $query->get()->getRowArray();
        $gwHost = $this->request->getServer('SERVER_NAME') ?: 'vpn.micronos.corp';
        $port   = !empty($tunnel['listen_port']) ? $tunnel['listen_port'] : 51820;

        // Generate client WireGuard credentials
        $clientPrivateKey = base64_encode(random_bytes(32));
        $serverPublicKey  = !empty($tunnel['public_key']) ? $tunnel['public_key'] : base64_encode(random_bytes(32));

        $wgConfig = <<<WG
[Interface]
PrivateKey = {$clientPrivateKey}
Address = 10.100.0.10/24
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = {$serverPublicKey}
Endpoint = {$gwHost}:{$port}
AllowedIPs = 0.0.0.0/0, ::/0
PersistentKeepalive = 25
WG;

        $filename = "micronos_wireguard.conf";
        return $this->response->setHeader('Content-Type', 'text/plain')
                              ->setHeader('Content-Disposition', "attachment; filename=\"{$filename}\"")
                              ->setBody($wgConfig);
    }
}
