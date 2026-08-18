<?php

if (!defined('MAIN_DB_PREFIX')) die('Access denied');

require_once __DIR__ . '/FahIntegrationInterface.php';

/**
 * Scans the helpers/ directory for integration files and manages their lifecycle.
 *
 * Naming convention: helpers/FooBarIntegration.php → class FahFooBarIntegration
 * Each file must define exactly one class that implements FahIntegrationInterface.
 * The class is instantiated with ($db, $conf) and registered automatically.
 */
class FahIntegrationManager
{
    private $db;
    private $conf;
    private $integrations = array();
    private $loaded = false;

    public function __construct($db, $conf)
    {
        $this->db   = $db;
        $this->conf = $conf;
    }

    private function loadAll()
    {
        if ($this->loaded) return;
        $this->loaded = true;
        foreach (glob(__DIR__ . '/*Integration.php') as $file) {
            require_once $file;
            $class = 'Fah' . basename($file, '.php');
            if (class_exists($class)) {
                $instance = new $class($this->db, $this->conf);
                $this->integrations[$instance->getId()] = $instance;
            }
        }
    }

    /** All registered integrations, detected or not. */
    public function getAll()
    {
        $this->loadAll();
        return $this->integrations;
    }

    /** Only integrations whose isDetected() returns true. */
    public function getDetected()
    {
        $this->loadAll();
        return array_filter($this->integrations, function ($i) { return $i->isDetected(); });
    }

    /** Get a specific integration by ID, or null. */
    public function get($id)
    {
        $this->loadAll();
        return isset($this->integrations[$id]) ? $this->integrations[$id] : null;
    }
}
