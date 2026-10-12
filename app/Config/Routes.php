<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

// ============================================================
// Public Routes (no auth required)
// ============================================================
$routes->get('/', 'Auth::login');
$routes->get('auth/login', 'Auth::login', ['as' => 'auth.login']);
$routes->post('auth/login', 'Auth::loginPost');
$routes->get('auth/logout', 'Auth::logout', ['as' => 'auth.logout']);

// TOTP 2FA challenge (shown after password but before session creation)
$routes->get('auth/totp', 'Auth::totp', ['as' => 'auth.totp']);
$routes->post('auth/totp', 'Auth::totpPost');

// Centralized Single Sign-On (SSO) Federation Endpoints
$routes->get('auth/sso/login/(:segment)', 'Sso::login/$1');
$routes->get('auth/sso/callback/(:segment)', 'Sso::callback/$1');
$routes->post('auth/sso/saml/acs/(:segment)', 'Sso::acs/$1');
$routes->get('auth/sso/metadata/(:segment)', 'Sso::metadata/$1');

// ============================================================
// Protected Routes (auth filter applied globally in Filters.php)
// ============================================================

// Dashboard
$routes->get('dashboard', 'Dashboard::index', ['as' => 'dashboard', 'filter' => 'permission:dashboard.view']);
$routes->get('dashboard/traffic', 'Dashboard::traffic', ['filter' => 'permission:dashboard.view']);
$routes->get('dashboard/telemetry', 'Dashboard::telemetry', ['filter' => 'permission:dashboard.view']);
$routes->get('dashboard/layout', 'Dashboard::layout', ['filter' => 'permission:dashboard.view']);
$routes->post('dashboard/layout/save', 'Dashboard::saveLayout', ['filter' => 'permission:dashboard.view']);
$routes->post('dashboard/layout/reset', 'Dashboard::resetLayout', ['filter' => 'permission:dashboard.view']);
$routes->post('dashboard/port-action', 'Dashboard::portAction', ['filter' => 'permission:dashboard.view']);
$routes->post('dashboard/service-toggle', 'Dashboard::serviceToggle', ['filter' => 'permission:dashboard.view']);

// ---- Interfaces ----
$routes->group('interfaces', ['filter' => 'permission:interfaces.view'], function ($r) {
    $r->get('/', 'Interfaces::index');
    $r->post('store', 'Interfaces::store', ['filter' => 'permission:interfaces.edit']);
    $r->get('edit/(:segment)', 'Interfaces::edit/$1', ['filter' => 'permission:interfaces.edit']);
    $r->post('save', 'Interfaces::save', ['filter' => 'permission:interfaces.edit']);
    $r->post('(:segment)/update', 'Interfaces::save', ['filter' => 'permission:interfaces.edit']);
    $r->post('(:segment)/toggle', 'Interfaces::toggle/$1', ['filter' => 'permission:interfaces.edit']);
    $r->post('(:segment)/delete', 'Interfaces::delete/$1', ['filter' => 'permission:interfaces.edit']);
    $r->post('apply', 'Interfaces::apply', ['filter' => 'permission:interfaces.apply']);

    // Link Aggregation / Bonding (bond0 LACP)
    $r->post('bonds/store', 'Interfaces::storeBond', ['filter' => 'permission:interfaces.edit']);
    $r->post('bonds/(:segment)/delete', 'Interfaces::deleteBond/$1', ['filter' => 'permission:interfaces.edit']);

    // Layer 2 Bridge Interfaces (br0, br-lan)
    $r->post('bridges/store', 'Interfaces::storeBridge', ['filter' => 'permission:interfaces.edit']);
    $r->post('bridges/(:segment)/delete', 'Interfaces::deleteBridge/$1', ['filter' => 'permission:interfaces.edit']);

    // Wireless LAN (wlan0 AP / Station)
    $r->post('wireless/save', 'Interfaces::saveWireless', ['filter' => 'permission:interfaces.edit']);
    $r->get('wireless/status/(:segment)', 'Interfaces::wirelessStatus/$1', ['filter' => 'permission:interfaces.view']);
});

