<?php

namespace NeoPHP\Net;

use Exception;
use RuntimeException;

/**
 * Class ConnectionManager
 * @package NeoPHP\Net
 */
class ConnectionManager implements ConnectionListener {

    private $port;
    private $resources;
    private $connections;
    private $masterSocket;
    private $listeners;
    private $keepaliveTimeout;
    private $checkOldConnectionsSeconds;

    /**
     * ConnectionManager constructor.
     * @param null $port
     */
    public function __construct($port = null) {
        $this->keepaliveTimeout = 300;
        $this->checkOldConnectionsSeconds = 600;
        $this->resources = array();
        $this->connections = array();
        $this->listeners = array();
        $this->port = $port;
        $this->masterSocket = null;
    }

    /**
     *
     */
    public function __destruct() {
        while (sizeof($this->connections) > 0)
            $this->removeConnection(end($this->connections));
        $this->masterSocket->close();
        $this->masterSocket = null;
        $this->resources = array();
    }

    /**
     *
     */
    public function run() {
        $this->running = true;
        while ($this->running)
            $this->checkConnections();
    }

    /**
     *
     */
    public function stop() {
        $this->running = false;
    }

    /**
     * @param $port
     */
    public function setPort($port) {
        $this->port = $port;
    }

    /**
     * @return null
     */
    public function getPort() {
        return $this->port;
    }

    /**
     *
     */
    public function checkConnections() {
        if (empty($this->masterSocket)) {
            $this->masterSocket = new ServerSocket($this->port);
            $masterResource = $this->masterSocket->getResource();
            $this->resources[$this->getResourceId($masterResource)] = $masterResource;
        }
        $masterResource = $this->masterSocket->getResource();
        $readResources = $this->resources;
        $writeResources = [];
        $errorResources = [];

        $streamsCount = socket_select($readResources, $writeResources, $errorResources, null);

        if ($streamsCount === false) {
            throw new RuntimeException("Error reading stream resources. " . socket_strerror(socket_last_error()));
        }

        foreach ($readResources as $resource) {
            if ($resource == $masterResource)
                $this->processMasterSocket();
            else
                $this->processClientSocket($resource);
        }

        $timestamp = microtime(true);
        if (!isset($this->lastCheckOldConnectionsTimestamp) || (($timestamp - $this->lastCheckOldConnectionsTimestamp) > $this->checkOldConnectionsSeconds)) {
            $this->closeOldConnections();
            $this->lastCheckOldConnectionsTimestamp = $timestamp;
        }
    }

    /**
     *
     */
    protected function processMasterSocket() {
        try {
            $this->addConnection(new Connection($this, $this->masterSocket->accept()));
        }
        catch (Exception $e) {
        }
    }

    /**
     * @param $resource
     */
    protected function processClientSocket($resource) {
        try {
            $this->getConnectionForResource($resource)->process();
        }
        catch (Exception $ex) {
        }
    }

    /**
     * @param Connection $connection
     */
    protected function addConnection(Connection $connection) {
        $resource = $connection->getSocket()->getResource();
        $resourceId = $this->getResourceId($resource);
        $this->resources[$resourceId] = $resource;
        $this->connections[$resourceId] = $connection;
        $this->onConnectionAdded($connection);
    }

    /**
     * @param Connection $connection
     */
    public function removeConnection(Connection $connection) {
        $resourceId = array_search($connection, $this->connections);
        if ($resourceId) {
            unset($this->connections[$resourceId]);
            unset($this->resources[$resourceId]);
            $this->onConnectionRemoved($connection);
        }
    }

    /**
     * @param $id
     */
    public function closeConnectionById($id) {
        $connection = $this->getConnection($id);
        if ($connection != null)
            $connection->close();
    }

    /**
     * @param $identifier
     */
    public function closeConnectionByIdentifier($identifier) {
        $connection = $this->getConnectionByIdentifier($identifier);
        if ($connection != null)
            $connection->close();
    }

