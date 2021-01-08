<?php
namespace mfunc;

class Smtp
{
    /**
     * 配置
     * @var array
     */
	protected $_config;

    /**
     * socket资源
     * @var reource
     */
	protected $_socket;

    /**
     * 错误信息
     * @var string
     */
	protected $_errmsg;

	public function __construct ($config = [])
	{
        if (empty($config['host'])) throw new \Exception("请设置SMTP服务器");
        if (empty($config['user'])) throw new \Exception("请设置SMTP用户名");
        if (empty($config['pass'])) throw new \Exception("请设置SMTP密码");
        if (empty($config['from'])) throw new \Exception("请设置SMTP发件人");

		$config['user'] = base64_encode($config['user']);
		$config['pass'] = base64_encode($config['pass']);
		empty($config['port']) && $config['port'] = 25; 
		$config['secu'] = !empty($config['secu']);
		$this->_config = $config;
	}

    /**
     * 设置自定义发件人姓名
     * @access public
     * @param string $realname 发件人姓名
     * @return boolean
     */
    public function setRealname($realname)
    {
        $this->_config['realname'] = $realname;
        return $this;
    }

    /**
     * 设置抄送，多个抄送，逗号分隔，调用多次.
     * @access public
     * @param string $cc 抄送地址
     * @return boolean
     */
    public function setCc($cc) 
    {
        $this->_config['cc'] = $cc;
        return $this;
    }

    /**
     * 设置秘密抄送，多个秘密抄送，逗号分隔，调用多次
     * @access public
     * @param string $bcc 秘密抄送地址
     * @return boolean
     */
    public function setBcc($bcc) 
    {
        $this->_config['bcc'] = $bcc;
        return $this;
    }


    /**
     * 设置邮件附件，多个附件，调用多次
     *   ['源文件路径', '邮件中显示附件名称'],
     *   // 邮件体中附件 图片音频等
     *   ['源文件路径', '名称', 'inline', 'cid']
     * @access public
     * @param string $file 文件地址
     * @return boolean
     */
    public function attachment(array $file_array) 
    {
        if(!file_exists($file_array[0])) {
            $this->_errmsg = "file " . $file_array[0] . " does not exist.";
            return false;
        }
        $this->_config['attachment'][] = $file_array;
        return $this;
    }

    /**
     * 发送邮件
     * @param string|array $to 收件人
     * @param string $subject  主题
     * @param string $content  邮件内容
     * @return boolen
     */
	public function sendMail ($to, $subject, $content)
	{
        // 邮件体中图片资源增加CID
        $pat = '/<\s*img\s+[^>]*?src\s*=\s*(\'|\")(.*?)\\1[^>]*?\/?\s*>/i';

        $content = preg_replace_callback($pat, function ($m) {
            if (!preg_match('|^https?://|i', $m[2])) {
                $cid = md5($m[2]);
                $this->attachment([$m[2], '', 'inline', $cid]);
                return str_replace($m[2], 'cid:'.$cid, $m[0]);
            } else {
                return $m[0];
            }
        }, $content);

        $commands = $this->_getCommand($to, $subject, $content);
        $this->_createSocket();

        foreach ($commands as $command) {
            list($cmd, $code) = $command;
            $result = $this->_config['secu'] ? $this->_sendCommandSecurity($cmd, $code) : $this->_sendCommand($cmd, $code);
            if($result)
                continue;
            else
                return false;
        }

        return true;
	}

    /**
     * 返回错误信息
     * @return string
     */
    public function getError ()
    {
        return isset($this->_errmsg) ? $this->_errmsg : '';
    }

