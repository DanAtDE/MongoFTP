<?php
final class MongoFTP_Client
{
    private $_db;
    var $id;
    var $connection;
    var $buffer;
    var $transfertype;
    var $_isLoggedIn = false;
    var $addr = null;
    var $port = null;
    var $pasv = false;
    var $data_addr;
    var $data_port;
    // passive ftp data socket and connection
    var $data_socket;
    var $data_conn;
    // active ftp data socket pointer
    var $data_fsp;
    var $return;

    private $_username = null;
    private $_user = null;
    private $_serverName;

    public function __construct($connection, $id, \MongoDB $db, $serverName = 'MongoFTP')
    {
        $this->_db = $db;
        $this->id = $id;
        $this->connection = $connection;
        $this->_serverName = $serverName;
        socket_getpeername($this->connection, $this->addr, $this->port);
    }

    public function init()
    {
        $this->buffer = '';
        $this->transfertype = "A";
        $this->send("220 {$this->_serverName}");
        if (! is_resource($this->connection)) die;
    }

    public function interact()
    {
        if (strlen($this->buffer) == 0)
            return true;

        $command  = trim(strtoupper(substr(trim($this->buffer), 0, 4)));
        $parameter = trim(substr(trim($this->buffer), 4));
        echo "{$command} : {$parameter}\n";
        switch ($command)
        {
            case 'QUIT':
                $this->send("221 Disconnected from {$this->_serverName} FTP Server. Have a nice day.");
                $this->disconnect();
                return false;
            case 'USER':
                $this->_username = $parameter;
                $this->send("331 Password required for {$this->_username}.");
                break;
            case 'PASS':
                $this->cmd_pass(md5($parameter));
                break;
            case 'LIST':
            case 'NLIST':
                $this->cmd_list();
                break;
            case 'PASV':
                $this->cmd_pasv();
                break;
            case 'PORT':
                $this->cmd_port($parameter);
                break;
            case 'SYST':
                $this->send("215 UNIX Type: L8");
                break;
            case 'TYPE':
                $this->cmd_type($parameter);
                break;
            case 'NOOP':
                $this->send("200 Nothing Done.");
                break;
            case 'RETR':
                $this->cmd_retr($parameter);
                break;
            case 'SIZE':
                $this->cmd_size($parameter);
                break;
            case 'STOR':
                $this->cmd_stor($parameter);
                break;
            case 'DELE':
                $this->cmd_dele($parameter);
                break;
            case 'HELP':
                $this->cmd_help();
                break;
            case 'APPE':
                $this->cmd_appe($parameter);
                break;
            case 'RNFR':
                $this->cmd_rnfr($parameter);
                break;
            case 'RNTO':
                $this->cmd_rnto($parameter);
                break;
            default:
                $this->send('502 Command not implemented.');
        }

        return true;
    }

    public function disconnect()
    {
        if (is_resource($this->connection))
            socket_close($this->connection);

        if ($this->pasv === false)
            return;

        if (is_resource($this->data_conn))
            socket_close($this->data_conn);
        if (is_resource($this->data_socket))
            socket_close($this->data_socket);
    }

    /*
    NAME: help
    SYNTAX: help
    DESCRIPTION: shows the list of available commands...
    NOTE: -
    */
    public function cmd_help()
    {
        $this->send(
            "214-{$this->_serverName}\n"
            ."214-Commands available:\n"
            ."214-APPE\n"
            ."214-DELE\n"
            ."214-HELP\n"
            ."214-LIST\n"
            ."214-NOOP\n"
            ."214-PASS\n"
            ."214-PASV\n"
            ."214-PORT\n"
            ."214-PWD\n"
            ."214-QUIT\n"
            ."214-RETR\n"
            ."214-RNFR\n"
            ."214-RNTO\n"
            ."214-SIZE\n"
            ."214-STOR\n"
            ."214-SYST\n"
            ."214-TYPE\n"
            ."214-USER\n"
            ."214 HELP command successful."
        );
    }

