<?php
/*
 * 目录定义
 */
require_once 'define.php';
/*
 * 使用composer自动加载
 */
require_once VENDOR_DIR . 'autoload.php';
/**
 * 全局配置
 */
require_once CONFIG_DIR . 'general_config.php';
/*
 * 邮件发送依赖包
 */
require_once VENDOR_DIR . 'swiftmailer/swiftmailer/lib/swift_required.php';
/*
 * 邮件handler
 */
require_once 'SwiftMailTrait.php';
