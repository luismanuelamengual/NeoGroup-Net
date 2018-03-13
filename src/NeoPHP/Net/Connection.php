<?php

namespace NeoPHP\Net;

use Exception;

/**
 * Class Connection
 * @package NeoPHP\Net
 */
class Connection {

    protected $id;
    protected $identifier;
    protected $name;
    protected $manager;
    protected $socket;
    protected $lastActivityTimestamp;

    /**
     * Connection constructor.
     * @param ConnectionManager $manager
     * @param Socket $socket
     */
    public function __construct(ConnectionManager $manager, Socket $socket) {
        static $idCounter = 1;
        $this->id = $idCounter++;
        $this->identifier = null;
        $this->manager = $manager;
        $this->socket = $socket;
        $this->lastActivityTimestamp = microtime(true);
    }

    /**
     * @return int
     */
    public function getId() {
        return $this->id;
    }

    /**
     * @return null
     */
    public function getIdentifier() {
        return $this->identifier;
    }

    /**
     * @param $identifier
     */
    public function setIdentifier($identifier) {
        if (!empty($identifier) && $identifier !== $this->identifier) {
            $this->manager->closeConnectionByIdentifier($identifier);
            $this->identifier = $identifier;
        }
    }

    /**
     * @return ConnectionManager
     */
    public function getManager() {
        return $this->manager;
    }

    /**
     * @return Socket
     */
    public function getSocket() {
        return $this->socket;
    }

    /**
     * @return mixed
     */
    public function getIp() {
        return $this->socket->getIp();
    }

    /**
     * @return mixed
     */
    public function getPort() {
        return $this->socket->getPort();
    }

    /**
     * @return mixed
     */
    public function getLastActivityTimestamp() {
        return $this->lastActivityTimestamp;
    }

    /**
     * @throws Exception
     */
    public function process() {
        try {
            $data = $this->socket->read();
            if ($data == false || strlen($data) == 0)
                throw new Exception ("Socket connection closed");
            $this->lastActivityTimestamp = microtime(true);
            $this->manager->onConnectionDataReceived($this, $data);
        }
        catch (Exception $ex) {
            $this->close();
            throw $ex;
        }
    }

    /**
     * @param $data
     * @throws Exception
     */
    public function send($data) {
        try {
            $written = $this->socket->send($data);
            if ($written == false)
                throw new Exception ("Socket connection closed");
            $this->lastActivityTimestamp = microtime(true);
            $this->manager->onConnectionDataSent($this, $data);
        }
        catch (Exception $ex) {
            $this->close();
            throw $ex;
        }
    }

    /**
     *
     */
    public function close() {
        $this->socket->close();
        $this->manager->removeConnection($this);
    }

    /**
     * @return string
     */
    public function __toString() {
        return "[" . str_pad($this->id, 4, "0", STR_PAD_LEFT) . "] " . (!empty($this->identifier) ? str_pad($this->identifier, 5, "0", STR_PAD_LEFT) : "?????") . "@" . $this->socket;
    }
}