    /*
    NAME: pass
    SYNTAX: pass <password>
    DESCRIPTION: checks <password>, whether it's correct...
    NOTE: added authentication library support by Phanatic (26/12/2002)
    */
    function cmd_pass($password) {

        if ($this->_username === null) {
            $this->_isLoggedIn = false;
            $this->send("530 Not logged in.");
            return;
        }

        $user = $this->_db->selectCollection('users')->findOne(['username' => $this->_username]);
        if ($user === null)
        {
            $this->send("530 Not logged in.");
            $this->_isLoggedIn = false;
            return;
        }

        if ($user['password'] !== $password)
        {
            $this->send("530 Not logged in.");
            $this->_isLoggedIn = false;
            return;
        }

        $this->_user = $user;

        $this->send("230 User {$this->_user['username']} logged in from {$this->addr}.");
        $this->_isLoggedIn = true;
    }

    /*
    NAME: list
    SYNTAX: list
    DESCRIPTION: returns the filelist of the current directory...
    NOTE: should implement the <directory> parameter to be RFC-compilant...
    */
    public function cmd_list()
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $ret = $this->data_open();

        if (! $ret) {
            $this->send("425 Can't open data connection.");
            return;
        }

        $this->send("150 Opening  " . $this->transfer_text() . " data connection.");

        $gridFS = $this->_db->getGridFS();

