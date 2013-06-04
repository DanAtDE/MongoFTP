<?php
final class MongoFTP_Log
{
    const COLLECTION_NAME = 'logs';

    private $_db;

    public function __construct(\MongoDB $db)
    {
        $this->_db = $db;
    }

    public function write($message)
    {
        $entry = [
            'message' => $message,
            'timestamp' => new \MongoTimestamp(),
        ];

        $this->_db->selectCollection(self::COLLECTION_NAME)->insert($entry);
    }
}
