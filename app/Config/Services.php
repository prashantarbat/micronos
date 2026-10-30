<?php

namespace Config;

use App\Connectors\ManualFeedIngestor;
use App\Libraries\ConnectorEgressGuard;
use App\Libraries\IpcClient;
use App\Libraries\CatalogueVerifier;
use App\Libraries\AcmeManager;
use App\Libraries\PackageManager;
use App\Libraries\RemoteCatalogue;
use App\Libraries\RepoRegistry;
use App\Libraries\SystemUpdate;
use CodeIgniter\Config\BaseService;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 */
class Services extends BaseService
{
    /**
     * The micrond IPC bridge.
     *
     * Resolved from the container rather than instantiated directly so tests
     * can inject a fake with `Services::injectMock('ipc', ...)` and exercise
     * daemon-failure handling without a running appliance.
     */
    public static function ipc($getShared = true): IpcClient
    {
        if ($getShared) {
            return static::getSharedInstance('ipc');
        }

        return new IpcClient();
    }

    /**
     * Control-socket client of the browser terminal service (micron-terminald).
     * A service so tests can substitute it.
     */
    public static function terminalClient($getShared = true): \App\Libraries\TerminalClient
    {
        if ($getShared) {
            return static::getSharedInstance('terminalClient');
        }

        return new \App\Libraries\TerminalClient();
    }

    /**
     * ACME / Let's Encrypt issuance and renewal (PKI module).
     *
     * Resolved from the container so the page, the public HTTP-01 endpoint and
     * the scheduled renewal task share one instance, and so a test can inject a
     * manager built on a stubbed CA with `Services::injectMock('acme', ...)`.
     *
     * Built on {@see ipc()} because installing an issued certificate means
     * telling the daemon to reload, exactly as a locally-issued one does.
     */
    public static function acme($getShared = true): AcmeManager
    {
        if ($getShared) {
            return static::getSharedInstance('acme');
        }

        return new AcmeManager(null, static::ipc());
    }

    /**
     * The connector fetch egress guard (threat model F3/F5).
     *
     * Resolved from the container so the operator-feed path and tests share one
     * instance, and so a test can inject one with a fake resolver/transport.
     */
    public static function egressGuard($getShared = true): ConnectorEgressGuard
    {
        if ($getShared) {
            return static::getSharedInstance('egressGuard');
        }

        return new ConnectorEgressGuard();
    }

    /**
     * Ingests operator-supplied `dns_feeds` URLs through the egress guard.
     *
     * Built on {@see egressGuard()} so injecting a guard is enough to steer it
     * in tests.
     */
    public static function manualFeedIngestor($getShared = true): ManualFeedIngestor
    {
        if ($getShared) {
            return static::getSharedInstance('manualFeedIngestor');
        }

        return new ManualFeedIngestor(static::egressGuard());
    }

    /**
     * The plugin package manager (Plugin Spec §5).
     *
     * Resolved from the container so the CLI, the (future) Plugins page and
     * tests share one instance, and so a test can inject a fake IPC client.
     */
    public static function packageManager($getShared = true): PackageManager
    {
        if ($getShared) {
            return static::getSharedInstance('packageManager');
        }

        return new PackageManager(static::ipc());
    }

    /**
     * The curated plugin repository registry (Plugin Spec §5.2).
     *
     * Built on the container IPC client and the shared egress guard, so a test
     * can inject either and exercise the page and the apply path without an
     * appliance: `Services::injectMock('repoRegistry', ...)`.
     */
    public static function repoRegistry($getShared = true): RepoRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('repoRegistry');
        }

        return new RepoRegistry(static::ipc(), null, static::egressGuard());
    }

    /**
     * The appliance-wide update / upgrade surface (System Updates page).
     *
     * Built on the container IPC client so tests can inject a fake and exercise
     * daemon-failure handling without a running appliance.
     */
    public static function systemUpdate($getShared = true): SystemUpdate
    {
        if ($getShared) {
            return static::getSharedInstance('systemUpdate');
        }

        return new SystemUpdate(static::ipc());
    }

    /**
     * The signed remote plugin catalogue (Plugin Spec §5.2).
     *
     * Built on the shared egress guard, so injection in tests (and the operator
     * feed path) share one SSRF policy. `Services::injectMock('remoteCatalogue', ...)`
     * lets a test drive the Updates screen from a staged catalogue.
     */
    public static function remoteCatalogue($getShared = true): RemoteCatalogue
    {
        if ($getShared) {
            return static::getSharedInstance('remoteCatalogue');
        }

        return new RemoteCatalogue(static::egressGuard(), new CatalogueVerifier());
    }

    /**
     * Automated Configuration Diff & Git-Backed Revision Engine.
     */
    public static function configRevisionEngine($getShared = true): \App\Libraries\ConfigRevisionEngine
    {
        if ($getShared) {
            return static::getSharedInstance('configRevisionEngine');
        }

        return new \App\Libraries\ConfigRevisionEngine();
    }
}

