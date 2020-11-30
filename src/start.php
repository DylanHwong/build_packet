<?php
include 'header.php';
include 'Build_Packet.php';

use Process\Process;

/**
 * 单端口，多进程（根据平台数据创建相应工作worker）
 */
$process = new Process('build_packet', 'root', CONFIG_DIR . 'pid_file', RUN_LOG);
/**
 * master 和 worker进程工作内容通过闭包函数传入
 * master负责监控task_list，同时创建worker进程分发工作，自动回收已退出的worker
 * worker负责build流程，完毕进程自动回收
 */
$build_packet = new Build_Packet();
$process->start(function (&$run_worker_list, &$shut_worker_list, $debug) use($build_packet) {
    $run_worker_list = array();
    $shut_worker_list = array();
    $task_list = array();
    if($debug) {
        $task_list[] = [
            'pack_id' => '99999',
            'game_id' => '1670',
            'channel_id' => '8888',
            'base_pkg_file' => 'J16_90590_zb.apk',
            'child_pkg_file_name' => 'J16_test_8888.apk',
            'pkg_build_id' => '',

        ];
    } else {
        $task_list = $build_packet->get_task();
    }

    //分发任务逻辑
    $length = count($task_list);
    if( 0 < $length ) {
        $worker_num = ceil($length / 5); //理想状态一个worker跑5个任务
        $max_worker_num = $worker_num > 20 ? 20 : $worker_num; //最多20个worker
        $single_worker_task_num = ceil($length/$max_worker_num); //计算每个worker的任务数
        $run_worker_list = array_chunk($task_list, $single_worker_task_num); //分配每个woker任务
    }
}, function ($worker_tasks) use ($build_packet) {
    foreach($worker_tasks as $task) {
        $build_packet->pack_new_pkg($task);
    }
});


;