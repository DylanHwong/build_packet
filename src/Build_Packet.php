<?php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SwiftMailerHandler;

class Build_Packet
{
    use SwiftMailTrait;

    private $file_logger;
    private $mail_logger;

    public function __construct()
    {
        //register file logger
        $this->file_logger = new Logger('BuildPacket logger');
        $this->file_logger->pushHandler(new StreamHandler(RUN_LOG . 'build_packet_.log'));


        //register mailer logger
        global $g_c;
        self::setMailer($g_c['mail']['build_packet']);
        $this->mail_logger = new Logger('BuidPacket logger');
        $this->mail_logger->pushHandler(new SwiftMailerHandler(self::$mailer, self::$message));
    }

    /**
     * 日志记录和邮件示例
     */
    public function log_example()
    {
        $this->file_logger->info('this is info log');
        $this->file_logger->err('this is error log');
        $this->file_logger->debug('this is debug log');
        $this->file_logger->alert('this is alert log');

        $this->mail_logger->err('this is error warning by mail');
    }

    public function  message_return($msg){
        switch ($msg) {
            case 'PACK_SUCCESS':
                return array('code' => 0, 'msg' => "success");
                break;
            case 'SRC_PACK_ERR':
                return array('code' => 10001, 'msg' => "src_pack_not_fund");
                break;
            case 'DECOMPRESS_ERR':
                return array('code' => 10002, 'msg' => "decompress_error");
                break;
            case 'COMPRESS_ERR':
                return array('code' => 10003, 'msg' => "compress_error");
                break;
            case 'CHANNEL_FILE_ERR':
                return array('code' => 10004, 'msg' => "channel_id_file_not_fund");
                break;
            case 'ANDROID_MANIFEST_ERR':
                return array('code' => 10005, 'msg' => "AndroidManifest_not_fund");
                break;
            case 'SIGN_ERR':
                return array('code' => 10006, 'msg' => "sign_error");
                break;
            case 'COPY_BASE_APK_FAIL':
                return array('code' => 10007, 'msg' => "copy_base_apk_fail");
                break;
            case 'REPLACE_CONFIG_ERR':
                return array('code' => 10008, 'msg' => "replace_config_err");
                break;
        }
    }


    public function get_task(){
        $task_list = array();

        return  $task_list;
    }



    public function clean_dir($path){

        //如果不是目录则退出
        if(false == is_dir($path)){
            return false;
        }

        if('/' != substr($path, -1)){
            $path = $path.'/';
        }

        //扫描一个文件夹内的所有文件夹和文件并返回数组
        $p = scandir($path);
        foreach($p as $val){
            //排除目录中的.和..
            if($val !="." && $val !=".."){
                //如果是目录则递归子目录，继续操作
                if(is_dir($path.$val)){
                    //子目录中操作删除文件夹和文件
                    if(false == $this->clean_dir($path.$val.'/')){
                        return false;
                    };
                    //目录清空后删除空文件夹
                    if (false == rmdir($path.$val.'/')){
                        return false;
                    }
                } else {
                    //如果是文件直接删除
                    if(false == unlink($path.$val)){
                        return false;
                    }
                }
            }
        }
        return true;


    }


    /*
        $base_pkg_file  母包文件名
    */
    public function decompress_base_pkg($base_pkg_file){
        $base_pkg_path = BASE_PKG_DIR.$base_pkg_file;
        if(!file_exists($base_pkg_path)){
            $this->message_return('SRC_PACK_ERR');
        }

        /*
            1、清空工作目录
            2、复制母包到工作目录
            3、解压母包
            4、删除母包复制copy体
        */
        if(false == clean_dir(WORK_DIR)){
            $this->message_return('DECOMPRESS_ERR');
        }

        if(false == mkdir(DECOMPRESS_DIR,0755)){
            $this->message_return('DECOMPRESS_ERR');
        }

        $work_pkg_file = WORK_DIR.$base_pkg_file;
        if(!copy($base_pkg_path,$work_pkg_file)){
            $this->message_return('COPY_BASE_APK_FAIL');
        }
        $status = '';
        $strcmd = "/home/lee/jdk1.8.0_191/bin/java -jar ".SOURCE_DIR."/apktool.jar d --only-main-classes {$work_pkg_file} -o ".DECOMPRESS_DIR." -f ";
        $log = system($strcmd,$status);
        if($status != 0){
            $this->message_return('DECOMPRESS_ERR');
        }

        if(false == unlink($work_pkg_file)){
            $this->message_return('DECOMPRESS_ERR');
        }
        $this->message_return('PACK_SUCCESS');
    }


