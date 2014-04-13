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

class Connection {
	protected $lowMark = 8; // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFFFF; // initial value of the maximum amout of bytes in buffer
	public $timeout = 180;

	protected $requests = [];
	protected $state = 0;

    protected $request;
    protected $requestId;

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

    protected $file=null;
	protected $data;
    protected $dataCurrent = -1;
    protected $dataLength = -1;

    public function setData($data) {
        $this->data = $data;
        $this->dataLenght = strlen($data);
        $this->dataCurrent = 0;
    }

    public function setSteam($file) {
        $this->file = $file;
    }

    public function readExact($n){
        if($n>0 && $this->dataCurrent< $this->dataLenght){
            $str = substr($this->data, $this->dataCurrent, $n);
            $this->dataCurrent += $n;
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
    }
    public function readExactStream($n){
        if ($n >$this->highMark) {
            //$n = $this->highMark;
        }
        if ($n <$this->lowMark) {
            //$n = $this->lowMark;
        }

        if(!feof($this->file)  && $n>0 ){
            $str = fread($this->file, $n);
            $str = substr($this->file, $n);
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
    }

    public function setWatermark($low = null, $high = null) {
		if ($low !== null) {
			$this->lowMark = $low;
		}
		if ($high !== null) {
			$this->highMark = $high;
		}
        //$this->bev->setWatermark(\Event::READ, $this->lowMark, $this->highMark);
	}

	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			$header = $this->readExact(8);

			if ($header === false) {
				return;
			}

            /*
             * struct {
             *      unsigned char ver; 8b
             *      unsigned char type; 8b
             *      unsigned short reqid; 16b
             *      unsigned short conlen; 16b
             *      unsigned char padlen; 8b
             *      unsigned char reverved; 8b
             * }
             *
             */
			$this->header = unpack('Cver/Ctype/nreqid/nconlen/Cpadlen/Creserved', $header);

			if ($this->header['conlen'] > 0) {
				$this->setWatermark($this->header['conlen'], $this->header['conlen']);
			}
			$type                  = $this->header['type'];
			$this->header['ttype'] = isset(self::$requestTypes[$type]) ? self::$requestTypes[$type] : $type;
			$rid                   = $this->header['reqid'];
			$this->state           = self::STATE_CONTENT;

		}
		else {
			$type = $this->header['type'];
			$rid  = $this->header['reqid'];
		}
		if ($this->state === self::STATE_CONTENT) {
			$this->content = $this->readExact($this->header['conlen']);

			if ($this->content === false) {
                $this->setWatermark($this->header['conlen'], $this->header['conlen']);
				return;
			}

			if ($this->header['padlen'] > 0) {
                $this->setWatermark($this->header['padlen'], $this->header['padlen']);
			}

			$this->state = self::STATE_PADDING;
		}

		if ($this->state === self::STATE_PADDING) {
			$pad = $this->readExact($this->header['padlen']);

			if ($pad === false) {
				return;
			}
		}
        $this->setWatermark(8, 0xFFFFFF);
		$this->state = self::STATE_ROOT;

		//error_log('[DEBUG] FastCGI-record ' . $this->header['ttype'] . '). Request ID: ' . $rid 
				//. '. Content length: ' . $this->header['conlen'] . ' (' . strlen($this->content) . ') Padding length: ' . $this->header['padlen'] 
				//. ' (' . strlen($pad) . ')');

		if ($type == self::FCGI_BEGIN_REQUEST) {
			//++Daemon::$process->reqCounter;
			$u = unpack('nrole/Cflags', $this->content);

			$req                    = new \stdClass();
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
			$req->attrs->id         = $this->header['reqid'];
			$req->attrs->paramsDone = false;
			$req->attrs->inputDone  = false;
			$req->attrs->input      = new \stdClass();
			$req->attrs->chunked    = false;
			$req->attrs->noHttpVer  = true;
			$req->queueId           = $rid;
			$this->requests[$rid]   = $req;
			$this->request   = $req;
			$this->requestId   = $rid;
		}
		elseif (isset($this->requests[$rid])) {
			$req = $this->requests[$rid];
		}
		else {
			error_log('Unexpected FastCGI-record #' . $this->header['type'] . ' (' . $this->header['ttype'] . '). Request ID: ' . $rid . '.');
			return;
		}

		if ($type === self::FCGI_ABORT_REQUEST) {
			error_log('fcgi aborted');
		}
		elseif ($type === self::FCGI_PARAMS) {
			if ($this->content === '') {
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

				while ($p < $this->header['conlen']) {
					if (($namelen = ord($this->content{$p})) < 128) {
						++$p;
					}
					else {
						$u       = unpack('Nlen', chr(ord($this->content{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
						$namelen = $u['len'];
						$p += 4;
					}

					if (($vlen = ord($this->content{$p})) < 128) {
						++$p;
					}
					else {
						$u    = unpack('Nlen', chr(ord($this->content{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
						$vlen = $u['len'];
						$p += 4;
					}

					$req->attrs->server[binarySubstr($this->content, $p, $namelen)] = binarySubstr($this->content, $p + $namelen, $vlen);
					$p += $namelen + $vlen;
				}
			}
		}
		elseif ($type === self::FCGI_STDIN) {
			if (!$req->attrs->input) {
				goto start;
			}
			if ($this->content === '') {
			    error_log("sendEOF to input");
			}
			else {
				$req->attrs->input->readFromString($this->content);
			}
		}

		if (
				$req->attrs->inputDone
				&& $req->attrs->paramsDone
		) {
			$order = $this->pool->variablesOrder ?: 'GPC';
			for ($i = 0, $s = strlen($order); $i < $s; ++$i) {
				$char = $order[$i];

				if ($char == 'G' && is_array($req->attrs->get)) {
					$req->attrs->request += $req->attrs->get;
				}
				elseif ($char == 'P' && is_array($req->attrs->post)) {
					$req->attrs->request += $req->attrs->post;
				}
				elseif ($char == 'C' && is_array($req->attrs->cookie)) {
					$req->attrs->request += $req->attrs->cookie;
				}
			}

			//Daemon::$process->timeLastActivity = time();
		}
		goto start;
	}

	/**
	 * Handles the output from downstream requests.
	 * @param $req
	 * @param $appStatus
	 * @param $protoStatus
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		$c = pack('NC', $appStatus, $protoStatus) // app status, protocol status
				. "\x00\x00\x00";

        return "\x01" // protocol version
			. "\x03" // record type (END_REQUEST)
			. pack('nn', $req->attrs->id, strlen($c)) // id, content length
			. "\x00" // padding length
			. "\x00" // reserved
			. $c; // content

		//if ($protoStatus === -1) {
			//$this->close();
		//}
		//elseif (!$this->pool->config->keepalive->value) {
			//$this->finish();
		//}
	}

    public function getRequests() {
        return $this->requests;
    }
    public function getRequest() {
        return $this->request;
    }
    public function getRId() {
        return $this->requestId;
    }
}
// vim:nolist
