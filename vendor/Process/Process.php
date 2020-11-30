<?php

namespace Process;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * Class Process
 * @author dyl
 * @date 2019-12-26
 */
class Process
{
    private $pid_children = array();
    private $master_pid;
    private $master_status = 1;
    private $max_worker_num;
    private $process_id;

    protected $pid_file_path;
    protected $output = '/dev/null';
    protected $logger;

    protected $debug = FALSE;
    protected $rebooter;

    public function __construct(
        $process_id,
        $user = 'root',
        $pid_dir = '/tmp/pid_file',
        $log_dir = '/tmp/',
        $max_worker = 200
    )
    {
        $this->process_id = $process_id;
        $this->user = $user;
        $this->pid_file_path = $pid_dir . '/' . $this->process_id . '-parent-process.pid';
        $this->max_worker_num = $max_worker;
        $this->checkPcntl();

        //register a logger
        $this->logger = new Logger($this->process_id . '|Process logger');
        $this->logger->pushHandler(new StreamHandler($log_dir . $this->process_id . '_process.log'));
    }

    public function setRebooter($obj)
    {
        $this->rebooter = $obj;
    }

    /**
     * 检查环境是否支持pcntl支持
     */
    public function checkPcntl()
    {
        if (!function_exists('pcntl_signal_dispatch')) {
            // PHP < 5.3 uses ticks to handle signals instead of pcntl_signal_dispatch
            declare(ticks=1);
        } else {
            pcntl_signal_dispatch();
        }
    }

    public function registerShutdownError()
    {
        register_shutdown_function(function (){
            if( $error = error_get_last() ) {
                $this->logger->err("Running Error", $error);
            }
        });
    }

    /**
     * @param $master_closure
     * @param $worker_closure
     * @param bool $is_asynchronous  是否异步参数 默认 false：同一批工作进程未结束不能调起新工作进程（适用于worker进程依赖为主进程分发任务模式），
     *                                                 true：允许分时调起工作进程（适用于worker进程单独工作，无需依赖master进程分发任务模式）
     */
    public function start($master_closure, $worker_closure, $is_asynchronous = false)
    {
        $this->registerShutdownError();
        $this->command();
        $this->daemonize();
        $this->checkPcntl();
        $this->installSignal();
        $this->master_pid = posix_getpid();
        echo "master_id: " . $this->master_pid . PHP_EOL;
        while ($this->master_status) {
            if ($this->master_pid != posix_getpid()) {
                exit();
            }

            if (!$is_asynchronous && 0 < count($this->pid_children)) {
                sleep(2);
                continue;
            } else {
                sleep(1);
            }
            $run_worker_list = array();
            $shut_worker_list = array();
            call_user_func_array($master_closure, [&$run_worker_list, &$shut_worker_list, $this->debug]);
            if( !empty($shut_worker_list) ) {
                $pid_arr = array_flip($this->pid_children);
                foreach ($shut_worker_list as $worker_id) {
                    if( in_array($worker_id, $this->pid_children) ) {
                        posix_kill($pid_arr[$worker_id], SIGUSR1);
                    }
                }
            }
            if (empty($run_worker_list)) {
                sleep(2);
                continue;
            }
            foreach ($run_worker_list as $worker_id => $worker_params) {
                if ($this->max_worker_num >= count($this->pid_children)
                && !in_array($worker_id, $this->pid_children)) {
                    $this->forkWorker($worker_closure, $worker_id, $worker_params);
                }
            }
        }
    }