        foreach ($gridFS->find(['owner' => $this->_user['username']]) as $gridFile)
        {
            $info = [
                'name' => $gridFile->getFilename(),
                'owner' => $gridFile->file['owner'],
                'group' => $gridFile->file['group'],
                'size' => $gridFile->getSize(),
                'time' => date('M d H:i', $gridFile->file['mtime']->sec),
                'perms' => '-rwx------',
            ];
            $formatted_list = sprintf("%-11s%-2s%-15s%-15s%-10s%-13s".$info['name'], $info['perms'], "1", $info['owner'], $info['group'], $info['size'], $info['time']);
            $this->data_send($formatted_list);
            $this->data_eol();
        }
        $this->data_close();
        $this->send("226 Transfer complete.");
    }

    /*
    NAME: dele
    SYNTAX: dele <filename>
    DESCRIPTION: delete <filename>...
    NOTE: authentication check added by Phanatic (26/12/2002)
    */
    public function cmd_dele($file)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();

        $result = $gridFS->remove(['filename' => $file, 'owner' => $this->_user['username']], ['w' => 1]);

        if ($result['n'] !== 1)
        {
            $this->send('550 Delete operation failed');
            return;
        }

        $this->send("250 Delete command successful.");
    }

    /*
    NAME: rnfr
    SYNTAX: rnfr <file>
    DESCRIPTION: sets the specified file for renaming...
    NOTE: -
    */
    public function cmd_rnfr($from)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();

        $gridFile = $gridFS->findOne(['filename' => $from, 'owner' => $this->_user['username']]);
        if ($gridFile === null)
        {
            $this->send("553 Requested action not taken.");
            return;
        }

        $id = $gridFile->file['_id'];

        $result = $gridFS->update(['_id' => $id], ['$set' => ['rnfr' => true]]);

        if ($result['n'] !== 1)
        {
            $this->send("553 Requested action not taken.");
            return;
        }

        $this->send("350 RNFR command successful.");
    }

    /*
    NAME: rnto
    SYNTAX: rnto <file>
    DESCRIPTION: sets the target of the renaming...
    NOTE: -
    */
    public function cmd_rnto($to)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();
        $gridFile = $gridFS->findOne(['rnfr' => true, 'owner' => $this->_user['username']]);
        if ($gridFile === null)
        {
            $this->send('550 Requested file action not taken (need an RNFR command).');
            return;
        }

        $id = $gridFile->file['_id'];

        $result = $gridFS->update(
            ['_id' => $id],
            ['$set' => ['filename' => $to, 'mtime' => new \MongoTimestamp()], '$unset' => ['rnfr' => 1] ]
        );

        if ($result['n'] !== 1)
        {
            $this->send("553 Requested action not taken.");
            return;
        }

        $this->send("250 RNTO command successful.");
    }

    /*
    NAME: stor
    SYNTAX: stor <file>
    DESCRIPTION: stores a local file on the server...
    NOTE: -
    */
    public function cmd_stor($file)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();

        $gridFile = $gridFS->findOne(['filename' => $file]);
        $idToDelete = null;
        if ($gridFile !== null)
        {
            $idToDelete = $gridFile->file['_id'];
            $result = $gridFS->update(
                ['_id' => $idToDelete],
                ['$set' => ['filename' => uniqid()]]
            );

            if ($result['n'] !== 1)
            {
                $this->send("550 Unable to overwrite existing file.");
            }
        }

        $this->send("150 File status okay; openening " . $this->transfer_text() . " connection.");
        $this->data_open();

        $tempFile = tempnam('/tmp', 'ftpUpload');

        $fp = fopen($tempFile, 'w');

        if ($this->pasv) {
            while(($buf = socket_read($this->data_conn, 512)) !== false)
            {
                if (strlen($buf) == 0)
                    break;

                fwrite($fp, $buf);
            }
        }
        else
        {
            while (!feof($this->data_fsp))
            {
                fwrite($fp, fgets($this->data_fsp, 16384));
            }
        }

        $this->data_close();

        $gridFS->storeFile($tempFile, ['filename' => $file, 'owner' => $this->_user['username'], 'group' => 'ftp', 'mtime' => new \MongoTimestamp()]);

        if ($idToDelete !== null)
        {
            $gridFS->remove(['_id' => $idToDelete]);
        }

        unlink($tempFile);

        $this->send("226 transfer complete.");
    }

    /*
    NAME: appe
    SYNTAX: appe <file>
    DESCRIPTION: if <file> exists, the recieved data should be appended to that file...
    NOTE: -
    */
    public function cmd_appe($file)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();

        $gridFile = $gridFS->findOne(['filename' => $from]);
        $idToDelete = null;
        $tempFile = tempnam('/tmp', 'ftpUpload');
        if ($gridFile !== null)
        {
            $idToDelete = $gridFile->file['_id'];
            $result = $gridFS->update(
                ['_id' => $idToDelete],
                ['$set' => ['filename' => uniqid()]]
            );

            if ($result['n'] !== 1)
            {
                $this->send("550 Unable to open file for appending.");
            }

            $gridFile->write($tempFile);
        }

        $this->send("150 File status okay; openening " . $this->transfer_text() . " connection.");

        $this->data_open();

        $fp = fopen($tempFile, 'a');

        if ($this->pasv)
        {
            while(($buf = socket_read($this->data_conn, 512)) !== false)
            {
                if (strlen($buf) == 0)
                    break;

                fwrite($fp, $buf);
            }
        }
        else
        {
            while (!feof($this->data_fsp))
            {
                fwrite($fp, fgets($this->data_fsp, 16384));
            }
        }

        $this->data_close();

        $gridFS->storeFile($tempFile, ['filename' => $file, 'owner' => 'cgray', 'group' => 'ftp', 'mtime' => new \MongoTimestamp()]);

        if ($idToDelete !== null)
        {
            $gridFS->remove(['_id' => $idToDelete]);
        }

        unlink($tempFile);

        $this->send("226 transfer complete.");
    }

    /*
    NAME: retr
    SYNTAX: retr <file>
    DESCRIPTION: retrieve a file from the server...
    NOTE: authentication check added by Phanatic (26/12/2002)
    */
    public function cmd_retr($file)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();

        $gridFile = $gridFS->findOne(['filename' => $file]);
        if ($gridFile === null)
        {
            $this->send("553 Requested action not taken.");
            return;
        }

        $size = $gridFile->getSize();

        $this->data_open();
        $this->send("150 " . $this->transfer_text() . " connection for " . $file . " (" . $size . " bytes).");

        $this->data_send($gridFile->getBytes());

        $this->send("226 transfer complete.");
        $this->data_close();
    }

    public function cmd_pasv()
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $pool = &$this->CFG->pasv_pool;

        if ($this->pasv) {
            if (is_resource($this->data_conn)) socket_close($this->data_conn);
            if (is_resource($this->data_socket)) socket_close($this->data_socket);

            $this->data_conn = false;
            $this->data_socket = false;

            if ($this->data_port) $pool->remove($this->data_port);

        }

        $this->pasv = true;

        $low_port = $this->CFG->low_port;
        $high_port = $this->CFG->high_port;

        $try = 0;

        if (($socket = socket_create(AF_INET, SOCK_STREAM, 0)) < 0) {
            $this->send("425 Can't open data connection.");
            return;
        }

        // reuse listening socket address
        if (! @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1)) {
            $this->send("425 Can't open data connection.");
            return;
        }

        for ($port = $low_port; $port <= $high_port && $try < 4; $port++) {
            if (! $pool->exists($port)) {
                $try++;

                $c = socket_bind($socket, $this->CFG->_address, $port);

                if ($c >= 0) {
                    $pool->add($port);
                    break;
                }
            }
        }

        if (! $c) {
            $this->send("452 Can't open data connection.");
            return;
        }

        socket_listen($socket);

        $this->data_socket = $socket;
        $this->data_port = $port;

        $p1 = $port >>  8;
        $p2 = $port & 0xff;

        $tmp = str_replace(".", ",", $this->CFG->_address);
        $this->send("227 Entering Passive Mode ({$tmp},{$p1},{$p2}).");
    }

    public function cmd_port($parameter)
    {
        if (! $this->_isLoggedIn) {
            $this->send('530 Not logged in.');
            return;
        }

        $data = explode(',', $parameter);

        if (count($data) != 6)
        {
            $this->send('500 Wrong number of Parameters.');
            return;
        }

        $p2 = array_pop($data);
        $p1 = array_pop($data);

        $port = ($p1 << 8) + $p2;

        foreach($data as $ip_seg)
        {
            if (! is_numeric($ip_seg) || $ip_seg > 255 || $ip_seg < 0)
            {
                $this->send('500 Bad IP address ' . implode('.', $data) . '.');
                return;
            }
        }

        $ip = implode('.', $data);

        if (! is_numeric($p1) || ! is_numeric($p2) || ! $port) {
            $this->send('500 Bad Port number.');
            return;
        }

        echo "PORT IS $port\n";

        $this->data_addr = $ip;
        $this->data_port = $port;

        $this->send('200 PORT command successful.');
    }

    public function cmd_type($type)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        if (strlen($type) != 1) {
            $this->send("501 Syntax error in parameters or arguments.");
        } elseif ($type != "A" && $type != "I") {
            $this->send("501 Syntax error in parameters or arguments.");
        } else {
            $this->transfertype = $type;
            $this->send("200 type set.");
        }
    }

    public function cmd_size($file)
    {
        if (! $this->_isLoggedIn) {
            $this->send("530 Not logged in.");
            return;
        }

        $gridFS = $this->_db->getGridFS();

        $gridFile = $gridFS->findOne(['filename' => $file]);
        if ($gridFile === null)
        {
            $this->send("553 Requested action not taken.");
            return;
        }

        $this->send('213 ' . $gridFile->getSize());
    }

    function data_open() {

        if ($this->pasv) {

            if (! $conn = @socket_accept($this->data_socket)) {

                return false;
            }

            if (! socket_getpeername($conn, $peer_ip, $peer_port)) {
                $this->data_conn = false;
                return false;
            }

            $this->data_conn = $conn;

        } else {

            $fsp = fsockopen($this->data_addr, $this->data_port, $errno, $errstr, 30);

            if (! $fsp) {
                return false;
            }

            $this->data_fsp = $fsp;
        }

        return true;
    }

    function data_close() {
        if (! $this->pasv) {
            if (is_resource($this->data_fsp)) fclose($this->data_fsp);
            $this->data_fsp = false;
        } else {
            socket_close($this->data_conn);
            $this->data_conn = false;
        }
    }

    function data_send($str) {

        if ($this->pasv) {
            socket_write($this->data_conn, $str, strlen($str));
        } else {
            fputs($this->data_fsp, $str);
        }
    }

    function data_read() {
        if ($this->pasv) {
            return socket_read($this->data_conn, 1024);
        } else {
            return fread($this->data_fsp, 1024);
        }
    }

    function data_eol() {
        $eol = ($this->transfertype == "A") ? "\r\n" : "\n";
        $this->data_send($eol);
    }

    public function send($str)
    {
        socket_write($this->connection, $str . "\n");
    }

    function transfer_text() {
        return ($this->transfertype == "A") ? "ASCII mode" : "Binary mode";
    }

}
