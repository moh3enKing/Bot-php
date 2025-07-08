<?php
require 'vendor/autoload.php';
use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

$MONGO_URI = getenv('MONGO_URI') ?: 'mongodb+srv://mohsenfeizi1386:p%40ssw0rd%279%27%21@cluster0.ounkvru.mongodb.net/?retryWrites=true&w=majority&appName=Cluster0';
$mongo = new Client($MONGO_URI, [
    'tls' => true,
    'connectTimeoutMS' => 60000,
    'serverSelectionTimeoutMS' => 60000,
    'socketTimeoutMS' => 60000
]);
$db = $mongo->chatroom_db;
$messages_collection = $db->messages;
$threshold = new UTCDateTime((time() - 24 * 3600) * 1000);
$messages_collection->deleteMany(['timestamp' => ['$lte' => $threshold]]);
file_put_contents('bot.log', date('Y-m-d H:i:s') . ' - Cleaned old messages' . PHP_EOL, FILE_APPEND);
?>
