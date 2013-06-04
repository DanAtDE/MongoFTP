<?php
final class MongoFTP_Server
{
    private $_address;
    private $_port;
    private $_socket;
    private $_clients;
    private $_log;
    private $_db;
    private $_maxConnections;
    private $_maxConnectionsPerIP;

    public function __construct(
        \MongoDB $db,
        MongoFTP_Log $log,
        $address = '127.0.0.1',
        $port = 21,
        $maxConnections = 10,
        $maxConnectionsPerIP = 3
    )
    {
        $this->_db = $db;
        $this->_log = $log;
        $this->_address = $address;
        $this->_port = $port;
        $this->_maxConnections = $maxConnections;
        $this->_maxConnectionsPerIP = $maxConnectionsPerIP;
        $this->_socket = false;
        $this->_clients = Array();
    }

    public function run()
    {
        // assign listening socket
        $this->_socket = socket_create(AF_INET, SOCK_STREAM, 0);
        if ($this->_socket === false)
        {
            $this->_log->write('Unable to create socket: ' . socket_strerror(socket_last_error($this->_socket)));
            return;
        }

        // reuse listening socket address
        if (socket_set_option($this->_socket, SOL_SOCKET, SO_REUSEADDR, 1) === false)
        {
            $this->_log->write('Unable to set socket option: ' . socket_strerror(socket_last_error($this->_socket)));
            return;
        }

        // set socket to non-blocking
        if (socket_set_nonblock($this->_socket) === false)
        {
            $this->_log->write('Unable to set socket to nonblocking: ' . socket_strerror(socket_last_error($this->_socket)));
            return;
        }

        // bind the socket to our ip and port
        if (socket_bind($this->_socket, $this->_address, $this->_port) === false)
        {
            $this->_log->write('Unable to bind socket to address/port: ' . socket_strerror(socket_last_error($this->_socket)));
            return;
        }

        // listen on listening socket
        if (socket_listen($this->_socket) === false)
        {
            $this->_log->write('Unable to set socket for listening: ' . socket_strerror(socket_last_error($this->_socket)));
            return;
        }

        while (true)
        {
            // sockets we want to pay attention to
            $set_array = array_merge(array("server" => $this->_socket), $this->get_client_connections());

            $set = $set_array;
            $set_w = null;
            $set_e = null;
            if (socket_select($set, $set_w, $set_e, 1, 0) == 0)
                continue;

            // loop through sockets
            foreach($set as $sock)
            {
                $name = array_search ($sock, $set_array);

                if ($name === false)
                    continue;

                if ($name == "server")
                {
                    $conn = socket_accept($this->_socket);

                    if ($conn === false)
                    {
                        $this->_log->write('Unable to accept incoming connection to socket: ' . socket_strerror(socket_last_error($this->_socket)));
                        continue;
                    }

                    // add socket to client list and announce connection
                    $clientID = uniqid("client_");
                    $this->_clients[$clientID] = new MongoFTP_Client($conn, $clientID, $this->_db);

                    // if maxConnections exceeded disconnect client
                    if (count($this->_clients) > $this->_maxConnections)
                    {
                        $this->_clients[$clientID]->send("421 Maximum user count reached.");
                        $this->_clients[$clientID]->disconnect();
                        $this->remove_client($clientID);
                        continue;
                    }

                    // get a list of how many connections each IP has
                    $ip_pool = array();
                    foreach($this->_clients as $client)
                    {
                        $key = $client->addr;
                        $ip_pool[$key] = (array_key_exists($key, $ip_pool)) ? $ip_pool[$key] + 1 : 1;
                    }

                    // disconnect when maxConnectionsPerIP is exceeded for this client
                    if ($ip_pool[$key] > $this->_maxConnectionsPerIP)
                    {
                        $this->_clients[$clientID]->send("421 Too many connections from this IP.");
                        $this->_clients[$clientID]->disconnect();
                        $this->remove_client($clientID);
                        continue;
                    }

                    // everything is ok, initialize client
                    $this->_clients[$clientID]->init();

                    continue;

                }

                $clientID = $name;

                // client socket has incoming data
                $read = socket_read($sock, 1024);
                if ($read === false || $read === '')
                {
                    $this->_log->write('Unable to read connection to socket: ' . socket_strerror(socket_last_error($this->_socket)));
                    $this->remove_client($clientID);
                    continue;
                }

                // only want data with a newline
                if (strchr(strrev($read), "\n") === false)
                {
                    $this->_clients[$clientID]->buffer .= $read;
                    continue;
                }

                $this->_clients[$clientID]->buffer .= str_replace("\n", "", $read);

                if (! $this->_clients[$clientID]->interact())
                {
                    $this->_clients[$clientID]->disconnect();
                    $this->remove_client($clientID);
                }
                else
                {
                    $this->_clients[$clientID]->buffer = "";
                }
            }
        }
    }

    private function get_client_connections()
    {
        $conn = array();

        foreach($this->_clients as $clientID => $client)
        {
            $conn[$clientID] = $client->connection;
        }

        return $conn;
    }

    private function remove_client($clientID)
    {
        unset($this->_clients[$clientID]);
    }
}
