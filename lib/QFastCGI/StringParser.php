<?php
namespace QFastCGI;

if (ini_get('mbstring.func_overload') & 2) {
    function binarySubstr($s, $p, $l = 0xFFFFFFF) {
        return substr($s, $p, $l, 'ASCII');
    }
}
else {
    function binarySubstr($s, $p, $l = NULL) {
        if ($l === NULL) {
            $ret = substr($s, $p);
        }
        else {
            $ret = substr($s, $p, $l);
        }

        if ($ret === FALSE) {
            $ret = '';
        }
        return $ret;
    }
}

class StringParser {

    const FCGI_BEGIN_REQUEST     = 1;
    const FCGI_ABORT_REQUEST     = 2;
    const FCGI_END_REQUEST       = 3;
    const FCGI_PARAMS            = 4;
    const FCGI_STDIN             = 5;
    const FCGI_STDOUT            = 6;
    const FCGI_STDERR            = 7;
    const FCGI_DATA              = 8;
    const FCGI_GET_VALUES        = 9;
    const FCGI_GET_VALUES_RESULT = 10;
    const FCGI_UNKNOWN_TYPE      = 11;

    const FCGI_RESPONDER  = 1;
    const FCGI_AUTHORIZER = 2;
    const FCGI_FILTER     = 3;

    const STATE_CONTENT = 1;
    const STATE_PADDING = 2;

    /**
     * Alias of STATE_STANDBY
     */
    const STATE_ROOT = 0;
    /**
     * Standby state (default state)
     */
    const STATE_STANDBY = 0;

    protected static $roles = [
        self::FCGI_RESPONDER  => 'FCGI_RESPONDER',
        self::FCGI_AUTHORIZER => 'FCGI_AUTHORIZER',
        self::FCGI_FILTER     => 'FCGI_FILTER',
        ];

    protected static $requestTypes = [
        self::FCGI_BEGIN_REQUEST     => 'FCGI_BEGIN_REQUEST',
        self::FCGI_ABORT_REQUEST     => 'FCGI_ABORT_REQUEST',
        self::FCGI_END_REQUEST       => 'FCGI_END_REQUEST',
        self::FCGI_PARAMS            => 'FCGI_PARAMS',
        self::FCGI_STDIN             => 'FCGI_STDIN',
        self::FCGI_STDOUT            => 'FCGI_STDOUT',
        self::FCGI_STDERR            => 'FCGI_STDERR',
        self::FCGI_DATA              => 'FCGI_DATA',
        self::FCGI_GET_VALUES        => 'FCGI_GET_VALUES',
        self::FCGI_GET_VALUES_RESULT => 'FCGI_GET_VALUES_RESULT',
        self::FCGI_UNKNOWN_TYPE      => 'FCGI_UNKNOWN_TYPE',
        ];

    protected $header;
    protected $content;

    public function parse($data){
        $current = 0;
        $data_len = strlen($data);
        $read = function ($n) use($data, &$current, $data_len) {
            if($n>0 && $current < $data_len){
                $str = substr($data, $current , $n);
                $current += $n;
                if ($str === null || strlen($str)<$n){
                    return false;
                } else {
                    return $str;
                }
            }

            if ($n == 0) {
                return '';
            }
            return false;
        };
        $state = 0;
        start:
        if ($state === self::STATE_ROOT) {
            $_header = $read(8);

            if ($_header === false) {
                return;
            }
            $header = unpack('Cver/Ctype/nreqid/nconlen/Cpadlen/Creserved', $_header);

            if ($header['conlen'] > 0) {
                //$this->setWatermark($this->header['conlen'], $this->header['conlen']);
            }
            $type                  = $this->header['type'];
            $header['ttype'] = isset(self::$requestTypes[$type]) ? self::$requestTypes[$type] : $type;
            $rid                   = $this->header['reqid'];
            $state           = self::STATE_CONTENT;

        }
        else {
            $type = $header['type'];
            $rid  = $header['reqid'];
        }
        if ($state === self::STATE_CONTENT) {
            $content = $read($header['conlen']);

            if ($content === false) {
                //$this->setWatermark($this->header['conlen'], $this->header['conlen']);
                return;
            }

            if ($header['padlen'] > 0) {
                //$this->setWatermark($this->header['padlen'], $this->header['padlen']);
            }

            $state = self::STATE_PADDING;
        }

        if ($state === self::STATE_PADDING) {
            $pad = $read($header['padlen']);

            if ($pad === false) {
                return;
            }
        }
        $state = self::STATE_ROOT;
        if ($type == self::FCGI_BEGIN_REQUEST) {
            //++Daemon::$process->reqCounter;
            $u = unpack('nrole/Cflags', $this->content);

            $req                    = new \stdClass();
            $this->request = $req;
            $req->attrs             = new \stdClass;
            $req->attrs->request    = [];
            $req->attrs->get        = [];
            $req->attrs->post       = [];
            $req->attrs->cookie     = [];
            $req->attrs->server     = [];
            $req->attrs->files      = [];
            $req->attrs->session    = null;
            $req->attrs->role       = self::$roles[$u['role']];
            $req->attrs->flags      = $u['flags'];
            $req->attrs->id         = $header['reqid'];
            $req->attrs->paramsDone = false;
            $req->attrs->inputDone  = false;
            $req->attrs->chunked    = false;
            $req->attrs->noHttpVer  = true;
            $req->queueId           = $rid;
            $this->requests[$rid]   = $req;
            $this->request   = $req;
            $this->requestId   = $rid;
        } else {
            error_log('Unexpected FastCGI-record #' . $this->header['type'] . ' (' . $this->header['ttype'] . '). Request ID: ' . $rid . '.');
            return;
        }
        if ($type === self::FCGI_ABORT_REQUEST) {
            error_log('fcgi aborted');
        } elseif ($type === self::FCGI_PARAMS) {
            if ($content === '') {
                if (!isset($req->attrs->server['REQUEST_TIME'])) {
                    $req->attrs->server['REQUEST_TIME'] = time();
                }
                if (!isset($req->attrs->server['REQUEST_TIME_FLOAT'])) {
                    $req->attrs->server['REQUEST_TIME_FLOAT'] = microtime(true);
                }
                $req->attrs->paramsDone = true;
                $this->requests[$rid] = $req;
            }
            else {
                $p = 0;

                while ($p < $header['conlen']) {
                    if (($namelen = ord($content{$p})) < 128) {
                        ++$p;
                    }
                    else {
                        $u       = unpack('Nlen', chr(ord($content{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
                        $namelen = $u['len'];
                        $p += 4;
                    }

                    if (($vlen = ord($content{$p})) < 128) {
                        ++$p;
                    }
                    else {
                        $u    = unpack('Nlen', chr(ord($content{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
                        $vlen = $u['len'];
                        $p += 4;
                    }

                    $req->attrs->server[binarySubstr($content, $p, $namelen)] = binarySubstr($this->content, $p + $namelen, $vlen);
                    $p += $namelen + $vlen;
                }
            }
        }
        elseif ($type === self::FCGI_STDIN) {
            if (!$req->attrs->input) {
                goto start;
            }
            if ($content === '') {
                error_log("sendEOF to input");
            }
            else {
                //$req->attrs->input->readFromString($this->content);
            }
        }
        goto start;
    }
}
