<?php
/**
 * Copyright © Callan Zimmermann. All rights reserved.
*/

namespace Valet;

use ValetDriver;

/**
 * Class DevTools
 */
class DevTools
{
    public $cli;
    public $configuration;
    public $site;
    public $files;
    public $mysql;

    /**
     * Create a new Nginx instance.
     *
     * @param CommandLine $cli
     * @param Configuration $configuration
     * @param Site $site
     * @param Filesystem $files
     * @param Mysql $mysql
     */
    public function __construct(
        CommandLine $cli,
        Configuration $configuration,
        Site $site,
        Filesystem $files,
        Mysql $mysql
    ) {
        $this->cli = $cli;
        $this->site = $site;
        $this->configuration = $configuration;
        $this->files = $files;
        $this->mysql = $mysql;

    }

    public function configure()
    {
        require realpath(__DIR__ . '/../drivers/require.php');

        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        $secured = $this->site->secured();
        $domain = $this->site->host(getcwd()) . '.' . $this->configuration->read()['domain'];
        $isSecure = in_array($domain, $secured);
        $url = ($isSecure ? 'https://' : 'http://') . $domain;

        if (method_exists($driver, 'configure')) {
            return $driver->configure($this, $url);
        }

        info('No configuration settings found.');
    }
}
