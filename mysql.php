<?php

/*
 * @author Javad
 */

date_default_timezone_set('Asia/Tehran');

use danog\{
    MadelineProto\EventHandler,
    MadelineProto\Settings,
    Loop\Generic\GenericLoop,
    MadelineProto\Logger,
    MadelineProto\Settings\Database\Mysql as DbSql,
};
use Amp\Mysql\{
    ConnectionConfig,
    Pool,
    function pool as pooling,
};

require "config.php";
require "vendor/autoload.php";

class JavadHandler extends EventHandler
{
    public static Pool $db;
    public static int  $start;

    public function getReportPeers()
    {
        return REPORT_PEER;
    }

    public function seenChats()
    {
        foreach (yield $this->getDialogs() as $dialog) {
            try {
                if ($dialog['_'] == "peerChannel") {
                    yield $this->channels->readHistory(['channel' => $dialog]);
                    yield $this->messages->readMentions(['peer' => $dialog]);
                }
                else {
                    yield $this->messages->readHistory(['peer' => $dialog]);
                }
                yield $this->messages->readReactions(['peer' => $dialog]);
            } catch (Throwable) {
            }
        }

        return 5 * 60 * 1000; // 5 minutes
    }

    public function onStart()
    {
        static::$start = time();
        static::$db    = pooling(
            new ConnectionConfig(
                host    : DB_HOST,
                user    : DB_USER,
                password: DB_PASS,
                database: DB_NAME,
                charset : DB_CHARSET,
                collate : DB_COLLATE,
            )
        );

        $seen = new GenericLoop([$this, 'seenChats'], 'seenAllChats');
        $seen->start();
    }

    public function onUpdateNewChannelMessage(array $update): Generator
    {
        return $this->onUpdateNewMessage($update);
    }

    /*    try {
            # Query simple
            yield static::$db->query("INSERT INTO `users`(`user_id`) VALUES(1), (2)");

            # Execute simple
            yield static::$db->execute("INSERT INTO `chats`(`chat_id`, `chat_type`) VALUES(?, ?)", [-100123456789, 'channel']);

            # Prepare simple
            $statement = yield static::$db->prepare("INSERT INTO `chats`(`chat_id`, `chat_type`) VALUES(?, ?)");
            $promises = [];

            foreach (range(1, 10) as $value) {
                $promises[] = $statement->execute([$value, 'supergroup']);
            }

            // Run all statements
            yield $promises;

            # Prepare + Bind
            $statement = yield static::$db->prepare("INSERT INTO `chats`(`chat_id`, `chat_type`) VALUES(:chat_id, :chat_type)");
            $promises = [];

            foreach (range(1, 10) as $value) {
                $statement->bind('chat_id', $value);
                $statement->bind('chat_type', 'supergroup');
                $promises[] = $statement->execute();
            }

            // Run all statements
            yield $promises;


            ### SAMPLE OF SELECT QUERY

            # 1. execute (recommended) - [safe]
            $select = yield static::$db->execute("SELECT * FROM `users`");
            yield $select->advance();
            $fetch = $select->getCurrent();
            var_dump($fetch);

            # 2. execute (recommended) - [safe]
            $select = yield static::$db->execute("SELECT * FROM `users` WHERE `user_id` = ?", [1391106072]);
            yield $select->advance();
            $fetch = $select->getCurrent();
            var_dump($fetch);

            # 3. execute (recommended) - [safe]
            $select = yield static::$db->execute("SELECT * FROM `users` WHERE `user_id` = :user_id", [
                'user_id' => 1391106072
            ]);
            yield $select->advance();
            $fetch = $select->getCurrent();
            var_dump($fetch);

            # 4. query (not recommended) - it has sql injections bug
            $select = yield static::$db->query("SELECT * FROM `users`");
            yield $select->advance();
            $fetch = $select->getCurrent();
            var_dump($fetch);

            # 5. prepare [safe]
            $statement = yield static::$db->prepare("SELECT * FROM `users` WHERE `user_id` = ?");

            foreach (range(1, 5) as $user) {
                $select = yield $statement->execute([$user]);
                yield $select->advance();
                var_dump($select->getCurrent());
            }

            # 6. prepare [safe]
            $statement = yield static::$db->prepare("SELECT * FROM `users` WHERE `user_id` = :user_id");

            foreach (range(1, 5) as $user) {
                $select = yield $statement->execute([
                    'user_id' => $user,
                ]);

                yield $select->advance();
                var_dump($select->getCurrent());
            }

            # 7. prepare + bind [safe]
            $statement = yield static::$db->prepare("SELECT * FROM `users` WHERE `user_id` = :user_id");

            foreach (range(1, 5) as $user) {
                $statement->bind('user_id', $user);
                $select = yield $statement->execute();

                yield $select->advance();
                var_dump($select->getCurrent());
            }
        } catch (Throwable $e) {
            echo (string) $e;
        }
    }*/

