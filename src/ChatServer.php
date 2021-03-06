<?php

namespace Chat;

use Predis\Client;
use Swoole\Coroutine\Redis;
use Swoole\WebSocket\Server;

class ChatServer
{
    private $server;
    private $config;
    private $table;
    protected $redis;

    public function __construct()
    {
        $this->createTable();
        $this->config = Config::instance();
    }

    /**
     *运行服务器
     */
    public function run()
    {
        $this->server = new Server($this->config['chat']['ip'], $this->config['chat']['port']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);

        $this->redisConnect();  //redis的连接
        $this->server->start();
    }

    /*
     *redis连接
     */
    public function redisConnect()
    {
        $ser = [
            'host' => '127.0.0.1',
            'port' => 6379,
        ];
        $this->redis = new Client($ser);
    }

    /**
     * 连接客户端
     * @param $ws
     * @param $request
     */
    public function onOpen($ws, $request)
    {
        $user = [
            'fd' => $request->fd,
            'name' => $this->config['chat']['name'][array_rand($this->config['chat']['name'])],
            'avatar' => $this->config['chat']['avatar'][0],
        ];
        $this->table->set($request->fd, $user);
        $ws->push($request->fd, json_encode([
            'user' => $user,
            'all' => $this->allUser(),
            'type' => 'openSuccess',
        ]));

//        $this->redis->set($request->fd, json_encode($user));
//        var_dump($this->redis->get($request->fd));

        //群发消息
        $this->pushMessage($ws, $user['name'] . '进入聊天室', 'open', $request->fd);
    }

    /*
     * 所有用户
     */
    public function allUser()
    {
        $users = [];
        foreach ($this->table as $item) {
            $users[] = $item;
        }
        return $users;
    }

    /**
     * 获取客户端信息
     * @param $ws
     * @param $frame
     */
    public function onMessage($ws, $frame)
    {
        $user = $this->table->get($frame->fd);
        $data = json_decode($frame->data);
        switch ($data->type) {
            case "public":
                $client_message = [
                    'user' => $user,
                    'data' => $data->data->message,
                ];
                $this->pushMessage($ws, json_encode($client_message), 'public_message', $frame->fd);
                break;
            case "private":
                $client_message = [
                    'message' => [
                        'user' => $user,
                        'data' => $data->data->message,
                    ],
                    'type' => 'private_message',
                ];
                $ws->push($frame->fd, json_encode($client_message)); //显示自己发送的
                $ws->push($data->data->to_user_fd, json_encode($client_message)); //发送给对方
                break;
        }
    }

    /**
     * 关闭连接
     * @param $ws
     * @param $fd
     */
    public function onClose($ws, $fd)
    {
        $user = $this->table->get($fd);
        $this->pushMessage($ws, $user['name'] . '离开聊天室', 'close', $fd);
        $this->table->del($fd);
    }

    /*
   * 群发消息
   */
    public function pushMessage(Server $server, $message, $messageType, $frameFd)
    {
        $dateTime = date('Y-m-d H:i:s', time());
        $user = $this->table->get($frameFd);
        foreach ($this->table as $row) {
            if ($frameFd == $row['fd'] && $messageType != 'public_message') {
                continue;
            }
            $server->push($row['fd'], json_encode([
                    'type' => $messageType,
                    'message' => $message,
                    'datetime' => $dateTime,
                    'user' => $user,
                ])
            );
        }
    }

    /**
     *创建共享数据table
     */
    public function createTable()
    {
        $this->table = new \swoole_table(1024);
        $this->table->column('fd', \swoole_table::TYPE_INT);
        $this->table->column('name', \swoole_table::TYPE_STRING, 255);
        $this->table->column('avatar', \swoole_table::TYPE_STRING, 255);
        $this->table->create();
    }
}