    public function forkWorker($worker_closure, $worker_id, $worker_params)
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            echo 'error';
            exit;
        }
        if ($pid) {
            $this->pid_children[$pid] = $worker_id;
        } else {
            call_user_func_array($worker_closure, [
                $worker_params
            ]);
            sleep(2);
            exit();
        }
    }

    /**
     * @param string $handler
     */
    public function installSignal($handler = 'signalHandler')
    {
        pcntl_signal(SIGINT, array($this, $handler));
        pcntl_signal(SIGCHLD, array($this, $handler));
        pcntl_signal(SIGTERM, array($this, $handler));
        pcntl_signal(SIGUSR1, array($this, $handler));

    }

    //收到信号回调
    public function signalHandler($signal)
    {
        switch ($signal) {
            case SIGCHLD:
                //捕获子进程结束信号，清理子进程进程号
                while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                    unset($this->pid_children[$pid]);
                }
                break;
            case SIGINT:
            case SIGTERM:
                $pid = posix_getpid();
                if ($pid == $this->master_pid) {
                    $this->master_status = 0; //设置主进程完毕

                    //通知子进程安全退出
                    foreach ($this->pid_children as $pid => $worker_id) {
                        posix_kill($pid, SIGUSR1);
                    }
                    $this->quit();
                }
                break;
            case SIGUSR1:
                $pid = posix_getpid();
                if($pid == $this->master_pid) {
                    //通知子进程安全退出
                    foreach ($this->pid_children as $pid => $worker_id) {
                        posix_kill($pid, SIGUSR1);
                    }
                } else {
                    if(!empty($this->rebooter)) {
                        //子进程退出的trigger
                        $this->rebooter->reboot();
                    }
                }
            default:
                break;
        }
    }

    /**
     * daemon化程序
     */
    public function daemonize()
    {
        global $stdin, $stdout, $stderr;

        set_time_limit(0);
        // 只允许在cli下面运行
        if (php_sapi_name() != "cli") {
            $this->logger->err("[daemonize err] only run in command line mode ");
            exit();
        }

        // 只能单例运行
        if ($this->checkPidfile()) {
            exit();
        }
        umask(0); //把文件掩码清0
        if (pcntl_fork() != 0) { //是父进程，父进程退出
            exit();
        }
        posix_setsid();//设置新会话组长，脱离终端
        if (pcntl_fork() != 0) { //是第一子进程，结束第一子进程
            exit();
        }
        chdir("/"); //改变工作目录
        if (!$this->setUser($this->user)) {
            $this->logger->err("[daemonize err] Can not change owner ");
            exit();
        }

        //关闭打开的文件描述符
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $stdin = fopen($this->output, 'r');
        $stdout = fopen($this->output, 'a');
        $stderr = fopen($this->output, 'a');
        $this->createPidfile();
    }

    //--检测pid是否已经存在
    public function checkPidfile()
    {
        if (!is_file($this->pid_file_path)) {
            return false;
        }
        $pid = file_get_contents($this->pid_file_path);
        $pid = intval($pid);
        if ($pid > 0 && posix_kill($pid, SIG_BLOCK)) {
            $this->logger->err("[daemonize err] the daemon process is already exist");
        } else {
            $this->logger->err("[daemonize err] the daemon process end abnormally, pid: {$pid}. Deleting pid file automatically");
            unlink($this->pid_file_path);
            return false;
        }
        return true;

    }

    /**
     * @ 设置运行的用户
     * @param $name
     * @return bool
     */
    public function setUser($name)
    {
        $result = false;
        if (empty($name)) {
            return true;
        }
        $user = posix_getpwnam($name);
        if ($user) {
            $uid = $user['uid'];
            $gid = $user['gid'];
            $result = posix_setuid($uid);
            posix_setgid($gid);
        }
        return $result;
    }

    /**
     * 创建pid
     */
    public function createPidfile()
    {
        $pid_dirname = dirname($this->pid_file_path);
        if (!is_dir($pid_dirname)) {
            mkdir($pid_dirname, 0755);
        }
        if (!$fp = fopen($this->pid_file_path, 'w')) {
            $this->logger->err("[daemonize err] cannot create pid file, permission denied");
            exit();
        }
        fwrite($fp, posix_getpid());
        fclose($fp);
        $this->logger->info("[daemonize succ] create pid file success in @", [$this->pid_file_path]);
    }

    public function quit()
    {
        if (is_file($this->pid_file_path)) {
            unlink($this->pid_file_path);
            $this->logger->info("[Process kill] Pid file has deleted successfully");
        }
        exit(0);
    }

    /**
     * 运行指令
     */
    public function command()
    {
        // 检查运行命令的参数
        global $argv;
        $start_file = $argv[0];

        // 命令
        $command = isset($argv[1]) ? trim($argv[1]) : '';

        // 进程号
        $pid = isset($argv[2]) ? $argv[2] : '';

        // 根据命令做相应处理
        switch ($command) {
            case 'debug':
                $this->debug = true;
                break;
            case 'start':
                break;
            case 'stop':
                exec("ps aux | grep $start_file | grep -v grep | awk '{print $2}'", $pid_list);
                if (is_file($this->pid_file_path)) {
                    $pid = file_get_contents($this->pid_file_path);
                    if (0 < $pid && in_array($pid, $pid_list)) {
                        exec("kill -15 {$pid}");
                        echo "[$start_file] master process stop success\n";
                    }
                } else {
                    echo " [$start_file] master process not run\n";
                }
                exit(0);
                break;
            case 'reload':
                exec("ps aux | grep $start_file | grep -v grep | awk '{print $2}'", $pid_list);
                if (is_file($this->pid_file_path)) {
                    $pid = file_get_contents($this->pid_file_path);
                    if (0 < $pid && in_array($pid, $pid_list)) {
                        exec("kill -10 {$pid}");
                        echo "[$start_file] worker restart success\n ";
                    }
                } else {
                    echo " [$start_file] master process not run\n";
                }
                exit(0);
                break;
            case 'status':
                while (1) {
                    if (!is_file($this->pid_file_path)) {
                        $pid = " [master process not run, might be exiting securely]";
                    } else {
                        $pid = file_get_contents($this->pid_file_path);
                    }
                    $pid_list = array();
                    exec("ps aux | grep $start_file | grep -v grep | grep -v status | awk '{print $2}' | grep -v {$pid}", $pid_list);
                    $this->printStr($pid, $pid_list);
                    sleep(5);
                }
                exit(0);
            // 未知命令
            default :
                exit("\033[32;40mUsage: php {$start_file} {start|stop|reload|status}\033[0m\n");
        }
    }

    //系统负载
    public function getSysLoad()
    {
        $loadavg = sys_getloadavg();
        foreach ($loadavg as $k => $v) {
            $loadavg[$k] = round($v, 2);
        }
        return implode(", ", $loadavg);
    }

    /**
     * 打印到屏幕
     * @param $pid
     * @param $pid_children
     */
    public function printStr($pid, $pid_children)
    {
        $display_str = '';
        $display_str .= "-----------------------<white>Process status </white>-------------------" . PHP_EOL;
        $display_str .= "现在时间:" . date('Y-m-d H:i:s') . PHP_EOL;
        $display_str .= 'Load average: <green>' . $this->getSysLoad() . '<green>' . PHP_EOL;
        $display_str .= "PHP version:<purple>" . PHP_VERSION . "</purple>" . PHP_EOL;
        if (empty($pid_children)) {
            $display_str .= "当前子进程数: <red>无子进程作业中...</red>" . PHP_EOL;
        } else {
            $display_str .= "当前子进程数: <red>" . count($pid_children) . "个，PID:(" . implode(',', $pid_children) . ")</red>" . PHP_EOL;
        }
        $display_str .= "当前主进程PID: <red>" . $pid . "</red>" . PHP_EOL;
        $display_str .= "<yellow>Press Ctrl+C to quit.</yellow>" . PHP_EOL;
        $display_str = $this->clearLine($this->replaceStr($display_str));
        echo $display_str;

    }

    /**
     * 文字替换
     * @param $str
     * @return string|string[]
     */
    public function replaceStr($str)
    {
        $line = "\033[1A\n\033[K";
        $white = "\033[47;30m";
        $green = "\033[32;40m";
        $yellow = "\033[33;40m";
        $red = "\033[31;40m";
        $purple = "\033[35;40m";
        $end = "\033[0m";
        $str = str_replace(array('<n>', '<white>', '<green>', '<yellow>', '<red>', '<purple>'), array($line, $white, $green, $yellow, $red, $purple), $str);
        $str = str_replace(array('</n>', '</white>', '</green>', '</yellow>', '</red>', '</purple>'), $end, $str);
        return $str;
    }

    /**
     * shell 替换显示
     * @param $message
     * @param null $force_clear_lines
     * @return string
     */
    function clearLine($message, $force_clear_lines = NULL)
    {
        static $last_lines = 0;
        if (!is_null($force_clear_lines)) {
            $last_lines = $force_clear_lines;
        }

        // 获取终端宽度
        $toss = $status = null;
        $term_width = exec('tput cols', $toss, $status);
        if ($status || empty($term_width)) {
            $term_width = 64; // Arbitrary fall-back term width.
        }

        $line_count = 0;
        foreach (explode("\n", $message) as $line) {
            $line_count += count(str_split($line, $term_width));
        }
        // Erasure MAGIC: Clear as many lines as the last output had.
        for ($i = 0; $i < $last_lines; $i++) {
            echo "\r\033[K\033[1A\r\033[K\r";
        }
        $last_lines = $line_count;
        return $message . "\n";
    }
}