    public function onUpdateNewMessage(array $update): Generator
    {
        try {
            if ($update['message']['_'] !== 'message') {
                return;
            }
            $message   = $update['message'];
            $peer      = yield $this->getId($message);
            $userId    = $message['from_id']['user_id'] ?? 0;
            $textMsg   = $message['message'] ?? "";
            $messageId = $message['id'] ?? 0;

            // is outgoing message or not?
            if ($message['out']) {
                $text = strtolower($textMsg);

                if ($text == '/ping') {
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "Bot is Online!",
                    ]);
                }

                elseif ($text == '/help') {
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "/add (userId)
Add a user to database
/remove (userId)
Remove a user from database
/users
list of all users
/exists (userId)
check user status
/ping
am I alive?",
                    ]);
                }
                elseif (preg_match('#^/add +(\d+)#si', $textMsg, $match)) {
                    $user = $match[1];
                    // execute has not any sql injection . . .
                    yield static::$db->execute("INSERT INTO `users`(`userId`) VALUES(?)", [$user]);
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "User Added!",
                    ]);
                }

                elseif (preg_match('#^/remove +(\d+)#si', $textMsg, $match)) {
                    $user = $match[1];
                    // execute has not sql injections . . .
                    yield static::$db->execute("DELETE FROM `users` WHERE `userId` = :userId", [
                        'userId' => $user,
                    ]);
                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "User Deleted!",
                    ]);
                }

                elseif ($text == '/users') {
                    // MAKE SURE! Query has sql injections . . .
                    $users = yield static::$db->query("SELECT * FROM `users`");
                    $list  = [];

                    while (yield $users->advance()) {
                        $fetch  = $users->getCurrent();
                        $list[] = $fetch['userId'];
                    }

                    yield $this->messages->editMessage([
                        'peer'    => $peer,
                        'id'      => $messageId,
                        'message' => "There is " . count($list) . " Users: " . implode("\n", $userId),
                    ]);
                }

                elseif (preg_match('#^/exists +(\d+)#si', $textMsg, $match)) {
                    $user   = $match[1];
                    $select = yield static::$db->execute("SELECT * FROM `users` WHERE `userId` = :userId", [
                        'userId' => $user,
                    ]);
                    // OR You can use this instead of execute method:
                    // $select = yield static::$db->query("SELECT * FROM `users` WHERE `userId` = $userId");

                    // Advance method checks is there any row to fetch or not?
                    if (yield $select->advance()) {
                        // fetch the row data:
                        $fetch = $select->getCurrent();

                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "User exists!\n\n" . var_export($fetch, TRUE),
                        ]);
                    }
                    else {
                        yield $this->messages->editMessage([
                            'peer'    => $peer,
                            'id'      => $messageId,
                            'message' => "User not exists!",
                        ]);
                    }
                }
            }
        } catch (Throwable $e) {
            $this->report("An error occurred:\n\n$e");
        }
    }
}

is_dir('session') || mkdir('session');
$settings = new Settings;
$db       = $settings->setDb((new DbSql)
    ->setUri(SESSION_URi)
    ->setUsername(SESSION_USER)
    ->setPassword(SESSION_PASS)
    ->setDatabase(SESSION_DATABASE)
    ->setMaxConnections(1));

$settings->getAppInfo()
    ->setApiId(API_ID)
    ->setApiHash(API_HASH);

$settings->getPeer()
    ->setFullFetch(FALSE)
    ->setCacheAllPeersOnStartup(FALSE)
    ->setFullInfoCacheTime(0);

$settings->getLogger()
    ->setLevel(Logger::LEVEL_ULTRA_VERBOSE);

JavadHandler::startAndLoop('session/' . SESSION_NAME . '.session', $settings);
