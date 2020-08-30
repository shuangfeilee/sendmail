<?php
require_once __DIR__ . '/vendor/autoload.php';

use mfunc\Smtp;

// 配置
$config = [
	'host'	=>	'smtp.exmail.qq.com',
	'user'	=>	'your email',
	'from'	=>	'your email',
	'pass'	=>	'your email pwd',
	// 启用安全连接 默认false
	'secu'	=>	true,
	// 默认25
	'port'	=>	465,
];

// 实例化
$smtp = new Smtp($config);
// 收件人列表 数组或者逗号分隔字符串
$to = 'admin@test.com,hehe@test.com';
// 邮件主题
$subject = '测试邮件';
// 邮件内容 邮件体中含有本地图片资源等文件在邮件中显示
$content = "adsfadsfafaf<img src='https://ss0.bdstatic.com/70cFvHSh_Q1YnxGkpoWK1HF6hhy/it/u=1526166290,132727360&fm=26&gp=0.jpg'>sdfadfsfadsfds<img src='test.jpg'>sdfsdfafsd";

// 自定义发件人姓名
// $smtp->setRealname('姓名')

// 设置抄送
// $cc = 'xxxxx@xxx.com,ssssss@sss.com';
// $smtp->setCc($cc)

// 设置秘密抄送
// $bcc = 'xxxxx@xxx.com,ssssss@sss.com';
// $smtp->setBcc($bcc);

// 设置附件
// $attachment = ['源文件路径', '邮件中显示附件名称'];
// 附件图片显示到邮件体中
// $attachment = ['源文件路径', '名称', 'inline', '自定义cid'];
// $smtp->attachment();

// 发送邮件
if ($smtp->setRealname('heihei')->sendMail($to, $subject, $content)) {
	echo 'ok';
} else {
	echo $smtp->getError();
}