    public function get_pkg_config_path(){
        $config_path_key = "smali";
        $default_config_file = 'com/qdazzle/sdk/core/config/ReplaceConfig.smali';
        $default_config_path =  DECOMPRESS_DIR."{$config_path_key}/$default_config_file";
        if(file_exists($default_config_path)) return $default_config_path;
        $dir_list = scandir(DECOMPRESS_DIR);
        foreach($dir_list as $maybe_dir){
            if(false == is_dir(DECOMPRESS_DIR.$maybe_dir)) continue;
            $maybe_path = DECOMPRESS_DIR."{$maybe_dir}/$default_config_file";
            if(true == file_exists($maybe_path)){
                return $maybe_path;
            }
        }
    }

    public function  change_pkg_build_id($pkg_manifest_path, $pkg_build_id){
        $content = file_get_contents($pkg_manifest_path);
        $xml = new SimpleXMLElement($content);
        $xml->attributes()->package = $pkg_build_id;
        $xml->saveXML($pkg_manifest_path);
    }

    public function replace_pkg_config($task){
        /*
            1、判断是否需要修改包名
            2、获取配置文件路径
            3、获取配置文件，修改渠道id
        */
        if($task['pkg_build_id']){
            //1、判断是否需要修改包名
            $pkg_manifest_path = WORK_DIR.'AndroidManifest.xml';
            if(false == file_exists($pkg_manifest_path)){
                $this->message_return('REPLACE_CONFIG_ERR');
            }
            $this->change_pkg_build_id($pkg_manifest_path, $task['pkg_build_id']);
        }

        $pkg_config_path = $this->get_pkg_config_path();
        unlink($pkg_config_path);
        if(!copy(SOURCE_DIR.'ReplaceConfig.smali',$pkg_config_path)){
            $this->message_return('REPLACE_CONFIG_ERR');
        }
        $config_contents = file_get_contents($pkg_config_path);
        $config_contents = str_replace('14444', $task['channel_id'], $config_contents);
        if(false == file_put_contents($pkg_config_path, $config_contents)){
            $this->message_return('REPLACE_CONFIG_ERR');
        }
        $this->message_return('PACK_SUCCESS');
    }


    public function  compress_child_pkg($task){
        $strcmd = "/home/lee/jdk1.8.0_191/bin/java -jar ".SOURCE_DIR."/apktool.jar b ".DECOMPRESS_DIR." -o ".CHILD_PKG_DIR."/nojarsign.apk";
        $status = '';
        $log = system($strcmd,$status);
        if($status != 0){
            $this->message_return('COMPRESS_ERR');
        }
        $this->message_return('PACK_SUCCESS');
    }


    public function  jarsigner_pkg($task){
        $strcmd = "/home/lee/jdk1.8.0_191/bin/jarsigner -verbose -keystore ".SOURCE_DIR."/shushan-release-key.keystore -sigfile CERT -sigalg MD5withRSA -digestalg SHA1 -keypass qdazzle -storepass qdazzle -signedjar ".CHILD_PKG_DIR."/{$task['child_pkg_file_name']}/ ".CHILD_PKG_DIR."/nojarsign.apk shushan-release-key.keystore";
        $status = '';
        $log = system($strcmd,$status);
        if($status != 0){
            $this->message_return('DECOMPRCOMPRESS_ERRESS_ERR');
        }
        $this->message_return('PACK_SUCCESS');
    }

    /*
        $task['pack_id'] 打包任务ID
        $task['game_id'] game_id
        $task['channel_id'] 渠道id
        $task['base_pkg_file'] 母包文件名
        $task['child_pkg_file_name'] 分包文件名
        $task['pkg_build_id'] 包名build_id

    */
    public function  pack_new_pkg( $task){
        /*
            一、解压母包
                1、清空工作目录
                2、复制母包到工作目录
                3、解压母包
                4、删除母包复制copy体
            二、替换配置
                1、判断是否需要修改包名
                2、获取配置文件路径
                3、获取配置文件，修改渠道id
            三、压缩包
            四、签名
        */

        //解包
        $status = $this->decompress_base_pkg($task['base_pkg_file']);
        if($status['code'] != 0){
            return $status;
        }
        //替换配置
        $status = $this->replace_pkg_config($task);
        if($status['code'] != 0){
            return $status;
        }
        //压缩包
        $status = $this->compress_child_pkg($task);
        if($status['code'] != 0){
            return $status;
        }
        //签名
        $status = $this->jarsigner_pkg($task);
        if($status['code'] != 0){
            return $status;
        }
        $this->message_return('PACK_SUCCESS');
    }
}


