<?php
class ServerState {
    const STOPPED = 0;
    const RUNNING = 1;
}

class Server {
    private $sock;
    private $errorcode = 0;
    private $errormsg = '';
    private $backlog = 100;
    private $connections;
    private $startTime = 0;
    private $state = ServerState::STOPPED;
    private $wrapper;
    private $ssl_cert_file;
    private $ssl_privkey_file;
    private $ssl_passphrase;
    private $timers;

    public $ip = '';
    public $port = 0;
    public $log;

    public function __construct($ip = '0.0.0.0', $port = 65000, $ssl = array()) {
        $this->connections = array();//new SplObjectStorage();
        $this->log = new FileLog();
        $this->ip = $ip;
        $this->port = $port;
        $this->startTime = time();
        $this->timers = array();

        $cert_file = !empty($ssl['cert_file']) ? $ssl['cert_file'] : '';
        $privkey_file = !empty($ssl['privkey_file']) ? $ssl['privkey_file'] : '';
        $pass = !empty($ssl['passphrase']) ? $ssl['passphrase'] : '';

        if (!empty($cert_file) && $cert_file !== null) {//this stupid check is because HHVM is a moron
            $this->ssl_cert_file = $cert_file;
        } else {
            $this->ssl_cert_file = '';
        }

        if (!empty($privkey_file) && $privkey_file !== null) {//this stupid check is because HHVM is a moron
            $this->ssl_privkey_file = $privkey_file;
        } else {
            $this->ssl_privkey_file = '';
        }

        if (!empty($pass) && $pass !== null) {//this stupid check is because HHVM is a moron
            $this->ssl_passphrase = $pass;
        } else {
            $this->ssl_passphrase = '';
        }
    }

    public function __destruct() {
        $this->stop();
    }

    public function loadWrapper($wrapper = 'RawTcp', $wrapper_config = array()) {
        $this->wrapper = new $wrapper($wrapper_config, $this);
        $this->wrapper->init();
        return $this;
    }

    public function getWrapper() {
        return $this->wrapper;
    }

    public function isSSL() {
        return !empty($this->ssl_cert_file) && !empty($this->ssl_privkey_file);
    }

    public function isRunning() {
        return $this->state == ServerState::RUNNING;
    }

    public function getStartTime() {
        return $this->startTime;
    }

    public function start() {
        $this->startTime = time();

        //$context = stream_context_create(array(
        //    'socket' => array(
        //        'backlog' => $this->backlog
        //    )
        //));

        $context = stream_context_create();

        stream_context_set_option($context, 'socket', 'so_reuseport', true);

        if ($this->isSSL()) {
            stream_context_set_option($context, 'ssl', 'local_cert', $this->ssl_cert_file);
            stream_context_set_option($context, 'ssl', 'local_pk', $this->ssl_privkey_file);
            if ($this->ssl_passphrase) {
                stream_context_set_option($context, 'ssl', 'passphrase', $this->ssl_passphrase);
            }
            stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
            stream_context_set_option($context, 'ssl', 'verify_peer', false);
        }

        $this->sock = stream_socket_server('tcp://' . $this->ip . ':' . $this->port, $this->errorcode, $this->errormsg, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        if ($this->sock === false) {
            $this->saveSocketError();
            exit(1);
        }

        $this->log->debug("Server is listening on $this->ip:$this->port");

        $this->state = ServerState::RUNNING;

        return $this;
    }

    public function setTimer($timer) {
        $this->timers[$timer->id] = $timer;
    }

    public function removeTimer($timerId) {
        if (isset($this->timers[$timerId])) {
            unset($this->timers[$timerId]);
        }
    }

    public function timerTick() {
        foreach ($this->timers as $k => $timer) {
            if ($timer->hasToExecute()) {
                $timer->execute();

                if ($timer->type == TimerType::TIMEOUT) {
                    unset($this->timers[$k]);
                }
            }
        }
    }

    public function loop() {
        if ($this->state !== ServerState::RUNNING) return 0;

        $this->timerTick();

        $counter = 0;

        $con = new Connection(@stream_socket_accept($this->sock, 0), $this);
        while ($con->isValid() && $counter++ < 3000) {
            if ($this->isSSL()) {
                $con->enableSSL();
            }

            $this->wrapper->onConnect($con);

            $this->connections[$con->id] = $con;
            //$this->log->debug(date('[Y-m-d H:i:s]') . " Client connected from $con->ip");

            $con = new Connection(@stream_socket_accept($this->sock, 0), $this);
        }

        foreach ($this->connections as $con) {
            $con->listen();
        }

        $hasWork = false;

        foreach ($this->connections as $con) {
            try {
                $con->flush();

                if ($con->hasWork()) {
                    $hasWork = true;
                }
            } catch (SocketWriteException $e) {
                //TODO: Do some sort of logging
            }
        }

        return $hasWork || $counter > 0 ? 1 : 0;
    }

    public function printUptime() {
        $uptime = time() - $this->startTime;
        $hours = ($uptime > 3600) ? (int)($uptime/3600) : 0;
        $uptime -= $hours * 3600;
        $minutes = ($uptime > 60) ? (int)($uptime/60) : 0;
        $uptime -= $minutes*60;
        $seconds = $uptime;
        $this->log->debug(sprintf("[%s:%d] Current uptime is %sh %sm %ss", $this->ip, $this->port, $hours, $minutes, $seconds));
    }

    public function printStatus() {
        $this->log->debug(sprintf("Currently active connections: %d", count($this->connections)));
    }

    public function onDisconnect($con) {
        if (isset($this->connections[$con->id])) {
            unset($this->connections[$con->id]);
        }
        //$this->log->debug("Client has disconnected");
    }

    public function stop() {
        if (!$this->isRunning()) return;

        $this->log->debug("Closing connections...");

        if ($this->wrapper !== null) {
            $this->wrapper->onStop();
        }

        foreach($this->connections as $con) {
            $con->close();
        }

        fclose($this->sock);
        $this->state = ServerState::STOPPED;

        $this->log->debug("Server is stopped");
    }

    private function saveSocketError() {
        $this->log->error(date('[j M Y : H:i:s]')." ($this->errorcode) $this->errormsg");
    }
}
