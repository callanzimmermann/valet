<?php

namespace Valet;

use mysqli;

class Mysql
{
    const MYSQL_ROOT_PASSWORD = '';

    /**
     * @var CommandLine
     */
    public $cli;

    /**
     * @var Configuration
     */
    public $configuration;

    /**
     * @var Mysqli
     */
    protected $link = false;

    /**
     * Create a new instance.
     *
     * @param CommandLine $cli
     * @param Configuration $configuration
     * @param Site $site
     */
    public function __construct(
        CommandLine $cli,
        Configuration $configuration
    ) {
        $this->cli = $cli;
        $this->configuration = $configuration;
    }

    /**
     * Set root password of Mysql.
     * @param string $oldPwd
     * @param string $newPwd
     */
    public function setRootPassword($oldPwd = '', $newPwd = self::MYSQL_ROOT_PASSWORD)
    {
        $success = true;
        $this->cli->runAsUser("mysqladmin -u root --password='".$oldPwd."' password ".$newPwd, function () use (&$success) {
            warning('Setting mysql password for root user failed. ');
            $success = false;
        });

        if ($success !== false) {
            $config = $this->configuration->read();
            if (!isset($config['mysql'])) {
                $config['mysql'] = [];
            }
            $config['mysql']['password'] = $newPwd;
            $this->configuration->write($config);
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     */
    private function getRootPassword()
    {
        $config = $this->configuration->read();
        if (isset($config['mysql']) && isset($config['mysql']['password'])) {
            return $config['mysql']['password'];
        }

        return self::MYSQL_ROOT_PASSWORD;
    }

    /**
     * Run Mysql query.
     *
     * @param $query
     * @param bool $escape
     *
     * @return bool|\mysqli_result
     */
    protected function query($query, $escape = true)
    {
        $link = $this->getConnection();

        $query = $escape ? $this->escape($query) : $query;

        return tap($link->query($query), function ($result) use ($link) {
            if (!$result) {
                warning(\mysqli_error($link));
            }
        });
    }

    /**
     * Return Mysql connection.
     *
     * @return bool|mysqli
     */
    public function getConnection()
    {
        // if connection already exists return it early.
        if ($this->link) {
            return $this->link;
        }

        // Create connection
        $this->link = new mysqli('127.0.0.1', 'root', '', 'mysql', 3306, '/tmp/mysql_3306.sock');

        // Check connection
        if ($this->link->connect_error) {
            warning('Failed to connect to database');

            return false;
        }

        return $this->link;
    }

    /**
     * escape string of query via myslqi.
     *
     * @param string $string
     *
     * @return string
     */
    protected function escape($string)
    {
        return \mysqli_real_escape_string($this->getConnection(), $string);
    }

    /**
     * Drop current Mysql database & re-import it from file.
     *
     * @param $file
     * @param $database
     */
    public function reimportDatabase($file, $database)
    {
        $this->importDatabase($file, $database, true);
    }

    /**
     * Import Mysql database from file.
     *
     * @param string $file
     * @param string $database
     * @param bool   $dropDatabase
     */
    public function importDatabase($file, $database, $dropDatabase = false)
    {
        $database = $this->getDatabaseName($database);

        // drop database first
        if ($dropDatabase) {
            $this->dropDatabase($database);
        }

        $this->createDatabase($database);

        $gzip = ' | ';
        if (\stristr($file, '.gz')) {
            $gzip = ' | gzip -cd | ';
        }
        $this->cli->passthru('pv ' . \escapeshellarg($file) . $gzip . 'mysql -S /tmp/mysql_3306.sock ' . \escapeshellarg($database));
    }

    /**
     * Get database name via name or current dir.
     *
     * @param $database
     *
     * @return string
     */
    protected function getDatabaseName($database = '')
    {
        return $database ?: $this->getDirName();
    }

    /**
     * Get current dir name.
     *
     * @return string
     */
    public function getDirName()
    {
        $gitDir = $this->cli->runAsUser('git rev-parse --show-toplevel 2>/dev/null');

        if ($gitDir) {
            return \trim(\basename($gitDir));
        }

        return \trim(\basename(\getcwd()));
    }

    /**
     * Drop Mysql database.
     *
     * @param string $name
     *
     * @return bool|string
     */
    public function dropDatabase($name)
    {
        $name = $this->getDatabaseName($name);

        return $this->query('DROP DATABASE `' . $name . '`') ? $name : false;
    }

    /**
     * Create Mysql database.
     *
     * @param string $name
     *
     * @return bool|string
     */
    public function createDatabase($name)
    {
        $name = $this->getDatabaseName($name);

        return $this->query('CREATE DATABASE IF NOT EXISTS `' . $name . '`') ? $name : false;
    }

    /**
     * Check if database already exists.
     *
     * @param string $name
     *
     * @return bool|\mysqli_result
     */
    public function isDatabaseExists($name)
    {
        $name = $this->getDatabaseName($name);

        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '" . $this->escape($name) . "'", false);

        return (bool) $query->num_rows;
    }

    /**
     * Export Mysql database.
     *
     * @param $filename
     * @param $database
     *
     * @return array
     */
    public function exportDatabase($filename, $database)
    {
        $database = $this->getDatabaseName($database);

        if (!$filename || $filename === '-') {
            $filename = $database . '-' . \date('Y-m-d-His', \time());
        }

        if (!\stristr($filename, '.sql')) {
            $filename = $filename . '.sql.gz';
        }
        if (!\stristr($filename, '.gz')) {
            $filename = $filename . '.gz';
        }

        $this->cli->passthru('mysqldump ' . \escapeshellarg($database) . ' | gzip > ' . \escapeshellarg($filename ?: $database));

        return [
            'database' => $database,
            'filename' => $filename,
        ];
    }
}