    /**
     * 返回mail命令
     * @access protected
     * @return array
     */
    protected function _getCommand($to, $subject, $content) 
    {
        $separator = "----=_Part_" . md5($this->_config['from'] . time()) . uniqid(); //分隔符
 
        $command = [["HELO sendmail\r\n", 250]];

        if(!empty($this->_config['user'])){
            $command[] = ["AUTH LOGIN\r\n", 334];
            $command[] = [$this->_config['user'] . "\r\n", 334];
            $command[] = [$this->_config['pass'] . "\r\n", 235];
        }
  
        // 设置发件人
        $command[] = ["MAIL FROM: <" . $this->_config['from'] . ">\r\n", 250];
        $header = "FROM: ".$this->_config['realname']."<" . $this->_config['from'] . ">\r\n";
 
        // 设置收件人
        $header .= $this->_rcptTo($to, $command, 'TO');
  
        // 设置抄送
        if (isset($this->_config['cc'])) {
            $header .= $this->_rcptTo($this->_config['cc'], $command, 'CC');
        }
  
        // 设置秘密抄送
        if (isset($this->_config['bcc'])) {
            $header .= $this->_rcptTo($this->_config['bcc'], $command, 'BCC');
        }
  
        // 主题
        $header .= "Subject: =?UTF-8?B?" . base64_encode($subject) ."?=\r\n";

        if(isset($this->_config['attachment'])) {
            $inline = false;
            foreach ($this->_config['attachment'] as $attachment) {
                if (!empty($attachment[2]) && $attachment[2] == 'inline' && !empty($attachment[3])) {
                    $inline = true;
                    break;
                }
            }
            if (!$inline) {
                // 含有附件的邮件头声明
                $header .= "Content-Type: multipart/mixed;\r\n";
            } else {
                // 邮件体含有图片资源的,且包含的图片在邮件内部时声明
                $header .= "Content-Type: multipart/related;\r\n";
            }
        } else {
            // html或者纯文本的邮件声明
            $header .= "Content-Type: multipart/alternative;\r\n";
        }
  
        // 邮件头分隔符
        $header .= "\t" . 'boundary="' . $separator . '"';
        $header .= "\r\nMIME-Version: 1.0\r\n";
  
        // 这里开始是邮件的body部分，body部分分成几段发送
        $header .= "\r\n--" . $separator . "\r\n";
        $header .= "Content-Type:text/html; charset=utf-8\r\n";
        $header .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $header .= base64_encode($content) . "\r\n";
        $header .= "--" . $separator . "\r\n";
 
        // 加入附件
        if(!empty($this->_config['attachment'])){
            foreach ($this->_config['attachment'] as $attachment) {
                $header .= "\r\n--" . $separator . "\r\n";
                $header .= "Content-Type: " . $this->_getMIMEType($attachment[0]) . '; name="=?UTF-8?B?' . base64_encode( basename($attachment[1]) ) . '?="' . "\r\n";
                //echo $header;
                $header .= "Content-Transfer-Encoding: base64\r\n";
                $header .= 'Content-Disposition: attachment; filename="=?UTF-8?B?' . base64_encode( basename($attachment[1]) ) . '?="' . "\r\n";
                if (!empty($attachment[2]) && $attachment[2] == 'inline' && !empty($attachment[3])) {
                    $header .= "Content-ID: <{$attachment[3]}>\r\n";
                }
                $header .= "\r\n";
                $header .= $this->_readFile($attachment[0]);
                $header .= "\r\n--" . $separator . "\r\n";
            }
            // echo $header;
        }
 
        //结束邮件数据发送
        $header .= "\r\n.\r\n";
  
  
        $command[] = ["DATA\r\n", 354];
        $command[] = [$header, 250];
        $command[] = ["QUIT\r\n", 221];
          
        return $command;
    }

    protected function _rcptTo ($data, &$command, $type = 'TO')
    {
        $str  = '';
        $data = !is_array($data) ? explode(',', $data) : $data;
        if(!empty($data)) {
            $data = array_unique(array_filter($data));
            $count = count($data);
            if($count == 1){
                $command[] = ["RCPT TO: <" . $data[0] . ">\r\n", 250];
                $str .= "{$type}: <" . $data[0] .">\r\n";
            } else {
                foreach ($data as $key => $val) {
                    $command[] = ["RCPT TO: <" . $val . ">\r\n", 250];
                    if($key == 0){
                        $str .= "{$type}: <" . $val .">";
                    }
                    else if($key + 1 == $count){
                        $str .= ",<" . $val .">\r\n";
                    }
                    else{
                        $str .= ",<" . $val .">";
                    }
                }
            }
        }
        return $str;
    }