// ---- Firewall ----
$routes->group('firewall', ['filter' => 'permission:firewall.rules.view'], function ($r) {
    // Firewall Rules / Policies
    $r->get('rules', 'Firewall::index');
    $r->get('rules/export-csv', 'Firewall::exportRulesCsv');
    $r->post('rules/import-csv', 'Firewall::importRulesCsv', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('rules/store', 'Firewall::store', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('rules/(:segment)/update', 'Firewall::update/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('rules/(:segment)/toggle', 'Firewall::toggle/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('rules/(:segment)/delete', 'Firewall::delete/$1', ['filter' => 'permission:firewall.rules.delete']);
    $r->post('rules/apply', 'Firewall::apply', ['filter' => 'permission:firewall.rules.apply']);
    $r->post('rules/reorder', 'Firewall::reorder', ['filter' => 'permission:firewall.rules.reorder']);
    $r->get('rules/nftables-preview', 'Firewall::nftablesPreview', ['filter' => 'permission:firewall.rules.view']);

    // Policies
    $r->post('policies/store', 'Firewall::storePolicy', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('policies/(:segment)/update', 'Firewall::updatePolicy/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('policies/(:segment)/toggle', 'Firewall::togglePolicy/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('policies/(:segment)/delete', 'Firewall::deletePolicy/$1', ['filter' => 'permission:firewall.rules.delete']);
    $r->post('policies/(:segment)/clone', 'Firewall::clonePolicy/$1', ['filter' => 'permission:firewall.rules.edit']);

    // DoS / DDoS Policies
    $r->get('dos', 'DosPolicy::index');
    $r->post('dos/store', 'DosPolicy::store', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('dos/(:segment)/update', 'DosPolicy::update/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('dos/(:segment)/toggle', 'DosPolicy::toggle/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('dos/(:segment)/delete', 'DosPolicy::delete/$1', ['filter' => 'permission:firewall.rules.delete']);
    $r->post('dos/apply', 'DosPolicy::apply', ['filter' => 'permission:firewall.rules.apply']);

    // Objects: server-side search for the object pickers (paged, never the
    // whole table). Mirrored under `routing` for the PBR dialog.
    $r->get('objects/search', 'Firewall::searchObjects');

    // Objects: Addresses
    $r->get('addresses', 'Firewall::addresses');
    $r->get('addresses/export-csv', 'Firewall::exportAddressesCsv');
    $r->post('addresses/resolve', 'Firewall::resolveAddresses', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('addresses/import-csv', 'Firewall::importAddressesCsv', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('addresses/store', 'Firewall::storeAddress', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('addresses/(:segment)/update', 'Firewall::updateAddress/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('addresses/(:segment)/delete', 'Firewall::deleteAddress/$1', ['filter' => 'permission:firewall.rules.delete']);

    // Objects: Internet Service Database
    $r->get('internet-services', 'InternetServices::index');
    $r->post('internet-services/sync', 'InternetServices::sync', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('internet-services/sync-geo', 'InternetServices::syncGeo', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('internet-services/create-address', 'InternetServices::createAddress', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('internet-services/(:segment)/toggle', 'InternetServices::toggle/$1', ['filter' => 'permission:firewall.rules.edit']);

    // Objects: Address Groups
    $r->get('address-groups', 'Firewall::addressGroups');
    $r->get('address-groups/export-csv', 'Firewall::exportAddressGroupsCsv');
    $r->post('address-groups/import-csv', 'Firewall::importAddressGroupsCsv', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('address-groups/store', 'Firewall::storeAddressGroup', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('address-groups/(:segment)/update', 'Firewall::updateAddressGroup/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('address-groups/(:segment)/delete', 'Firewall::deleteAddressGroup/$1', ['filter' => 'permission:firewall.rules.delete']);

    // Objects: Schedules
    $r->get('schedules', 'Schedule::index');
    $r->post('schedules/store', 'Schedule::store', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('schedules/(:segment)/update', 'Schedule::update/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('schedules/(:segment)/toggle', 'Schedule::toggle/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('schedules/(:segment)/delete', 'Schedule::delete/$1', ['filter' => 'permission:firewall.rules.delete']);

    // Objects: Services
    $r->get('services', 'Firewall::services');
    $r->get('services/export-csv', 'Firewall::exportServicesCsv');
    $r->post('services/import-csv', 'Firewall::importServicesCsv', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('services/store', 'Firewall::storeService', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('services/(:segment)/update', 'Firewall::updateService/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('services/(:segment)/delete', 'Firewall::deleteService/$1', ['filter' => 'permission:firewall.rules.delete']);

    // Objects: Service Groups
    $r->get('service-groups', 'Firewall::serviceGroups');
    $r->get('service-groups/export-csv', 'Firewall::exportServiceGroupsCsv');
    $r->post('service-groups/import-csv', 'Firewall::importServiceGroupsCsv', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('service-groups/store', 'Firewall::storeServiceGroup', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('service-groups/(:segment)/update', 'Firewall::updateServiceGroup/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('service-groups/(:segment)/delete', 'Firewall::deleteServiceGroup/$1', ['filter' => 'permission:firewall.rules.delete']);
});

// ---- Firewall: NAT (one object-first page — Destination NAT, SNAT Pools, NPTv6, Settings) ----
$routes->group('nat', ['filter' => 'permission:nat.view'], function ($r) {
    $r->get('/', 'Nat::index');
    $r->post('settings', 'Nat::saveSettings', ['filter' => 'permission:nat.edit']);

    // Destination NAT — inbound DNAT rules
    $r->post('dnat/store', 'Nat::storeDnat', ['filter' => 'permission:nat.edit']);
    $r->post('dnat/(:segment)/update', 'Nat::updateDnat/$1', ['filter' => 'permission:nat.edit']);
    $r->post('dnat/(:segment)/toggle', 'Nat::toggleDnat/$1', ['filter' => 'permission:nat.edit']);
    $r->post('dnat/(:segment)/delete', 'Nat::deleteDnat/$1', ['filter' => 'permission:nat.delete']);

    // SNAT Pools — outbound source-NAT address ranges
    $r->post('snat-pools/store', 'Nat::storeSnatPool', ['filter' => 'permission:nat.edit']);
    $r->post('snat-pools/(:segment)/update', 'Nat::updateSnatPool/$1', ['filter' => 'permission:nat.edit']);
    $r->post('snat-pools/(:segment)/toggle', 'Nat::toggleSnatPool/$1', ['filter' => 'permission:nat.edit']);
    $r->post('snat-pools/(:segment)/delete', 'Nat::deleteSnatPool/$1', ['filter' => 'permission:nat.delete']);

    // IPv6 Prefix Translation (NPTv6)
    $r->post('npt/store', 'Nat::storeNpt', ['filter' => 'permission:nat.edit']);
    $r->post('npt/(:segment)/toggle', 'Nat::toggleNpt/$1', ['filter' => 'permission:nat.edit']);
    $r->post('npt/(:segment)/delete', 'Nat::deleteNpt/$1', ['filter' => 'permission:nat.delete']);

    $r->post('apply', 'Nat::apply', ['filter' => 'permission:nat.apply']);

    // Legacy deep links → the matching tab
    $r->get('dnat', static fn() => redirect()->to(base_url('nat?tab=dnat')));
    $r->get('snat-pools', static fn() => redirect()->to(base_url('nat?tab=snat')));
});

// ---- Routing ----
$routes->group('routing', ['filter' => 'permission:routing.table.view'], function ($r) {
    // Server-side object search for the PBR match-criteria pickers. The same
    // endpoint as `firewall/objects/search`, under this group's permission.
    $r->get('objects/search', 'Firewall::searchObjects');

    // Static routes & FIB (existing)
    $r->get('/', 'Routing::index');
    $r->post('static/store', 'Routing::storeStatic', ['filter' => 'permission:routing.static.manage']);
    $r->post('static/(:segment)/update', 'Routing::updateStatic/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('static/(:segment)/toggle', 'Routing::toggleStatic/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('static/(:segment)/delete', 'Routing::deleteStatic/$1', ['filter' => 'permission:routing.static.manage']);

    // ── BGP ──────────────────────────────────────────────────────────
    $r->post('bgp/settings/save', 'Routing::saveBgpSettings', ['filter' => 'permission:routing.static.manage']);
    $r->post('bgp/store', 'Routing::storeBgp', ['filter' => 'permission:routing.static.manage']);
    $r->post('bgp/(:segment)/update', 'Routing::updateBgp/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('bgp/(:segment)/delete', 'Routing::deleteBgp/$1', ['filter' => 'permission:routing.static.manage']);

    // ── OSPF Global Settings ─────────────────────────────────────────
    $r->post('ospf/settings/save', 'Routing::saveOspfSettings', ['filter' => 'permission:routing.static.manage']);

    // OSPF Networks (network X.X.X.X/X area Y.Y.Y.Y statements)
    $r->post('ospf/networks/store', 'Routing::storeOspf', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/networks/(:segment)/delete', 'Routing::deleteOspf/$1', ['filter' => 'permission:routing.static.manage']);

    // OSPF Areas
    $r->post('ospf/areas/store', 'Routing::storeOspfArea', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/areas/(:segment)/update', 'Routing::updateOspfArea/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/areas/(:segment)/delete', 'Routing::deleteOspfArea/$1', ['filter' => 'permission:routing.static.manage']);

    // OSPF Interfaces
    $r->post('ospf/ifaces/store', 'Routing::storeOspfIface', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/ifaces/(:segment)/update', 'Routing::updateOspfIface/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/ifaces/(:segment)/delete', 'Routing::deleteOspfIface/$1', ['filter' => 'permission:routing.static.manage']);

    // OSPF Manual Neighbors
    $r->post('ospf/neighbors/store', 'Routing::storeOspfNeighbor', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/neighbors/(:segment)/delete', 'Routing::deleteOspfNeighbor/$1', ['filter' => 'permission:routing.static.manage']);

    // OSPF Policy — Prefix Lists & Route Maps
    $r->post('ospf/policy/prefix-lists/store', 'Routing::storePrefixList', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/prefix-lists/(:segment)/update', 'Routing::updatePrefixList/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/prefix-lists/(:segment)/delete', 'Routing::deletePrefixList/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/route-maps/store', 'Routing::storeRouteMap', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/route-maps/(:segment)/update', 'Routing::updateRouteMap/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/route-maps/(:segment)/delete', 'Routing::deleteRouteMap/$1', ['filter' => 'permission:routing.static.manage']);

    // OSPF Policy — Distribute Lists & Area Filter Lists
    $r->post('ospf/policy/distribute-lists/store', 'Routing::storeDistributeList', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/distribute-lists/(:segment)/update', 'Routing::updateDistributeList/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/distribute-lists/(:segment)/delete', 'Routing::deleteDistributeList/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/area-filters/store', 'Routing::storeAreaFilter', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/area-filters/(:segment)/update', 'Routing::updateAreaFilter/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('ospf/policy/area-filters/(:segment)/delete', 'Routing::deleteAreaFilter/$1', ['filter' => 'permission:routing.static.manage']);

    // ── RIP (ripd) ────────────────────────────────────────────────────
    $r->post('rip/settings/save', 'Routing::saveRipSettings', ['filter' => 'permission:routing.static.manage']);
    $r->post('rip/networks/store', 'Routing::storeRipNetwork', ['filter' => 'permission:routing.static.manage']);
    $r->post('rip/networks/(:segment)/delete', 'Routing::deleteRipNetwork/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('rip/neighbors/store', 'Routing::storeRipNeighbor', ['filter' => 'permission:routing.static.manage']);
    $r->post('rip/neighbors/(:segment)/delete', 'Routing::deleteRipNeighbor/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('rip/passive/store', 'Routing::storeRipPassiveInterface', ['filter' => 'permission:routing.static.manage']);
    $r->post('rip/passive/(:segment)/delete', 'Routing::deleteRipPassiveInterface/$1', ['filter' => 'permission:routing.static.manage']);

    // ── IS-IS (isisd) ─────────────────────────────────────────────────
    $r->post('isis/settings/save', 'Routing::saveIsisSettings', ['filter' => 'permission:routing.static.manage']);
    $r->post('isis/interfaces/store', 'Routing::storeIsisInterface', ['filter' => 'permission:routing.static.manage']);
    $r->post('isis/interfaces/(:segment)/update', 'Routing::updateIsisInterface/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('isis/interfaces/(:segment)/delete', 'Routing::deleteIsisInterface/$1', ['filter' => 'permission:routing.static.manage']);

    // ── BFD ──────────────────────────────────────────────────────────
    $r->post('bfd/store', 'Routing::storeBfd', ['filter' => 'permission:routing.static.manage']);
    $r->post('bfd/(:segment)/update', 'Routing::updateBfd/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('bfd/(:segment)/delete', 'Routing::deleteBfd/$1', ['filter' => 'permission:routing.static.manage']);

    // ── VRF ───────────────────────────────────────────────────────────
    $r->post('vrf/store', 'Routing::storeVrf', ['filter' => 'permission:routing.static.manage']);
    $r->post('vrf/(:segment)/delete', 'Routing::deleteVrf/$1', ['filter' => 'permission:routing.static.manage']);

    // ── PBR ───────────────────────────────────────────────────────────
    $r->post('pbr/store', 'Routing::storePbr', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('pbr/reorder', 'Routing::reorderPbr', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('pbr/apply', 'Routing::applyPbr', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('pbr/(:segment)/update', 'Routing::updatePbr/$1', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('pbr/(:segment)/toggle', 'Routing::togglePbr/$1', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('pbr/(:segment)/delete', 'Routing::deletePbr/$1', ['filter' => 'permission:routing.pbr.manage']);

    // ── Multi-WAN Gateways ────────────────────────────────────────────
    $r->post('multiwan/gateways/store', 'Routing::storeGateway', ['filter' => 'permission:routing.static.manage']);
    $r->post('multiwan/gateways/(:segment)/update', 'Routing::updateGateway/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('multiwan/gateways/(:segment)/delete', 'Routing::deleteGateway/$1', ['filter' => 'permission:routing.static.manage']);

    // ── Multi-WAN Gateway Groups ──────────────────────────────────────
    $r->post('multiwan/groups/store', 'Routing::storeGatewayGroup', ['filter' => 'permission:routing.static.manage']);
    $r->post('multiwan/groups/(:segment)/update', 'Routing::updateGatewayGroup/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('multiwan/groups/(:segment)/delete', 'Routing::deleteGatewayGroup/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('multiwan/apply', 'Routing::applyMultiWan', ['filter' => 'permission:routing.static.manage']);
});

// ---- Application-Aware Dynamic SD-WAN (SLA Path Steering) ----
$routes->group('routing/sdwan', ['filter' => 'permission:routing.table.view'], function ($r) {
    $r->get('/', 'Sdwan::index');
    $r->post('member/save', 'Sdwan::saveMember', ['filter' => 'permission:routing.static.manage']);
    $r->post('member/(:segment)/delete', 'Sdwan::deleteMember/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('profile/save', 'Sdwan::saveProfile', ['filter' => 'permission:routing.static.manage']);
    $r->post('profile/(:segment)/delete', 'Sdwan::deleteProfile/$1', ['filter' => 'permission:routing.static.manage']);
    $r->post('rule/save', 'Sdwan::saveRule', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('rule/(:segment)/delete', 'Sdwan::deleteRule/$1', ['filter' => 'permission:routing.pbr.manage']);
    $r->post('settings/save', 'Sdwan::saveSettings', ['filter' => 'permission:routing.static.manage']);
    $r->get('probe-now', 'Sdwan::probeNow');
    $r->post('apply', 'Sdwan::apply', ['filter' => 'permission:routing.static.manage']);
    $r->get('metrics', 'Sdwan::metrics');
});

// ---- PPPoE Server ----
$routes->group('pppoe', ['filter' => 'permission:pppoe.server.view'], function ($r) {
    $r->get('/', 'Pppoe::index');

    $r->post('apply', 'Pppoe::apply', ['filter' => 'permission:pppoe.server.apply']);
    $r->post('redial', 'Pppoe::redial');

    $r->get('users/(:segment)/history', 'Pppoe::subscriberHistory/$1');

    $r->post('servers/store', 'Pppoe::storeServer', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('servers/(:segment)/update', 'Pppoe::updateServer/$1', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('servers/(:segment)/toggle', 'Pppoe::toggleServer/$1', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('servers/(:segment)/delete', 'Pppoe::deleteServer/$1', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('servers/(:segment)/test-radius', 'Pppoe::testRadius/$1', ['filter' => 'permission:pppoe.server.manage']);

    $r->post('users/store', 'Pppoe::storeUser', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('users/(:segment)/update', 'Pppoe::updateUser/$1', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('users/(:segment)/toggle', 'Pppoe::toggleUser/$1', ['filter' => 'permission:pppoe.server.manage']);
    $r->post('users/(:segment)/delete', 'Pppoe::deleteUser/$1', ['filter' => 'permission:pppoe.server.manage']);
});

// ---- VPN — WireGuard ----
$routes->group('vpn/wireguard', ['filter' => 'permission:vpn.wireguard.view'], function ($r) {
    $r->get('/', 'VpnWireguard::index');
    // ── Tunnels (interfaces + their addresses) ──────────────────────
    $r->post('tunnels/store', 'VpnWireguard::storeTunnel', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->post('tunnels/(:segment)/update', 'VpnWireguard::updateTunnel/$1', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->post('tunnels/(:segment)/toggle', 'VpnWireguard::toggleTunnel/$1', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->post('tunnels/(:segment)/delete', 'VpnWireguard::deleteTunnel/$1', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->post('tunnels/(:segment)/genkey', 'VpnWireguard::generateKeys/$1', ['filter' => 'permission:vpn.wireguard.manage']);
    // Mint keys/PSK for the add/edit modals — no saved tunnel id required (JSON)
    $r->post('keygen', 'VpnWireguard::keygen', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->get('tunnels/(:segment)/config', 'VpnWireguard::downloadTunnel/$1', ['filter' => 'permission:vpn.wireguard.view']);
    $r->get('tunnels/(:segment)/apply-script', 'VpnWireguard::applyScript/$1', ['filter' => 'permission:vpn.wireguard.view']);
    // ── Peers ───────────────────────────────────────────────────────
    $r->get('peers', 'VpnWireguard::peers');
    $r->post('peers/store', 'VpnWireguard::storePeer', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->post('peers/(:segment)/update', 'VpnWireguard::updatePeer/$1', ['filter' => 'permission:vpn.wireguard.manage']);
    $r->get('peers/(:segment)/download', 'VpnWireguard::downloadPeer/$1', ['filter' => 'permission:vpn.wireguard.view']);
    $r->post('peers/(:segment)/delete', 'VpnWireguard::deletePeer/$1', ['filter' => 'permission:vpn.wireguard.manage']);
});

// ---- VPN — OpenVPN ----
$routes->group('vpn/openvpn', ['filter' => 'permission:vpn.openvpn.view'], function ($r) {
    $r->get('/', 'VpnOpenvpn::index');
    // ── Server & site-to-site client instances ───────────────────────
    $r->post('store', 'VpnOpenvpn::store', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->post('(:segment)/update', 'VpnOpenvpn::update/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->post('(:segment)/toggle', 'VpnOpenvpn::toggle/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->post('(:segment)/delete', 'VpnOpenvpn::delete/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->post('restart', 'VpnOpenvpn::restart', ['filter' => 'permission:vpn.openvpn.manage']);
    // ── Config material ──────────────────────────────────────────────
    $r->get('client-config/(:segment)', 'VpnOpenvpn::clientConfig/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->get('ccd/(:segment)', 'VpnOpenvpn::ccd/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->get('export/(:segment)', 'VpnOpenvpn::export/$1', ['filter' => 'permission:vpn.openvpn.cert.export']);
    $r->get('server-config/(:segment)', 'VpnOpenvpn::serverConfig/$1', ['filter' => 'permission:vpn.openvpn.cert.export']);
});

// ---- VPN — IPsec / strongSwan ----
$routes->group('vpn/ipsec', ['filter' => 'permission:vpn.ipsec.view'], function ($r) {
    $r->get('/', 'VpnIpsec::index');
    $r->post('store', 'VpnIpsec::store', ['filter' => 'permission:vpn.ipsec.manage']);
    $r->post('(:segment)/update', 'VpnIpsec::update/$1', ['filter' => 'permission:vpn.ipsec.manage']);
    $r->post('(:segment)/toggle', 'VpnIpsec::toggle/$1', ['filter' => 'permission:vpn.ipsec.manage']);
    $r->post('(:segment)/delete', 'VpnIpsec::delete/$1', ['filter' => 'permission:vpn.ipsec.manage']);
    $r->post('restart', 'VpnIpsec::restart', ['filter' => 'permission:vpn.ipsec.manage']);
    $r->get('config', 'VpnIpsec::compiledConfig', ['filter' => 'permission:vpn.ipsec.view']);
    $r->get('(:segment)/vti-script', 'VpnIpsec::vtiScript/$1', ['filter' => 'permission:vpn.ipsec.view']);
});

// ---- In-Browser Clientless Web Application & Desktop Gateway (HTML5 RDP/SSH - ZTNA) ----
$routes->group('vpn/clientless-gateway', function ($r) {
    $r->get('/', 'ZtnaGateway::index', ['filter' => 'permission:vpn.openvpn.view']);
    $r->get('portal', 'ZtnaGateway::portal');
    $r->post('resource/save', 'ZtnaGateway::saveResource', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->post('resource/(:segment)/delete', 'ZtnaGateway::deleteResource/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->get('launch/(:segment)', 'ZtnaGateway::launch/$1');
    $r->get('connect/(:segment)', 'ZtnaGateway::connect/$1');
    $r->post('session/(:segment)/terminate', 'ZtnaGateway::terminateSession/$1', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->match(['get', 'post'], 'verify-auth', 'ZtnaGateway::verifyAuth');
    $r->post('settings/save', 'ZtnaGateway::saveSettings', ['filter' => 'permission:vpn.openvpn.manage']);
    $r->post('apply', 'ZtnaGateway::apply', ['filter' => 'permission:vpn.openvpn.manage']);
});

// ---- DHCP ----
$routes->group('dhcp', ['filter' => 'permission:dhcp.view'], function ($r) {
    $r->get('/', 'Dhcp::index');
    $r->get('leases', 'Dhcp::leases');
    $r->post('settings', 'Dhcp::saveSettings', ['filter' => 'permission:dhcp.edit']);
    $r->post('reservations/store', 'Dhcp::storeReservation', ['filter' => 'permission:dhcp.edit']);
    $r->post('reservations/(:segment)/update', 'Dhcp::updateReservation/$1', ['filter' => 'permission:dhcp.edit']);
    $r->match(['get', 'post'], 'reservations/(:segment)/delete', 'Dhcp::deleteReservation/$1', ['filter' => 'permission:dhcp.edit']);
    $r->post('restart', 'Dhcp::restart', ['filter' => 'permission:dhcp.restart']);

    // DHCP Relay (Services: DHCP Relay)
    $r->get('relay', 'DhcpRelay::index');
    $r->post('relay/destinations/store', 'DhcpRelay::storeDestination', ['filter' => 'permission:dhcp.edit']);
    $r->post('relay/destinations/(:segment)/delete', 'DhcpRelay::deleteDestination/$1', ['filter' => 'permission:dhcp.edit']);
    $r->post('relay/interfaces/store', 'DhcpRelay::storeRelay', ['filter' => 'permission:dhcp.edit']);
    $r->post('relay/interfaces/(:segment)/toggle', 'DhcpRelay::toggleRelay/$1', ['filter' => 'permission:dhcp.edit']);
    $r->post('relay/interfaces/(:segment)/delete', 'DhcpRelay::deleteRelay/$1', ['filter' => 'permission:dhcp.edit']);
    $r->post('relay/apply', 'DhcpRelay::apply', ['filter' => 'permission:dhcp.restart']);
    // Live relay-instance widget: status poll, in-place control, LED log fetch.
    $r->get('relay/instances/status', 'DhcpRelay::status');
    $r->get('relay/instances/(:segment)/logs', 'DhcpRelay::logs/$1');
    $r->post('relay/instances/(:segment)/control', 'DhcpRelay::control/$1', ['filter' => 'permission:dhcp.restart']);
});

// ---- DNS Resolver & DNSBL Ad-Blocking ----
$routes->group('dns', ['filter' => 'permission:dns.view'], function ($r) {
    $r->get('/', 'Dns::index');
    $r->post('settings', 'Dns::saveSettings', ['filter' => 'permission:dns.edit']);
    $r->post('resolver', 'Dns::saveResolverSettings', ['filter' => 'permission:dns.edit']);
    $r->post('hosts/store', 'Dns::storeHost', ['filter' => 'permission:dns.edit']);
    $r->post('hosts/(:segment)/update', 'Dns::updateHost/$1', ['filter' => 'permission:dns.edit']);
    $r->post('hosts/(:segment)/delete', 'Dns::deleteHost/$1', ['filter' => 'permission:dns.edit']);
    $r->post('domain-overrides/store', 'Dns::storeDomainOverride', ['filter' => 'permission:dns.edit']);
    $r->post('domain-overrides/(:segment)/update', 'Dns::updateDomainOverride/$1', ['filter' => 'permission:dns.edit']);
    $r->post('domain-overrides/(:segment)/toggle', 'Dns::toggleDomainOverride/$1', ['filter' => 'permission:dns.edit']);
    $r->post('domain-overrides/(:segment)/delete', 'Dns::deleteDomainOverride/$1', ['filter' => 'permission:dns.edit']);
    $r->post('feeds/store', 'Dns::storeFeed', ['filter' => 'permission:dns.edit']);
    $r->post('feeds/(:segment)/toggle', 'Dns::toggleFeed/$1', ['filter' => 'permission:dns.edit']);
    $r->post('feeds/(:segment)/delete', 'Dns::deleteFeed/$1', ['filter' => 'permission:dns.edit']);
    $r->post('feeds/sync', 'Dns::syncFeeds', ['filter' => 'permission:dns.edit']);
    $r->post('overrides/store', 'Dns::storeOverride', ['filter' => 'permission:dns.edit']);
    $r->post('overrides/(:segment)/delete', 'Dns::deleteOverride/$1', ['filter' => 'permission:dns.edit']);
    $r->post('overrides/quick-whitelist', 'Dns::quickWhitelist', ['filter' => 'permission:dns.edit']);
    $r->post('flush', 'Dns::flush', ['filter' => 'permission:dns.flush']);
});

// ---- WPAD / PAC Auto-Discovery Endpoints (Turnkey Root Handlers) ----
$routes->get('wpad.dat', 'WebFilter::serveWpad');
$routes->get('wpad.da', 'WebFilter::serveWpad');
$routes->get('proxy.pac', 'WebFilter::serveWpad');
$routes->get('wpad.pac', 'WebFilter::serveWpad');

// ---- Enterprise Web Proxy & Content Filter ----
$routes->group('webfilter', ['filter' => 'permission:webfilter.view'], function ($r) {
    $r->get('/', 'WebFilter::index');
    $r->get('logs', 'WebFilter::index');
    $r->post('save-general', 'WebFilter::saveGeneral', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('save-acls', 'WebFilter::saveAcls', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('save-filtering', 'WebFilter::saveFiltering', ['filter' => 'permission:webfilter.categories.manage']);
    $r->post('category/(:segment)/toggle', 'WebFilter::toggleCategory/$1', ['filter' => 'permission:webfilter.categories.manage']);
    $r->post('feeds/sync', 'WebFilter::syncFeeds', ['filter' => 'permission:webfilter.categories.manage']);
    $r->post('save-ssl', 'WebFilter::saveSsl', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('provision-ca', 'WebFilter::provisionCa', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('save-antivirus', 'WebFilter::saveAntivirus', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('av/update-signatures', 'WebFilter::updateSignatures', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('av/clear-detections', 'WebFilter::clearDetections', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('save-wpad', 'WebFilter::saveWpad', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('wpad/test', 'WebFilter::testWpad');
    $r->get('download-pac', 'WebFilter::downloadPac');
    $r->get('download-ca', 'WebFilter::downloadCa');
    $r->post('save-cache', 'WebFilter::saveCache', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('cache/clear', 'WebFilter::clearCache', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('quick-action', 'WebFilter::quickAction', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('logs/clear', 'WebFilter::clearLogs', ['filter' => 'permission:webfilter.logs.view']);
    $r->get('logs/data', 'WebFilter::logsData');
    $r->post('reload', 'WebFilter::reload', ['filter' => 'permission:webfilter.reload']);
    $r->post('policy/save', 'WebFilter::savePolicy', ['filter' => 'permission:webfilter.policy.edit']);
});

// ---- IDS/IPS ----
$routes->group('ids', ['filter' => 'permission:ids.view'], function ($r) {
    $r->get('/', 'Ids::index');
    $r->post('settings/save', 'Ids::saveSettings', ['filter' => 'permission:ids.edit']);
    $r->post('rules/store', 'Ids::storeIpRule', ['filter' => 'permission:ids.rules.manage']);
    $r->post('rules/(:segment)/toggle', 'Ids::toggleIpRule/$1', ['filter' => 'permission:ids.rules.manage']);
    $r->post('rules/(:segment)/delete', 'Ids::deleteIpRule/$1', ['filter' => 'permission:ids.rules.manage']);
    $r->post('country/save', 'Ids::saveCountryFilter', ['filter' => 'permission:ids.rules.manage']);
    $r->post('quick-action', 'Ids::quickAction', ['filter' => 'permission:ids.rules.manage']);
    $r->post('alerts/clear', 'Ids::clearAlerts', ['filter' => 'permission:ids.edit']);
    $r->post('apply', 'Ids::apply', ['filter' => 'permission:ids.apply']);
    $r->post('restart', 'Ids::restart', ['filter' => 'permission:ids.restart']);
});

// ---- Application Identification & Layer 7 DPI (AppID via nDPI) ----
$routes->group('appid', ['filter' => 'permission:firewall.rules.view'], function ($r) {
    $r->get('/', 'AppId::index');
    $r->post('policy/save', 'AppId::savePolicy', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('policy/(:segment)/delete', 'AppId::deletePolicy/$1', ['filter' => 'permission:firewall.rules.delete']);
    $r->post('policy/(:segment)/toggle', 'AppId::togglePolicy/$1', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('settings/save', 'AppId::saveSettings', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('signature/save', 'AppId::saveSignature', ['filter' => 'permission:firewall.rules.edit']);
    $r->post('signature/(:segment)/delete', 'AppId::deleteSignature/$1', ['filter' => 'permission:firewall.rules.delete']);
    $r->post('apply', 'AppId::apply', ['filter' => 'permission:firewall.rules.apply']);
    $r->get('preview', 'AppId::previewConfig');
    $r->get('live-flows', 'AppId::liveFlows');
    $r->get('telemetry-stats', 'AppId::telemetryStats');
});

// ---- Full SSL/TLS Deep Packet Inspection & MITM CA Engine ----
$routes->group('ssl-inspection', ['filter' => 'permission:webfilter.view'], function ($r) {
    $r->get('/', 'SslInspection::index');
    $r->post('profile/save', 'SslInspection::saveProfile', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('profile/(:segment)/delete', 'SslInspection::deleteProfile/$1', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('bypass/save', 'SslInspection::saveBypassDomain', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('bypass/(:segment)/delete', 'SslInspection::deleteBypassDomain/$1', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('settings/save', 'SslInspection::saveSettings', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('simulate-cert', 'SslInspection::simulateMitmCert');
    $r->get('download-ca', 'SslInspection::downloadCaCert/pem');
    $r->get('download-ca/(:segment)', 'SslInspection::downloadCaCert/$1');
    $r->post('apply', 'SslInspection::apply', ['filter' => 'permission:webfilter.policy.edit']);
    $r->get('active-sessions', 'SslInspection::activeSessions');
});
$routes->group('security/ssl-inspection', ['filter' => 'permission:webfilter.view'], function ($r) {
    $r->get('/', 'SslInspection::index');
    $r->post('profile/save', 'SslInspection::saveProfile', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('profile/(:segment)/delete', 'SslInspection::deleteProfile/$1', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('bypass/save', 'SslInspection::saveBypassDomain', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('bypass/(:segment)/delete', 'SslInspection::deleteBypassDomain/$1', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('settings/save', 'SslInspection::saveSettings', ['filter' => 'permission:webfilter.policy.edit']);
    $r->post('simulate-cert', 'SslInspection::simulateMitmCert');
    $r->get('download-ca', 'SslInspection::downloadCaCert/pem');
    $r->get('download-ca/(:segment)', 'SslInspection::downloadCaCert/$1');
    $r->post('apply', 'SslInspection::apply', ['filter' => 'permission:webfilter.policy.edit']);
    $r->get('active-sessions', 'SslInspection::activeSessions');
});

// ---- Automated Dynamic Threat Intelligence Feed Ingest (MISP / AlienVault / AbuseIPDB) ----
$routes->group('security/threat-intel', ['filter' => 'permission:ids.view'], function ($r) {
    $r->get('/', 'ThreatIntel::index');
    $r->post('save-feed', 'ThreatIntel::saveFeed', ['filter' => 'permission:ids.edit']);
    $r->post('toggle/(:segment)', 'ThreatIntel::toggleFeed/$1', ['filter' => 'permission:ids.edit']);
    $r->post('delete/(:segment)', 'ThreatIntel::deleteFeed/$1', ['filter' => 'permission:ids.edit']);
    $r->post('sync', 'ThreatIntel::syncNow', ['filter' => 'permission:ids.edit']);
    $r->post('sync/(:segment)', 'ThreatIntel::syncNow/$1', ['filter' => 'permission:ids.edit']);
    $r->post('save-settings', 'ThreatIntel::saveSettings', ['filter' => 'permission:ids.edit']);
    $r->post('add-whitelist', 'ThreatIntel::addWhitelist', ['filter' => 'permission:ids.edit']);
    $r->post('delete-whitelist/(:segment)', 'ThreatIntel::deleteWhitelist/$1', ['filter' => 'permission:ids.edit']);
    $r->post('add-manual-ioc', 'ThreatIntel::addManualIoc', ['filter' => 'permission:ids.edit']);
    $r->post('delete-manual-ioc/(:segment)', 'ThreatIntel::deleteManualIoc/$1', ['filter' => 'permission:ids.edit']);
    $r->get('compile', 'ThreatIntel::compileRuleset');
    $r->get('compile-dnsbl', 'ThreatIntel::compileDnsblRuleset');
});
$routes->group('threat-intel', ['filter' => 'permission:ids.view'], function ($r) {
    $r->get('/', 'ThreatIntel::index');
    $r->post('save-feed', 'ThreatIntel::saveFeed', ['filter' => 'permission:ids.edit']);
    $r->post('toggle/(:segment)', 'ThreatIntel::toggleFeed/$1', ['filter' => 'permission:ids.edit']);
    $r->post('delete/(:segment)', 'ThreatIntel::deleteFeed/$1', ['filter' => 'permission:ids.edit']);
    $r->post('sync', 'ThreatIntel::syncNow', ['filter' => 'permission:ids.edit']);
    $r->post('sync/(:segment)', 'ThreatIntel::syncNow/$1', ['filter' => 'permission:ids.edit']);
    $r->post('save-settings', 'ThreatIntel::saveSettings', ['filter' => 'permission:ids.edit']);
    $r->post('add-whitelist', 'ThreatIntel::addWhitelist', ['filter' => 'permission:ids.edit']);
    $r->post('delete-whitelist/(:segment)', 'ThreatIntel::deleteWhitelist/$1', ['filter' => 'permission:ids.edit']);
    $r->post('add-manual-ioc', 'ThreatIntel::addManualIoc', ['filter' => 'permission:ids.edit']);
    $r->post('delete-manual-ioc/(:segment)', 'ThreatIntel::deleteManualIoc/$1', ['filter' => 'permission:ids.edit']);
    $r->get('compile', 'ThreatIntel::compileRuleset');
    $r->get('compile-dnsbl', 'ThreatIntel::compileDnsblRuleset');
});

// ---- Backup & Restore ----
$routes->group('backup', ['filter' => 'permission:backup.view'], function ($r) {
    $r->get('/', 'Backup::index');
    $r->post('create', 'Backup::create', ['filter' => 'permission:backup.create']);
    $r->post('restore', 'Backup::restore', ['filter' => 'permission:backup.restore']);
    $r->get('download/(:segment)', 'Backup::download/$1', ['filter' => 'permission:backup.export']);
    $r->match(['get', 'post'], '(:segment)/delete', 'Backup::delete/$1', ['filter' => 'permission:backup.delete']);
    $r->match(['get', 'post'], 'delete/(:segment)', 'Backup::delete/$1', ['filter' => 'permission:backup.delete']);
});

// ---- Identity: Users ----
$routes->group('users', ['filter' => 'permission:users.view'], function ($r) {
    $r->get('/', 'Users::index');
    $r->get('create', 'Users::create', ['filter' => 'permission:users.create']);
    $r->post('store', 'Users::store', ['filter' => 'permission:users.create']);
    $r->get('edit/(:segment)', 'Users::edit/$1', ['filter' => 'permission:users.edit']);
    $r->post('update/(:segment)', 'Users::update/$1', ['filter' => 'permission:users.edit']);
    $r->post('(:segment)/delete', 'Users::delete/$1', ['filter' => 'permission:users.delete']);
    $r->post('(:segment)/reset-pw', 'Users::resetPassword/$1', ['filter' => 'permission:users.password.reset']);
    $r->post('(:segment)/assign-role', 'Users::assignRole/$1', ['filter' => 'permission:roles.assign']);
    $r->post('import-ldap', 'Users::importLdap', ['filter' => 'permission:users.create']);
    $r->post('sync-os', 'Users::syncOs', ['filter' => 'permission:users.create']);
});

// ---- Identity: Roles & Permissions ----
$routes->group('roles', ['filter' => 'permission:roles.view'], function ($r) {
    $r->get('/', 'Roles::index');
    $r->get('create', 'Roles::create', ['filter' => 'permission:roles.create']);
    $r->post('store', 'Roles::store', ['filter' => 'permission:roles.create']);
    $r->get('edit/(:segment)', 'Roles::edit/$1', ['filter' => 'permission:roles.edit']);
    $r->post('update/(:segment)', 'Roles::update/$1', ['filter' => 'permission:roles.edit']);
    $r->post('(:segment)/delete', 'Roles::delete/$1', ['filter' => 'permission:roles.delete']);
    $r->post('(:segment)/sync-permissions', 'Roles::syncPermissions/$1', ['filter' => 'permission:roles.edit']);
    $r->post('group-mappings/store', 'Roles::saveGroupMapping', ['filter' => 'permission:roles.edit']);
    $r->match(['get', 'post'], 'group-mappings/(:segment)/delete', 'Roles::deleteGroupMapping/$1', ['filter' => 'permission:roles.edit']);
});

// ---- Diagnostics & Live Telemetry ----
$routes->group('diag', ['filter' => 'permission:system.info.view'], function ($r) {
    $r->get('/', 'Diagnostics::index');
    $r->post('ping', 'Diagnostics::ping', ['filter' => 'permission:diag.ping']);
    $r->post('traceroute', 'Diagnostics::traceroute', ['filter' => 'permission:diag.ping']);
    // The streamed ping and traceroute are sessions the page polls for a line at
    // a time instead of waiting for the whole command to finish.
    $r->post('ping/start', 'Diagnostics::pingStart', ['filter' => 'permission:diag.ping']);
    $r->post('traceroute/start', 'Diagnostics::tracerouteStart', ['filter' => 'permission:diag.ping']);
    $r->get('stream', 'Diagnostics::diagnosticStream', ['filter' => 'permission:diag.ping']);
    $r->post('stream/stop', 'Diagnostics::diagnosticStreamStop', ['filter' => 'permission:diag.ping']);
    $r->post('dns-lookup', 'Diagnostics::dnsLookup', ['filter' => 'permission:diag.ping']);
    $r->post('packet-capture', 'Diagnostics::packetCapture', ['filter' => 'permission:diag.ping']);
    // The live capture is a session the page streams from, and it is gated on
    // the dedicated capture permission rather than the ping one.
    $r->post('packet-capture/start', 'Diagnostics::packetCaptureStart', ['filter' => 'permission:diag.packet_capture']);
    $r->get('packet-capture/stream', 'Diagnostics::packetCaptureStream', ['filter' => 'permission:diag.packet_capture']);
    $r->post('packet-capture/stop', 'Diagnostics::packetCaptureStop', ['filter' => 'permission:diag.packet_capture']);
    $r->post('route-lookup', 'Diagnostics::routeLookup', ['filter' => 'permission:diag.ping']);
    $r->post('port-probe', 'Diagnostics::portProbe', ['filter' => 'permission:diag.ping']);
    $r->post('dns-benchmark', 'Diagnostics::dnsBenchmark', ['filter' => 'permission:diag.ping']);
    $r->get('logs', 'Diagnostics::logs', ['filter' => 'permission:diag.logs.view']);
    $r->get('logs/data', 'Diagnostics::logsData', ['filter' => 'permission:diag.logs.view']);
    $r->get('audit', 'Diagnostics::audit', ['filter' => 'permission:diag.audit.view']);
    $r->get('arp', 'Diagnostics::arp', ['filter' => 'permission:diag.arp.view']);
    $r->get('states', 'Diagnostics::states', ['filter' => 'permission:diag.states.view']);
    $r->get('states/data', 'Diagnostics::statesData', ['filter' => 'permission:diag.states.view']);
    $r->get('states/export-csv', 'Diagnostics::exportStatesCsv', ['filter' => 'permission:diag.states.view']);
    $r->get('states/export-json', 'Diagnostics::exportStatesJson', ['filter' => 'permission:diag.states.view']);
    $r->post('states/kill', 'Diagnostics::killState', ['filter' => 'permission:system.settings.edit']);
    $r->post('states/kill-host', 'Diagnostics::killHostStates', ['filter' => 'permission:system.settings.edit']);
});

// ---- System ----
$routes->group('system', ['filter' => 'permission:system.info.view'], function ($r) {
    $r->get('/', 'SystemSettings::index');
    $r->post('save', 'SystemSettings::save', ['filter' => 'permission:system.settings.edit']);
    $r->post('reboot', 'SystemSettings::reboot', ['filter' => 'permission:system.reboot']);
    $r->post('halt', 'SystemSettings::halt', ['filter' => 'permission:system.halt']);
});

// ---- Profile (own account management — no role check needed) ----
$routes->get('profile', 'Profile::index');
$routes->post('profile/save', 'Profile::save');
$routes->post('profile/totp', 'Profile::setupTotp');
$routes->post('profile/password', 'Profile::changePassword');

// ---- High Availability & Stateful Failover (Keepalived & conntrackd) ----
// ---- Multicast & IGMP (proxy, PIM routing, snooping / mDNS reflection) ----
$routes->group('multicast', ['filter' => 'permission:multicast.view'], function ($r) {
    $r->get('/', 'Multicast::index');
    $r->post('save', 'Multicast::save', ['filter' => 'permission:multicast.edit']);
    $r->post('apply', 'Multicast::apply', ['filter' => 'permission:multicast.apply']);
});

$routes->group('ha', ['filter' => 'permission:ha.view'], function ($r) {
    $r->get('/', 'HighAvailability::index');
    $r->post('settings', 'HighAvailability::saveSettings', ['filter' => 'permission:ha.edit']);
    $r->post('vip', 'HighAvailability::saveVip', ['filter' => 'permission:ha.edit']);
    $r->match(['get', 'post'], 'delete-vip/(:num)', 'HighAvailability::deleteVip/$1', ['filter' => 'permission:ha.edit']);
    $r->post('failover', 'HighAvailability::failover', ['filter' => 'permission:ha.failover']);
    $r->post('sync', 'HighAvailability::syncConfig', ['filter' => 'permission:ha.edit']);
});

// ---- Traffic Shaping & Bufferbloat Mitigation (QoS / CAKE) ----
$routes->group('qos', ['filter' => 'permission:qos.view'], function ($r) {
    $r->get('/', 'Qos::index');
    $r->post('shaper', 'Qos::saveShaper', ['filter' => 'permission:qos.edit']);
    $r->match(['get', 'post'], 'delete-shaper/(:num)', 'Qos::deleteShaper/$1', ['filter' => 'permission:qos.edit']);
    $r->post('rule', 'Qos::saveRule', ['filter' => 'permission:qos.edit']);
    $r->match(['get', 'post'], 'delete-rule/(:num)', 'Qos::deleteRule/$1', ['filter' => 'permission:qos.edit']);
    $r->post('apply', 'Qos::apply', ['filter' => 'permission:qos.apply']);
});

// ---- ACME HTTP-01 validation (RFC 8555 §8.3) ----
//
// The CA fetches this over plain HTTP on port 80 and has no session or cookie,
// so it sits outside the authenticated group. The answer is a public value the
// CA already knows (`token.thumbprint`), only readable while an order is
// pending, and the token has to match ACME's base64url grammar — so the route
// cannot be used to read anything else.
$routes->get('.well-known/acme-challenge/(:segment)', 'Certificates::acmeChallenge/$1');

// ---- Certificates & Public Key Infrastructure (PKI) ----
$routes->group('certificates', ['filter' => 'permission:cert.view'], function ($r) {
    $r->get('/', 'Certificates::index');
    $r->post('create-ca', 'Certificates::createCa', ['filter' => 'permission:cert.ca.manage']);
    $r->post('create', 'Certificates::createCert', ['filter' => 'permission:cert.create']);
    $r->get('download-ca/(:num)', 'Certificates::downloadCaCert/$1', ['filter' => 'permission:cert.view']);
    $r->get('download-ca-key/(:num)', 'Certificates::downloadCaKey/$1', ['filter' => 'permission:cert.view']);
    $r->get('download/(:num)/(:segment)', 'Certificates::downloadCert/$1/$2', ['filter' => 'permission:cert.view']);
    $r->match(['get', 'post'], 'revoke/(:num)', 'Certificates::revoke/$1', ['filter' => 'permission:cert.delete']);
    $r->match(['get', 'post'], 'delete/(:num)', 'Certificates::delete/$1', ['filter' => 'permission:cert.delete']);
    $r->match(['get', 'post'], 'delete-ca/(:num)', 'Certificates::deleteCa/$1', ['filter' => 'permission:cert.ca.manage']);
    // Import third-party / externally-issued material
    $r->post('import-ca', 'Certificates::importCa', ['filter' => 'permission:cert.ca.manage']);
    $r->post('import', 'Certificates::importCert', ['filter' => 'permission:cert.create']);
    // CSR workflow (create → export → paste signed certificate back)
    $r->post('create-csr', 'Certificates::generateCsr', ['filter' => 'permission:cert.create']);
    $r->get('download-csr/(:num)', 'Certificates::downloadCsr/$1', ['filter' => 'permission:cert.view']);
    $r->post('complete-csr/(:num)', 'Certificates::completeCsr/$1', ['filter' => 'permission:cert.create']);
    // Web GUI / portal TLS binding
    $r->match(['get', 'post'], 'set-webgui/(:num)', 'Certificates::setWebguiCert/$1', ['filter' => 'permission:cert.ca.manage']);
    $r->match(['get', 'post'], 'clear-webgui', 'Certificates::clearWebguiCert', ['filter' => 'permission:cert.ca.manage']);
    // ACME / Let's Encrypt issuance and renewal
    $r->post('acme/account', 'Certificates::saveAcmeAccount', ['filter' => 'permission:cert.acme.manage']);
    $r->post('acme/request', 'Certificates::requestAcme', ['filter' => 'permission:cert.acme.manage']);
    $r->post('acme/check/(:num)', 'Certificates::checkAcme/$1', ['filter' => 'permission:cert.acme.manage']);
    $r->post('acme/renew/(:num)', 'Certificates::renewAcme/$1', ['filter' => 'permission:cert.acme.manage']);
});

// ---- Road Warrior Self-Service VPN Portal ----
$routes->group('vpn/portal', function ($r) {
    $r->get('/', 'VpnUserPortal::index');
    $r->get('download-ovpn', 'VpnUserPortal::downloadOvpn');
    $r->get('download-ovpn/(:segment)', 'VpnUserPortal::downloadOvpn/$1');
    $r->get('download-wg', 'VpnUserPortal::downloadWireguard');
    $r->get('download-wg/(:segment)', 'VpnUserPortal::downloadWireguard/$1');
});

// ---- Transactional commit engine (staged config with an auto-revert watchdog) ----
$routes->group('system/commit', function ($r) {
    $r->get('status', 'SystemCommit::status');
    $r->post('watchdog', 'SystemCommit::commitWatchdog');
    $r->post('permanent', 'SystemCommit::confirmPermanent');
    $r->post('rollback', 'SystemCommit::rollback');
});

// ---- System Services & Daemon Manager (Status: Services) ----
$routes->group('services', ['filter' => 'permission:dashboard.view'], function ($r) {
    $r->get('/', 'Services::index');
    $r->get('status', 'Services::status');
    $r->post('(:segment)/control', 'Services::control/$1');
    $r->post('(:segment)/start', 'Services::start/$1');
    $r->post('(:segment)/stop', 'Services::stop/$1');
    $r->post('(:segment)/restart', 'Services::restart/$1');
    $r->post('(:segment)/toggle-boot', 'Services::toggleBoot/$1');
    $r->post('(:segment)/toggle-watchdog', 'Services::toggleWatchdog/$1');
    $r->get('logs/(:segment)', 'Services::logs/$1');
    $r->post('restart-all', 'Services::restartAll');
});

// ---- Cron & Scheduled Tasks (System: Cron) ----
$routes->group('cron', ['filter' => 'permission:dashboard.view'], function ($r) {
    $r->get('/', 'Cron::index');
    $r->get('logs', 'Cron::logs');
    $r->post('store', 'Cron::store');
    $r->post('(:segment)/update', 'Cron::update/$1');
    $r->post('(:segment)/toggle', 'Cron::toggle/$1');
    $r->post('(:segment)/delete', 'Cron::delete/$1');
    $r->post('(:segment)/run-now', 'Cron::runNow/$1');
});

// ---- SNMP Agent & Monitoring (Services: SNMP) ----
$routes->group('snmp', ['filter' => 'permission:dashboard.view'], function ($r) {
    $r->get('/', 'Snmp::index');
    $r->get('logs', 'Snmp::logs');
    $r->post('settings', 'Snmp::saveSettings');
    $r->post('v3-users/store', 'Snmp::storeV3User');
    $r->post('v3-users/(:segment)/delete', 'Snmp::deleteV3User/$1');
    $r->post('v3-users/(:segment)/toggle', 'Snmp::toggleV3User/$1');
    $r->post('traps', 'Snmp::saveTraps');
    $r->post('traps/test', 'Snmp::sendTestTrap');
    $r->post('test-query', 'Snmp::testQuery');
});

// ---- SIEM Integration & Remote Syslog (Security: SIEM) ----
$routes->group('siem', ['filter' => 'permission:dashboard.view'], function ($r) {
    $r->get('/', 'Siem::index');
    $r->get('logs', 'Siem::logs');
    $r->get('stats', 'Siem::stats');
    $r->post('settings', 'Siem::saveSettings');
    $r->post('test-event', 'Siem::sendTestEvent');
});

// ---- Plugins & Package Manager (Extension surface) ----
$routes->group('plugins', ['filter' => 'permission:plugins.view'], function ($r) {
    $r->get('/', 'Plugins::index');
    $r->get('status', 'Plugins::status');
    // Update / upgrade screen (must precede the (:segment) plugin page below).
    $r->get('updates', 'Plugins::updates');
    $r->post('upgrade-all', 'Plugins::upgradeAll', ['filter' => 'permission:plugins.install']);
    $r->post('(:segment)/install', 'Plugins::install/$1', ['filter' => 'permission:plugins.install']);
    $r->post('(:segment)/remove', 'Plugins::remove/$1', ['filter' => 'permission:plugins.install']);
    $r->post('(:segment)/upgrade', 'Plugins::upgrade/$1', ['filter' => 'permission:plugins.install']);
    // Installed plugin daemons: live status poll, in-place start/restart/stop
    // and the LED log fetch — the same per-unit pattern as DHCP relay.
    $r->get('daemons/status', 'Plugins::daemonStatus');
    $r->get('daemons/(:segment)/logs', 'Plugins::daemonLogs/$1');
    $r->post('daemons/(:segment)/control', 'Plugins::daemonControl/$1', ['filter' => 'permission:plugins.configure']);
    $r->post('refresh', 'Plugins::refresh', ['filter' => 'permission:plugins.repo.refresh']);
    // Curated repositories (Plugin Spec §5.2): the registry, its CRUD, the
    // declarative apply and the metadata-only refresh. Registered before the
    // (:segment) plugin page below, so `/plugins/repositories` is not read as a
    // plugin id.
    $r->get('repositories', 'Packages::index');
    $r->post('repositories/store', 'Packages::store', ['filter' => 'permission:plugins.repo.manage']);
    $r->post('repositories/(:segment)/toggle', 'Packages::toggle/$1', ['filter' => 'permission:plugins.repo.manage']);
    $r->post('repositories/(:segment)/delete', 'Packages::delete/$1', ['filter' => 'permission:plugins.repo.manage']);
    // Read-only verification (reachability + signature + fingerprint), so it
    // needs only the metadata permission — the same one Check Signed Catalogue
    // uses.
    $r->post('repositories/(:segment)/test', 'Packages::test/$1', ['filter' => 'permission:plugins.repo.refresh']);
    // Dismiss the rendered report (session state only, so no permission gate).
    $r->post('repositories/test/clear', 'Packages::clearTest');
    // Adopt an image-shipped preset repository (Class C plugins' vendor repos).
    $r->post('repositories/preset/(:segment)', 'Packages::seed/$1', ['filter' => 'permission:plugins.repo.manage']);
    // Prove a preset's *image-shipped* trust data against its vendor, without
    // registering it. Read-only (reachability + signature + fingerprint), so it
    // needs only the metadata permission — not the one that adopts a source.
    $r->post('repositories/preset/(:segment)/test', 'Packages::testPreset/$1', ['filter' => 'permission:plugins.repo.refresh']);
    $r->post('repositories/apply', 'Packages::apply', ['filter' => 'permission:plugins.repo.manage']);
    $r->post('repositories/refresh', 'Packages::refresh', ['filter' => 'permission:plugins.repo.refresh']);
    // The drift sweep on demand (the scheduled task runs the same code). It
    // refreshes metadata but installs nothing, so it needs only the refresh
    // permission — the one that never widens the package surface.
    $r->post('repositories/drift', 'Packages::drift', ['filter' => 'permission:plugins.repo.refresh']);
    // Signed remote catalogue: fetch + verify + cache (Updates screen).
    $r->post('catalogue/sync', 'Plugins::syncCatalogue', ['filter' => 'permission:plugins.repo.refresh']);
    // The iperf3 plugin's own runner endpoint (must precede the (:segment) page).
    $r->post('iperf3/run', 'Plugins::iperf3Run', ['filter' => 'permission:plugins.configure']);
    // The NetBird overlay's own endpoints. Status is read-only; driving the
    // overlay (up/down/enrol) is a configure action.
    $r->get('netbird/status', 'Plugins::netbirdStatus', ['filter' => 'permission:plugins.view']);
    $r->post('netbird/control', 'Plugins::netbirdControl', ['filter' => 'permission:plugins.configure']);
    // The ZeroTier overlay's own endpoints, the same split: status is read-only,
    // joining or leaving a network is a configure action.
    $r->get('zerotier/status', 'Plugins::zerotierStatus', ['filter' => 'permission:plugins.view']);
    $r->post('zerotier/control', 'Plugins::zerotierControl', ['filter' => 'permission:plugins.configure']);
    // The NAT64/DNS64 plugin's own endpoints (must precede the (:segment)
    // plugin page below). Status is read-only; applying configuration is a
    // configure action.
    $r->get('nat64/status', 'Plugins::nat64Status', ['filter' => 'permission:plugins.view']);
    $r->post('nat64/apply', 'Plugins::nat64Apply', ['filter' => 'permission:plugins.configure']);
    // The darkstat plugin's own endpoints. Status is read-only; applying the
    // analyser's settings is a configure action.
    $r->get('darkstat/status', 'Plugins::darkstatStatus', ['filter' => 'permission:plugins.view']);
    $r->post('darkstat/apply', 'Plugins::darkstatApply', ['filter' => 'permission:plugins.configure']);
    // The lightsquid reporter plugin's own endpoints. Status is read-only;
    // applying the settings and running the parser are configure actions.
    $r->get('lightsquid/status', 'Plugins::lightsquidStatus', ['filter' => 'permission:plugins.view']);
    $r->post('lightsquid/apply', 'Plugins::lightsquidApply', ['filter' => 'permission:plugins.configure']);
    $r->post('lightsquid/refresh', 'Plugins::lightsquidRefresh', ['filter' => 'permission:plugins.configure']);
    // The lldpd plugin's own endpoints. Status and the neighbour table are
    // read-only; applying the daemon's settings is a configure action.
    $r->get('lldpd/status', 'Plugins::lldpdStatus', ['filter' => 'permission:plugins.view']);
    $r->get('lldpd/neighbours', 'Plugins::lldpdNeighbours', ['filter' => 'permission:plugins.view']);
    $r->post('lldpd/apply', 'Plugins::lldpdApply', ['filter' => 'permission:plugins.configure']);
    // The 6in4 tunnel broker plugin's own endpoints. `update` contacts the
    // broker with the stored key, so it is a configure action too.
    $r->get('ipv6-tunnel/status', 'Plugins::tunnelStatus', ['filter' => 'permission:plugins.view']);
    $r->post('ipv6-tunnel/apply', 'Plugins::tunnelApply', ['filter' => 'permission:plugins.configure']);
    $r->post('ipv6-tunnel/update', 'Plugins::tunnelUpdate', ['filter' => 'permission:plugins.configure']);
    // LightSquid report console / static file viewer under /plugins/lightsquid/report
    $r->get('lightsquid/report', 'Plugins::lightsquidReport', ['filter' => 'permission:plugins.view']);
    $r->get('lightsquid/report/(:any)', 'Plugins::lightsquidReport/$1', ['filter' => 'permission:plugins.view']);
    // A single plugin's own page — the target of its declared `provides.menu`
    // entries (`/plugins/<id>`). Registered last so the literals above win.
    $r->get('(:segment)', 'Plugins::show/$1');
});

// ---- LightSquid Console & Plugin Friendly Direct URLs ----
$routes->get('lightsquid', 'Plugins::lightsquidReport', ['filter' => 'permission:plugins.view']);
$routes->get('lightsquid/(:any)', 'Plugins::lightsquidReport/$1', ['filter' => 'permission:plugins.view']);
$routes->get('darkstat', static fn () => redirect()->to('plugins/darkstat'));
$routes->get('lldpd', static fn () => redirect()->to('plugins/lldpd'));
$routes->get('zerotier', static fn () => redirect()->to('plugins/zerotier'));
$routes->get('netbird', static fn () => redirect()->to('plugins/netbird'));
$routes->get('nat64', static fn () => redirect()->to('plugins/nat64'));

// ---- System Settings ----
$routes->group('system', ['filter' => 'permission:dashboard.view'], function ($r) {
    $r->get('/', 'SystemSettings::index');
    $r->post('save', 'SystemSettings::save');
    $r->post('saveTheme', 'SystemSettings::saveTheme');
    $r->post('reboot', 'SystemSettings::reboot');
    $r->post('halt', 'SystemSettings::halt');
    // Appliance-wide updates / upgrade (OS packages, not per-plugin)
    $r->get('updates', 'SystemUpdate::index');
    $r->post('updates/check', 'SystemUpdate::check', ['filter' => 'permission:system.settings.edit']);
    $r->post('updates/upgrade', 'SystemUpdate::upgrade', ['filter' => 'permission:system.settings.edit']);
    // LDAP / Active Directory
    $r->post('ldap/save', 'SystemSettings::saveLdap', ['filter' => 'permission:system.settings.edit']);
    $r->post('ldap/test', 'SystemSettings::testLdap', ['filter' => 'permission:system.settings.edit']);
    $r->post('ldap/sync', 'SystemSettings::syncLdapUsers', ['filter' => 'permission:system.settings.edit']);
    // RADIUS Authentication
    $r->post('radius/save', 'SystemSettings::saveRadius', ['filter' => 'permission:system.settings.edit']);
    $r->post('radius/test', 'SystemSettings::testRadius', ['filter' => 'permission:system.settings.edit']);
    // External Group to Role Mappings
    $r->post('group-mappings/store', 'SystemSettings::saveGroupMapping', ['filter' => 'permission:system.settings.edit']);
    $r->match(['get', 'post'], 'group-mappings/(:segment)/delete', 'SystemSettings::deleteGroupMapping/$1', ['filter' => 'permission:system.settings.edit']);
    // Automated Configuration Diff & Git-Backed Revision History
    $r->get('revisions', 'ConfigRevisions::index');
    $r->get('revisions/diff/(:segment)', 'ConfigRevisions::diff/$1');
    $r->post('revisions/commit', 'ConfigRevisions::commitManual', ['filter' => 'permission:system.settings.edit']);
    $r->post('revisions/rollback/(:segment)', 'ConfigRevisions::rollback/$1', ['filter' => 'permission:system.settings.edit']);
    $r->get('revisions/export/(:segment)', 'ConfigRevisions::export/$1');

    // Centralized Single Sign-On (SSO) Federation (OIDC / SAML 2.0)
    $r->get('sso', 'Sso::index');
    $r->post('sso/save-provider', 'Sso::saveProvider', ['filter' => 'permission:system.settings.edit']);
    $r->post('sso/toggle/(:segment)', 'Sso::toggleProvider/$1', ['filter' => 'permission:system.settings.edit']);
    $r->post('sso/delete/(:segment)', 'Sso::deleteProvider/$1', ['filter' => 'permission:system.settings.edit']);
    $r->post('sso/save-settings', 'Sso::saveSettings', ['filter' => 'permission:system.settings.edit']);
    $r->post('sso/discover', 'Sso::discoverEndpoints', ['filter' => 'permission:system.settings.edit']);
});
$routes->get('system', 'SystemSettings::index', ['filter' => 'permission:dashboard.view']);

// Which sidebar parents the operator has unfolded, remembered for the session so
// the rail follows them from page to page. Deliberately outside the `system`
// group above: it is not an appliance setting and grants nothing, so the only
// gate it needs is being signed in — a user who cannot open the settings page
// still gets a rail that remembers where they were working.
$routes->post('system/nav-groups', 'SystemSettings::saveNavGroups');

// ---- Users & Identities (IAM) ----
$routes->group('users', ['filter' => 'permission:users.view'], function ($r) {
    $r->get('/', 'Users::index');
    $r->get('create', 'Users::create', ['filter' => 'permission:users.create']);
    $r->post('store', 'Users::store', ['filter' => 'permission:users.create']);
    $r->get('edit/(:segment)', 'Users::edit/$1', ['filter' => 'permission:users.edit']);
    $r->post('update/(:segment)', 'Users::update/$1', ['filter' => 'permission:users.edit']);
    $r->post('delete/(:segment)', 'Users::delete/$1', ['filter' => 'permission:users.delete']);
    $r->post('reset-password/(:segment)', 'Users::resetPassword/$1', ['filter' => 'permission:users.edit']);
    $r->post('import-ldap', 'Users::importLdap', ['filter' => 'permission:users.create']);
});

// ---- Roles & Permissions (RBAC) ----
$routes->group('roles', ['filter' => 'permission:roles.view'], function ($r) {
    $r->get('/', 'Roles::index');
    $r->get('create', 'Roles::create', ['filter' => 'permission:roles.create']);
    $r->post('store', 'Roles::store', ['filter' => 'permission:roles.create']);
    $r->get('edit/(:segment)', 'Roles::edit/$1', ['filter' => 'permission:roles.edit']);
    $r->post('update/(:segment)', 'Roles::update/$1', ['filter' => 'permission:roles.edit']);
    $r->post('delete/(:segment)', 'Roles::delete/$1', ['filter' => 'permission:roles.delete']);
    $r->post('sync-permissions/(:segment)', 'Roles::syncPermissions/$1', ['filter' => 'permission:roles.edit']);
    // External group mappings
    $r->post('group-mappings/store', 'Roles::saveGroupMapping', ['filter' => 'permission:roles.edit']);
    $r->match(['get', 'post'], 'group-mappings/(:segment)/delete', 'Roles::deleteGroupMapping/$1', ['filter' => 'permission:roles.edit']);
});

// ---- Documentation (docs/*.md rendered as in-app guides) ----
$routes->get('docs', 'Docs::index');
$routes->get('docs/(:segment)', 'Docs::index/$1');