    /**
     *
     */
    public function closeOldConnections() {
        $timestamp = microtime(true);
        foreach ($this->connections as $connection) {
            if (($timestamp - $connection->getLastActivityTimestamp()) > $this->keepaliveTimeout) {
                $connection->close();
            }
        }
    }

    /**
     * @param $id
     * @return mixed|null
     */
    public function getConnection($id) {
        $foundConnection = null;
        foreach ($this->connections as $connection) {
            if ($connection->getId() == $id) {
                $foundConnection = $connection;
                break;
            }
        }
        return $foundConnection;
    }

    /**
     * @param $identifier
     * @return mixed|null
     */
    public function getConnectionByIdentifier($identifier) {
        $foundConnection = null;
        foreach ($this->connections as $connection) {
            if ($connection->getIdentifier() == $identifier) {
                $foundConnection = $connection;
                break;
            }
        }
        return $foundConnection;
    }

    /**
     * @return array
     */
    public function getConnections() {
        return $this->connections;
    }

    /**
     * @param $resource
     * @return bool|mixed
     */
    protected function getConnectionForResource($resource) {
        $resourceId = $this->getResourceId($resource);
        return array_key_exists($resourceId, $this->connections) ? $this->connections[$resourceId] : false;
    }

    /**
     * @param Connection $connection
     * @param $data
     */
    public function sendToConnection(Connection $connection, $data) {
        $connection->send($data);
    }

    /**
     * @param $connectionId
     * @param $data
     * @throws Exception
     */
    public function sendToConnectionId($connectionId, $data) {
        $connection = $this->getConnection($connectionId);
        if ($connection == null)
            throw new Exception ('Connection "' . $connectionId . '" not found for sending data !!');
        $this->sendToConnection($connection, $data);
    }

    /**
     * @param $connectionIdentifier
     * @param $data
     * @throws Exception
     */
    public function sendToConnectionIdentifier($connectionIdentifier, $data) {
        $connection = $this->getConnectionByIdentifier($connectionIdentifier);
        if ($connection == null)
            throw new Exception ('Connection "' . $connectionIdentifier . '" not found for sending data !!');
        $this->sendToConnection($connection, $data);
    }

    /**
     * @param $resource
     * @return int
     */
    protected function getResourceId($resource) {
        return (int)$resource;
    }

    /**
     * @param ConnectionListener $listener
     */
    public function addConnectionListener(ConnectionListener $listener) {
        $this->listeners[] = $listener;
    }

    /**
     * @param ConnectionListener $listener
     */
    public function removeConnectionListener(ConnectionListener $listener) {
        $index = array_search($listener, $this->listeners);
        if ($index != false)
            unset($this->listeners[$index]);
    }

    /**
     * @param Connection $connection
     * @return mixed|void
     */
    public function onConnectionAdded(Connection $connection) {
        foreach ($this->listeners as $connectionListener) {
            try {
                $response = $connectionListener->onConnectionAdded($connection);
                if ($response === true)
                    break;
            }
            catch (Exception $ex) {
            }
        }
    }

    /**
     * @param Connection $connection
     * @return mixed|void
     */
    public function onConnectionRemoved(Connection $connection) {
        foreach ($this->listeners as $connectionListener) {
            try {
                $response = $connectionListener->onConnectionRemoved($connection);
                if ($response === true)
                    break;
            }
            catch (Exception $ex) {
            }
        }
    }

    /**
     * @param Connection $connection
     * @param $dataReceived
     * @return mixed|void
     */
    public function onConnectionDataReceived(Connection $connection, $dataReceived) {
        foreach ($this->listeners as $connectionListener) {
            try {
                $response = $connectionListener->onConnectionDataReceived($connection, $dataReceived);
                if ($response === true)
                    break;
            }
            catch (Exception $ex) {
            }
        }
    }

    /**
     * @param Connection $connection
     * @param $dataSent
     * @return mixed|void
     */
    public function onConnectionDataSent(Connection $connection, $dataSent) {
        foreach ($this->listeners as $connectionListener) {
            try {
                $response = $connectionListener->onConnectionDataSent($connection, $dataSent);
                if ($response === true)
                    break;
            }
            catch (Exception $ex) {
            }
        }
    }
}