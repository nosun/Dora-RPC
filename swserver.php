<?php

/**
 * Class DoraRPCServer
 * https://github.com/xcl3721/Dora-RPC
 * by 蓝天 http://weibo.com/thinkpc
 */
abstract class DoraRPCServer
{
    const SW_SYNC_SINGLE = 'SSS';
    const SW_RSYNC_SINGLE = 'SRS';

    const SW_SYNC_MULTI = 'SSM';
    const SW_RSYNC_MULTI = 'SRM';

    private $server = null;
    private $taskInfo = array();

    final function __construct($ip = "0.0.0.0", $port = 9567)
    {
        $this->server = new swoole_server($ip, $port);
        $config = array(
            'open_length_check' => 1,
            'dispatch_mode' => 3,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 4,
            'package_max_length' => 1024 * 1024 * 2,
            'buffer_output_size' => 1024 * 1024 * 3,
            'pipe_buffer_size' => 1024 * 1024 * 32,
            'open_tcp_nodelay' => 1,
            'heartbeat_check_interval' => 5,
            'heartbeat_idle_time' => 10,

            'reactor_num' => 32,
            'worker_num' => 40,
            'task_worker_num' => 20,

            'max_request' => 0, #必须设置为0否则并发任务容易丢
            'task_max_request' => 4000,

            'backlog' => 2000,
            'log_file' => '/tmp/sw_server.log',
            'task_tmpdir' => '/tmp/swtasktmp/',

            //'daemonize' => 1,
        );
        $this->server->set($config);

        $this->server->on('connect', array($this, 'onConnect'));
        $this->server->on('workerstart', array($this, 'onWorkerStart'));
        $this->server->on('receive', array($this, 'onReceive'));
        $this->server->on('workererror', array($this, 'onWorkerError'));
        $this->server->on('task', array($this, 'onTask'));
        $this->server->on('close', array($this, 'onClose'));
        $this->server->on('finish', array($this, 'onFinish'));

        $this->server->start();
    }

    final function onConnect($serv, $fd)
    {
        $this->taskInfo[$fd] = array();
    }

    final function onWorkerStart($server, $worker_id)
    {
        swoole_set_process_name("swworker|{$worker_id}");
    }

    final function onReceive(swoole_server $serv, $fd, $from_id, $data)
    {
        $reqa = $this->packDecode($data);
        #decode error
        if ($reqa["code"] != 0) {
            $req = $this->packEncode($reqa);
            $serv->send($fd, $req);
            return true;
        } else {
            $req = $reqa["data"];
        }

        #api not set
        if (!is_array($req["api"]) && count($req["api"])) {
            $pack = $this->packFormat("param api is empty", 100003);
            $pack = $this->packEncode($pack);
            $serv->send($fd, $pack);
            return true;
        }

        $this->taskInfo[$fd] = $req;

        $task = array(
            "type" => $this->taskInfo[$fd]["type"],
            "guid" => $this->taskInfo[$fd]["guid"],
            "fd" => $fd,
        );

        echo "#{$serv->worker_id}>> received length=" . strlen($data) . "\n";

        switch ($this->taskInfo[$fd]["type"]) {

            case self::SW_SYNC_SINGLE:
                $task["api"] = $this->taskInfo[$fd]["api"]["one"];
                $taskid = $serv->task($task);

                $this->taskInfo[$fd]["task"][$taskid] = "one";
                return true;
                break;
            case self::SW_RSYNC_SINGLE:
                $task["api"] = $this->taskInfo[$fd]["api"]["one"];
                $serv->task($task);

                $pack = $this->packFormat("已经成功投递", 100001);
                $pack["guid"] = $task["guid"];
                $pack = $this->packEncode($pack);
                $serv->send($fd, $pack);

                unset($this->taskInfo[$fd]);
                return true;

                break;

            case self::SW_SYNC_MULTI:
                foreach ($req["api"] as $k => $v) {
                    $task["api"] = $this->taskInfo[$fd]["api"][$k];
                    $taskid = $serv->task($task);
                    $this->taskInfo[$fd]["task"][$taskid] = $k;
                }

                return true;
                break;
            case self::SW_RSYNC_MULTI:
                foreach ($req["api"] as $k => $v) {
                    $task["api"] = $this->taskInfo[$fd]["api"][$k];
                    $serv->task($task);
                }
                $pack = $this->packFormat("已经成功投递", 100001);
                $pack["guid"] = $task["guid"];
                $pack = $this->packEncode($pack);

                $serv->send($fd, $pack);
                unset($this->taskInfo[$fd]);

                return true;
                break;
            default:
                $pack = $this->packFormat("未知类型任务", 100002);
                $pack = $this->packEncode($pack);

                $serv->send($fd, $pack);
                unset($this->taskInfo[$fd]);
                return true;
        }

        return true;
    }

