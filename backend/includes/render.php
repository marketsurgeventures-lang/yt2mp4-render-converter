<?php
/** Original Render container transport, ephemeral queue admission and private downloads. */
namespace MSVCR;
require_once __DIR__.'/core.php';
require_once __DIR__.'/runtime.php';

final class RequestError extends \RuntimeException {
    public function __construct(string $message, public int $status = 400) { parent::__construct($message); }
}
function config(): array {
    $path=getenv('MSVCR_CONFIG') ?: '/var/lib/msvcr-config/config.json';
    if (!is_file($path)||filesize($path)>65536) { throw new \RuntimeException('The backend configuration is missing.'); }
    $c=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
    if (!is_array($c)||!preg_match('/^[a-f0-9]{64}$/D',$c['secret']??'')) { throw new \RuntimeException('Invalid backend configuration.'); }
    foreach (['origin','backend_url'] as $key) {
        $u=parse_url($c[$key]??'');
        if (!$u||($u['scheme']??'')!=='https'||empty($u['host'])||isset($u['user'])||isset($u['pass'])||isset($u['query'])||isset($u['fragment'])||isset($u['port'])||!in_array($u['path']??'',['','/'],true)) { throw new \RuntimeException('Configure exact HTTPS origins without paths or ports.'); }
        $c[$key]=rtrim($c[$key],'/');
    }
    $c=array_merge(['storage'=>'/var/lib/msvcr','yt_dlp'=>'/opt/msvcr/venv/bin/yt-dlp','ffmpeg'=>'/usr/bin/ffmpeg','ffprobe'=>'/usr/bin/ffprobe','setsid'=>'/usr/bin/setsid','js_type'=>'node','js_path'=>'/usr/local/bin/node','max_minutes'=>2,'max_mb'=>20,'max_height'=>360,'timeout'=>600,'ttl'=>10,'analysis_ttl'=>30,'queue_timeout'=>900,'max_queue'=>6,'daily'=>20,'analyses'=>40,'storage_mb'=>1024],$c);
    foreach (['max_minutes'=>[1,5],'max_mb'=>[1,50],'max_height'=>[144,720],'timeout'=>[30,600],'ttl'=>[5,30],'analysis_ttl'=>[10,120],'queue_timeout'=>[60,1800],'max_queue'=>[1,20],'daily'=>[1,200],'analyses'=>[1,400],'storage_mb'=>[256,2048]] as $key=>$range) {
        $c[$key]=(int)$c[$key];
        if ($c[$key]<$range[0]||$c[$key]>$range[1]) { throw new \RuntimeException('A backend limit is outside the supported range.'); }
    }
    if ($c['js_type']!=='node') { throw new \RuntimeException('This package uses the Node.js runtime.'); }
    return $c;
}
function engineConfig(array $c): array {
    return array_intersect_key($c,array_flip(['yt_dlp','ffmpeg','ffprobe','setsid','js_type','js_path','max_minutes','max_mb','max_height','timeout','ttl']));
}
function atomicJson(string $path, array $value): void {
    $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
    if (file_put_contents($tmp,json_encode($value,JSON_THROW_ON_ERROR),LOCK_EX)===false) { throw new \RuntimeException('Cannot save server state.'); }
    chmod($tmp,0600);
    if (!rename($tmp,$path)) { @unlink($tmp); throw new \RuntimeException('Cannot save server state.'); }
}
final class Ticket {
    public static function decode(string $ticket, array $c): array {
        if (strlen($ticket)>12000||!preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$ticket,$m)||!hash_equals(hash_hmac('sha256',$m[1],$c['secret']),$m[2])) { throw new RequestError('Invalid processing authorization.',403); }
        $raw=base64_decode(strtr($m[1],'-_','+/'),true);
        $p=$raw===false?null:json_decode($raw,true,16);
        if (!is_array($p)||!is_int($p['exp']??null)||$p['exp']<=time()||$p['exp']>time()+180||($p['origin']??'')!==$c['origin']||!preg_match('/^[a-f0-9]{32}$/D',$p['id']??'')||!preg_match('/^[a-f0-9]{64}$/D',$p['owner']??'')||!in_array($p['kind']??'',['analyze','convert'],true)) { throw new RequestError('The processing authorization expired or is invalid.',403); }
        if ($p['kind']==='analyze') { $p['url']=\MSVC\Input::video((string)($p['url']??''))['url']; }
        else {
            if (!preg_match('/^[a-f0-9]{32}$/D',$p['analysis']??'')||!in_array($p['format']??'',['mp4','mp3'],true)||!is_int($p['quality']??null)||($p['format']==='mp3'&&!in_array($p['quality'],[128,192,320],true))||($p['format']==='mp4'&&($p['quality']<1||$p['quality']>$c['max_height']))) { throw new RequestError('Choose a valid analysis, format and quality.'); }
        }
        return $p;
    }
}
final class Queue {
    public \MSVC\Store $store;
    public function __construct(public array $config) { $this->store=new \MSVC\Store($config['storage'],[dirname(__DIR__).'/public']); }
    public function jobs(): array {
        $jobs=[];
        foreach (glob($this->store->root.'/jobs/*/job.json')?:[] as $path) {
            try { $j=$this->store->read(basename(dirname($path))); if ($j['expires']>time()) { $jobs[]=$j; } } catch (\Throwable $e) {}
        }
        usort($jobs,fn($a,$b)=>($a['sequence']??$a['created'])<=>($b['sequence']??$b['created']));
        return $jobs;
    }
    public function alive(): bool {
        $path=$this->store->root.'/worker.json';
        if (!is_file($path)) { return false; }
        $worker=json_decode((string)file_get_contents($path),true);
        return is_array($worker)&&($worker['heartbeat']??0)>time()-45;
    }
    public function beat(?string $job=null): void { atomicJson($this->store->root.'/worker.json',['heartbeat'=>time(),'pid'=>getmypid(),'job'=>$job]); }
    public function submit(array $p): array {
        $s=$this->store; $c=$this->config; $lock=$s->lock('admission');
        try {
            if (is_file($s->root.'/paused')) { throw new RequestError('The converter is temporarily paused.',503); }
            if (!$this->alive()) { throw new RequestError('The conversion worker is unavailable. Try again later.',503); }
            if (is_dir($s->dir($p['id']))) { throw new RequestError('This authorization has already been used.',409); }
            $active=array_values(array_filter($this->jobs(),fn($j)=>in_array($j['state'],['queued','running'],true)));
            if (count($active)>=$c['max_queue']) { throw new RequestError('The waiting queue is full. Try again shortly.',429); }
            if (count(array_filter($active,fn($j)=>hash_equals($j['owner'],$p['owner'])))>=2) { throw new RequestError('Wait for your current requests to finish.',429); }
            $free=disk_free_space($s->root);
            if ($free!==false&&$free<1073741824) { throw new RequestError('The server is low on free disk space.',503); }
            $used=0;
            foreach (glob($s->root.'/jobs/*/media/*')?:[] as $path) { if (is_file($path)) { $used+=filesize($path); } }
            if ($used>($c['storage_mb']-$c['max_mb']*3)*1048576) { throw new RequestError('Temporary storage is busy. Try again later.',503); }
            $job=['id'=>$p['id'],'owner'=>$p['owner'],'kind'=>$p['kind'],'state'=>'queued','phase'=>'Waiting in queue','progress'=>0,'created'=>time(),'sequence'=>microtime(true),'expires'=>time()+$c['queue_timeout']+$c['timeout']+600,'config'=>engineConfig($c)];
            if ($p['kind']==='convert') {
                try { $a=$s->read($p['analysis']); } catch (\Throwable $e) { throw new RequestError('The analysis expired. Check the video again.'); }
                if ($a['kind']!=='analyze'||$a['state']!=='complete'||$a['expires']<=time()||!hash_equals($a['owner'],$p['owner'])) { throw new RequestError('This analysis is unavailable for your session.',403); }
                $info=$a['result'];
                if (($p['format']==='mp4'&&!in_array($p['quality'],$info['qualities'],true))||($p['format']==='mp3'&&!$info['has_audio'])) { throw new RequestError('The selected source quality is unavailable.'); }
                $job+=['url'=>$a['url'],'info'=>$info,'format'=>$p['format'],'quality'=>$p['quality']];
            } else { $job['url']=$p['url']; }
            $path=$s->root.'/admission.json';
            $counts=is_file($path)?json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR):[];
            if (($counts['day']??'')!==gmdate('Y-m-d')) { $counts=['day'=>gmdate('Y-m-d'),'analyze'=>0,'convert'=>0]; }
            $max=$p['kind']==='convert'?$c['daily']:$c['analyses'];
            if (($counts[$p['kind']]??0)>=$max) { throw new RequestError('Today’s converter allowance has been reached. Try again tomorrow.',429); }
            $counts[$p['kind']]=($counts[$p['kind']]??0)+1;
            // Reserve quota before creating a job: a crash can waste a slot, never increase the cap.
            atomicJson($path,$counts);
            $token=bin2hex(random_bytes(32)); $job['access_hash']=hash('sha256',$token);
            $s->write($job);
            return ['job'=>$p['id'],'access_token'=>$token,'kind'=>$p['kind']];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function authorized(string $id, string $token): array {
        try { $j=$this->store->read($id); } catch (\Throwable $e) { throw new RequestError('This request is unavailable.',404); }
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)||!hash_equals($j['access_hash']??'',hash('sha256',$token))) { throw new RequestError('Invalid request access.',403); }
        if ($j['expires']<=time()) { throw new RequestError('This request expired. Start again.',410); }
        return $j;
    }
    public function publicJob(array $j): array {
        $data=array_intersect_key($j,array_flip(['id','kind','state','phase','progress','expires','message']));
        if ($j['state']==='queued') {
            $position=0;
            foreach ($this->jobs() as $pending) { if (in_array($pending['state'],['queued','running'],true)) { $position++; if ($pending['id']===$j['id']) { break; } } }
            $data['position']=$position;
        }
        if ($j['state']==='complete') {
            $data['result']=$j['result'];
            if ($j['kind']==='convert') {
                unset($data['result']['file']);
                $exp=min($j['expires'],time()+$this->config['ttl']*60);
                $sig=hash_hmac('sha256',$j['id'].':'.$exp,$this->config['secret']);
                $data['result']['download_url']=$this->config['backend_url'].'/download?id='.$j['id'].'&expires='.$exp.'&sig='.$sig;
            }
        }
        return $data;
    }
    public function cancel(string $id, string $token): void {
        $lock=$this->store->lock('admission');
        try {
            $j=$this->authorized($id,$token);
            if (!in_array($j['state'],['queued','running'],true)) { return; }
            $path=$this->store->dir($id).'/cancel.flag';
            file_put_contents($path,'cancel'); chmod($path,0600);
            if ($j['state']==='queued') { $j['state']='failed'; $j['message']='This request was cancelled.'; $j['expires']=time()+600; $this->store->write($j); }
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function cleanMedia(string $id): void { \MSVC\Store::remove($this->store->dir($id).'/media'); }
    public function recover(): void {
        foreach ($this->jobs() as $j) {
            if ($j['state']==='running') { $this->cleanMedia($j['id']); $j['state']='failed'; $j['message']='The worker restarted during processing. Start a new request.'; $j['expires']=time()+600; $this->store->write($j); }
        }
    }
    public function next(): ?array {
        $lock=$this->store->lock('admission');
        try {
            foreach ($this->jobs() as $j) {
                if ($j['state']!=='queued') { continue; }
                if (is_file($this->store->dir($j['id']).'/cancel.flag')||time()-$j['created']>$this->config['queue_timeout']) {
                    $j['state']='failed'; $j['message']=is_file($this->store->dir($j['id']).'/cancel.flag')?'This request was cancelled.':'The queue wait limit was reached. Try again later.'; $j['expires']=time()+600; $this->store->write($j); continue;
                }
                $j['state']='running'; $j['phase']='Starting processing'; $j['started']=time(); $this->store->write($j); return $j;
            }
            return null;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
    public function process(array $j): void {
        $workerLock=fopen($this->store->dir($j['id']).'/worker.lock','c+');
        flock($workerLock,LOCK_EX);
        $this->store->observer=function($written) use($j) { $this->beat($j['id']); };
        try {
            $engine=new \MSVC\Engine($this->store,$j);
            $result=$j['kind']==='analyze'?$engine->analyze():$engine->convert();
            if (is_file($this->store->dir($j['id']).'/cancel.flag')) { throw new \RuntimeException('This request was cancelled.'); }
            $latest=$this->store->read($j['id']);
            $latest['state']='complete'; $latest['phase']='Complete'; $latest['progress']=100; $latest['result']=$result;
            $latest['expires']=time()+($j['kind']==='analyze'?$this->config['analysis_ttl']:$this->config['ttl'])*60;
            $this->store->write($latest);
        } catch (\Throwable $e) {
            \MSVC\Process::stop(); $this->cleanMedia($j['id']);
            $latest=$this->store->read($j['id']); $latest['state']='failed'; $latest['phase']='Failed';
            $latest['message']=$e instanceof \RuntimeException?substr($e->getMessage(),0,250):'Processing failed. Please try again later.';
            $latest['expires']=time()+600; $this->store->write($latest);
            error_log('MSVCR job '.$j['id'].' failed: '.get_class($e));
        } finally { $this->store->observer=null; flock($workerLock,LOCK_UN); fclose($workerLock); $this->beat(); }
    }
}
