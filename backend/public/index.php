<?php
/** The only public PHP entry point. */
require_once dirname(__DIR__).'/includes/render.php';
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
function respond(array $value,int $code=200): never {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($value,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function bearer(): string {
    $header=$_SERVER['HTTP_AUTHORIZATION']??'';
    if (!str_starts_with($header,'Bearer ')) { throw new \MSVCR\RequestError('Authentication required.',403); }
    return substr($header,7);
}
try {
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH); $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($path==='/healthz'&&$method==='GET') {
        // Public, cheap readiness probe; never includes configuration or secrets.
        header('Access-Control-Allow-Origin: *');
        $configured=is_file(getenv('MSVCR_CONFIG') ?: '/var/lib/msvcr-config/config.json');
        if (!$configured) { respond(['ok'=>true,'configured'=>false]); }
        $probe=new \MSVCR\Queue(\MSVCR\config());
        $ready=$probe->alive();
        respond(['ok'=>$ready,'configured'=>true],$ready?200:503);
    }
    $c=\MSVCR\config(); $q=new \MSVCR\Queue($c);
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH); $method=$_SERVER['REQUEST_METHOD']??'GET';
    if ($path==='/download'&&in_array($method,['GET','HEAD'],true)) {
        $id=is_string($_GET['id']??null)?$_GET['id']:'';
        $exp=is_string($_GET['expires']??null)&&preg_match('/^[0-9]{1,12}$/D',$_GET['expires'])?(int)$_GET['expires']:0;
        $sig=is_string($_GET['sig']??null)?$_GET['sig']:'';
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$sig)||$exp<=time()||$exp>time()+$c['ttl']*60||!hash_equals(hash_hmac('sha256',$id.':'.$exp,$c['secret']),$sig)) { throw new \MSVCR\RequestError('This download link is invalid or expired.',403); }
        $j=$q->store->read($id);
        if ($j['kind']!=='convert'||$j['state']!=='complete'||$j['expires']<$exp||!in_array($j['result']['file']??'',['media/download.mp4','media/download.mp3'],true)) { throw new \MSVCR\RequestError('This download has expired.',410); }
        $file=$q->store->dir($id).'/'.$j['result']['file'];
        if (!is_file($file)||is_link($file)) { throw new \MSVCR\RequestError('The download is unavailable.',410); }
        $size=filesize($file);
        try { [$start,$end,$partial]=\MSVC\Input::range($_SERVER['HTTP_RANGE']??null,$size); }
        catch (\Throwable $e) { header('Content-Range: bytes */'.$size); throw new \MSVCR\RequestError('Invalid download range.',416); }
        $handle=fopen($file,'rb'); if (!$handle) { throw new \MSVCR\RequestError('The download is unavailable.',410); }
        header('Content-Type: '.$j['result']['mime']);
        header('Content-Disposition: attachment; filename="'.$j['result']['filename'].'"');
        header('Accept-Ranges: bytes'); header('Content-Length: '.($end-$start+1));
        if ($partial) { http_response_code(206); header('Content-Range: bytes '.$start.'-'.$end.'/'.$size); }
        if ($method==='HEAD') { fclose($handle); exit; }
        fseek($handle,$start); $remaining=$end-$start+1;
        while ($remaining>0&&!connection_aborted()) { $chunk=fread($handle,min(65536,$remaining)); if ($chunk===false||$chunk==='') { break; } echo $chunk; $remaining-=strlen($chunk); }
        fclose($handle); exit;
    }
    if ($path==='/health'&&$method==='GET') {
        if (!hash_equals($c['secret'],bearer())) { throw new \MSVCR\RequestError('Authentication required.',403); }
        foreach (['yt_dlp','ffmpeg','ffprobe','setsid','js_path'] as $key) { if (!is_executable($c[$key])) { throw new \RuntimeException('A required backend executable is missing.'); } }
        if (!function_exists('proc_open')||!function_exists('posix_kill')) { throw new \RuntimeException('PHP process/POSIX support is missing.'); }
        $enc=\MSVC\Process::run([$c['ffmpeg'],'-hide_banner','-encoders'],5,$c['setsid']);
        if ($enc['code']!==0||!str_contains($enc['stdout'],'libx264')||!str_contains($enc['stdout'],'libmp3lame')) { throw new \RuntimeException('Required FFmpeg encoders are unavailable.'); }
        $node=\MSVC\Process::run([$c['js_path'],'--version'],5,$c['setsid']);
        if ($node['code']!==0||!preg_match('/^v(\d+)/',$node['stdout'],$m)||((int)$m[1])<22) { throw new \RuntimeException('Node.js 22 or newer is required.'); }
        $extractor=\MSVC\Process::run([$c['yt_dlp'],'--version'],5,$c['setsid']);
        if ($extractor['code']!==0) { throw new \RuntimeException('The extractor executable did not start.'); }
        $paused=is_file($q->store->root.'/paused');
        respond(['ok'=>$q->alive()&&!$paused,'origin'=>$c['origin'],'worker_alive'=>$q->alive(),'limits'=>array_intersect_key($c,array_flip(['daily','max_minutes','max_mb','max_height','max_queue','timeout','ttl','queue_timeout'])),'message'=>$paused?'Resume the paused converter.':($q->alive()?'Engine and worker checks passed. Live YouTube access still needs testing.':'Restart the Render web service to recover its worker.')]);
    }
    if (!in_array($path,['/submit','/status','/cancel'],true)) { throw new \MSVCR\RequestError('Endpoint not found.',404); }
    if (($_SERVER['HTTP_ORIGIN']??'')!==$c['origin']) { throw new \MSVCR\RequestError('Open the converter on the configured website.',403); }
    header('Access-Control-Allow-Origin: '.$c['origin']); header('Vary: Origin');
    if ($method==='OPTIONS') {
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); header('Access-Control-Allow-Headers: Authorization, Content-Type'); header('Access-Control-Max-Age: 600'); http_response_code(204); exit;
    }
    if ($path==='/submit'&&$method==='POST') {
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>4096) { throw new \MSVCR\RequestError('The request body is too large.',413); }
        $body=file_get_contents('php://input',false,null,0,4097);
        if (strlen($body)>4096) { throw new \MSVCR\RequestError('The request body is too large.',413); }
        $p=\MSVCR\Ticket::decode(bearer(),$c); respond($q->submit($p),202);
    }
    if ($path==='/status'&&$method==='GET') {
        $id=is_string($_GET['id']??null)?$_GET['id']:'';
        respond($q->publicJob($q->authorized($id,bearer())));
    }
    if ($path==='/cancel'&&$method==='POST') {
        $raw=file_get_contents('php://input',false,null,0,4097);
        if (strlen($raw)>4096) { throw new \MSVCR\RequestError('The request body is too large.',413); }
        $body=json_decode($raw,true,8);
        $q->cancel(is_string($body['id']??null)?$body['id']:'',bearer()); respond(['ok'=>true]);
    }
    throw new \MSVCR\RequestError('This method is not supported.',405);
} catch (\MSVCR\RequestError $e) { respond(['message'=>$e->getMessage()],$e->status); }
catch (\Throwable $e) { error_log('MSVCR API failure: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine()); respond(['message'=>'The backend could not complete this request. Ask the website owner to check the server.'],503); }