    final function onTask($serv, $task_id, $from_id, $data)
    {
        //$data["result"] = array("yes" => "ok");
        swoole_set_process_name("phptask|{$task_id}|" . $data["api"]["name"] . "");
        try {
            $data["result"] = $this->doWork($data);
        } catch (Exception $e) {
            $data["result"] = $this->packFormat($e->getMessage(), $e->getCode());
            return $data;
        }
        return $data;
    }

    abstract public function doWork($param);


    final function onWorkerError(swoole_server $serv, $worker_id, $worker_pid, $exit_code)
    {
        $this->log("WorkerError", array($this->taskInfo, $serv, $worker_id, $worker_pid, $exit_code));
    }

    private function log($type, $content, $file = "sw_error.log")
    {
        $result = date("Y-m-d H:i:s") . "|$type|" . json_encode($content) . "\r\n";
        file_put_contents("/tmp/" . $file, $result, FILE_APPEND);
    }

    final function onFinish($serv, $task_id, $data)
    {
        $fd = $data["fd"];

        if (!isset($this->taskInfo[$fd]) || !$data["result"]) {
            unset($this->taskInfo[$fd]);
            return true;
        }

        $key = $this->taskInfo[$fd]["task"][$task_id];
        $this->taskInfo[$fd]["result"][$key] = $data["result"];

        unset($this->taskInfo[$fd]["task"][$task_id]);

        switch ($data["type"]) {

            case self::SW_SYNC_SINGLE:
                $packet = $this->packFormat("OK", 0, $data["result"]);
                $packet["guid"] = $this->taskInfo[$fd]["guid"];
                $packet = $this->packEncode($packet);
                $serv->send($fd, $packet);
                unset($this->taskInfo[$fd]);
                return true;
                break;

            case self::SW_SYNC_MULTI:
                if (count($this->taskInfo[$fd]["task"]) == 0) {
                    $packet = $this->packFormat("OK", 0, $this->taskInfo[$fd]["result"]);
                    $packet["guid"] = $this->taskInfo[$fd]["guid"];
                    $packet = $this->packEncode($packet);
                    $serv->send($fd, $packet);
                    unset($this->taskInfo[$fd]);
                    return true;
                } else {
                    return true;
                }
                break;

            default:
                unset($this->taskInfo[$fd]);
                return true;
                break;
        }

    }

    final function onClose(swoole_server $server, $fd, $from_id)
    {
        unset($this->taskInfo[$fd]);
    }


    private function packEncode($data)
    {
        $sendStr = serialize($data);
        $sendStr = pack('N', strlen($sendStr)) . $sendStr;
        return $sendStr;
    }

    private function packDecode($str)
    {
        $header = substr($str, 0, 4);
        $result = substr($str, 4);

        $len = unpack("Nlen", $header);

        if ($len["len"] != strlen($result)) {
            //结果长度不对
            echo "error length...\n";
            return $this->packFormat("包长度非法", 100007);
        }
        $result = unserialize($result);

        return $this->packFormat("OK", 0, $result);
    }

    private function packFormat($msg = "OK", $code = 0, $data = array())
    {
        $pack = array(
            "code" => $code,
            "msg" => $msg,
            "data" => $data,
        );
        return $pack;
    }


    final function __destruct()
    {
        $this->server->shutdown();
    }


}