    /**
     * 发送命令
     * @access protected
     * @param string $command 发送到服务器的smtp命令
     * @param int $code 期望服务器返回的响应吗
     * @return boolean
     */
    protected function _sendCommand($command, $code) 
    {
        try{
            if(!socket_write($this->_socket, $command, strlen($command))) {
                $this->_errmsg = "Error:" . socket_strerror(socket_last_error());
                return false;
            }
  
            //当邮件内容分多次发送时，没有$code，服务器没有返回
            if(empty($code)) return true;
  
            //读取服务器返回
            $data = trim(socket_read($this->_socket, 1024));
            if(!$data) {
                $this->_errmsg = "Error:" . socket_strerror(socket_last_error());
                return false;
            }

            $pattern = "/^".$code."+?/";
            if(!preg_match($pattern, $data)) {
                $this->_errmsg = "Error:" . $data . "|**| command:";
                return false;
            }

            return true;
        } catch(\Exception $e) {
            $this->_errmsg = "Error:" . $e->getMessage();
        }
    }
  
    /**
     * 安全连接发送命令
     * @access protected
     * @param string $command 发送到服务器的smtp命令
     * @param int $code 期望服务器返回的响应码
     * @return boolean
     */
    protected function _sendCommandSecurity($command, $code) 
    {
        try {
            if (!fwrite($this->_socket, $command)) {
                $this->_errmsg = "Error: " . $command . " send failed";
                return false;
            }

            //当邮件内容分多次发送时，没有$code，服务器没有返回
            if(empty($code)) return true;

            //读取服务器返回
            $data = trim(fread($this->_socket, 1024));

            if(!$data) return false;

            $pattern = "/^".$code."+?/";
            if(!preg_match($pattern, $data)) {
                $this->_errmsg = "Error:" . $data . "|**| command:";
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->_errmsg = "Error:" . $e->getMessage();
        }
    }

    /**
     * 建立网络连接
     */
    protected function _createSocket ()
    {
        if ($this->_config['secu']) {
            $remoteAddr = "tcp://" . $this->_config['host'] . ":" . $this->_config['port'];
            $this->_socket = stream_socket_client($remoteAddr, $errno, $errstr, 30);
            if(!$this->_socket){
                $this->_errmsg = $errstr;
                return false;
            }

	        //设置加密连接，默认是ssl，如果需要tls连接，可以查看php手册stream_socket_enable_crypto函数的解释
	        stream_socket_enable_crypto($this->_socket, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
	        stream_set_blocking($this->_socket, 1);
	        $str = fread($this->_socket, 1024);
    	} else {
	        $this->_socket = socket_create(AF_INET, SOCK_STREAM, getprotobyname('tcp'));
	        if(!$this->_socket) {
	            $this->_errmsg = socket_strerror(socket_last_error());
	            return false;
	        }

	        socket_set_block($this->_socket);
	        //连接服务器
	        if(!socket_connect($this->_socket, $this->_config['host'], $this->_config['port'])) {
	            $this->_errmsg = socket_strerror(socket_last_error());
	            return false;
	        }
	        $str = socket_read($this->_socket, 1024);
    	}

        if(!preg_match("/220+?/", $str)){
            $this->_errmsg = $str;
            return false;
        }
    }

    /**
     * 读取附件文件内容，返回base64编码后的文件内容
     * @access protected
     * @param string $file 文件
     * @return mixed
     */
    protected function _readFile($file) 
    {
        if(!file_exists($file)) {
            $this->_errmsg = "file " . $file . " dose not exist";
            return false;
        }

        return base64_encode(file_get_contents($file));
    }

    /**
     * 获取附件MIME类型
     * @access protected
     * @param string $file 文件
     * @return mixed
     */
    protected function _getMIMEType($file) 
    {
        if(!file_exists($file)) return false;

        if(!function_exists('mime_content_type')) {
            function mime_content_type ($filename) {
                $content = file_get_contents(__DIR__ . '/mime');
                preg_match_all('#^([^\s]{2,}?)\s+(.+?)$#ism', $content, $matches, PREG_SET_ORDER);
                foreach ($matches as $match) foreach (explode(" ", $match[2]) as $ext) $mines[$ext] = $match[1];
                $ext = pathinfo($file, 4);
                if (array_key_exists($ext, $mines)) {
                    return $mines[$ext];
                } elseif (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME);
                    $mimetype = finfo_file($finfo, $filename);
                    finfo_close($finfo);
                    return $mimetype;
                } else {
                    return 'application/octet-stream';
                }
            }
        }
    
        $mime = mime_content_type($file);
        if(! preg_match("/gif|jpg|png|jpeg/", $mime) || $mime==""){
            $mime = "application/octet-stream";
        }
        return $mime;
    }
}
