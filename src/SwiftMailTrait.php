<?php

trait SwiftMailTrait
{
    protected static $mailer;
    protected static $message;

    public static function setMailer($config)
    {
        $transport = new Swift_SmtpTransport($config['host'], 25);
        $transport->setUsername($config['username']);
        $transport->setPassword($config['password']);
        self::$mailer = new Swift_Mailer($transport);
        self::$message = (new Swift_Message())
            ->setFrom($config['from'])
            ->setTo($config['to'])
            ->setSubject($config['subject']);
    }
}