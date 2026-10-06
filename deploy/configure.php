<?php
/** Runtime-only configuration. No secrets are used as Docker build arguments. */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/backend/includes/render.php';
$path=getenv('MSVCR_CONFIG') ?: '/var/lib/msvcr-config/config.json';
try {
    $enabled=getenv('MSVCR_ENABLED') ?: '0';
    if (!in_array($enabled,['0','1'],true)) { throw new RuntimeException('MSVCR_ENABLED must be 0 or 1.'); }
    if ($enabled==='0') {
        if (is_file($path)) { unlink($path); }
        fwrite(STDOUT,"Setup mode: configure the WordPress origin and secret, then enable the service.\n");
        exit(10);
    }
    $config=[
        'secret'=>getenv('MSVCR_SHARED_SECRET') ?: '',
        'origin'=>getenv('WORDPRESS_ORIGIN') ?: '',
        'backend_url'=>getenv('MSVCR_BACKEND_URL') ?: (getenv('RENDER_EXTERNAL_URL') ?: ''),
        'storage'=>getenv('MSVCR_STORAGE') ?: '/var/lib/msvcr',
        'yt_dlp'=>'/opt/msvcr/venv/bin/yt-dlp',
        'ffmpeg'=>'/usr/bin/ffmpeg','ffprobe'=>'/usr/bin/ffprobe','setsid'=>'/usr/bin/setsid',
        'js_type'=>'node','js_path'=>'/usr/local/bin/node',
    ];
    foreach (['daily'=>20,'max_minutes'=>2,'max_height'=>360,'max_mb'=>20] as $key=>$default) {
        $value=getenv('MSVCR_'.strtoupper($key));
        if ($value!==false&&!preg_match('/^[0-9]{1,4}$/D',$value)) { throw new RuntimeException('A service limit must be an integer.'); }
        $config[$key]=$value===false?$default:(int)$value;
    }
    $config['analyses']=$config['daily']*2;
    if (!str_starts_with($path,'/')||is_link($path)||is_link(dirname($path))) { throw new RuntimeException('The private configuration path is invalid.'); }
    if (!is_dir(dirname($path))) { mkdir(dirname($path),0700,true); }
    \MSVCR\atomicJson($path,$config);
    putenv('MSVCR_CONFIG='.$path);
    $validated=\MSVCR\config();
    $queue=new \MSVCR\Queue($validated);
    fwrite(STDOUT,"Conversion service configured; one worker, short video pilot limits.\n");
} catch (Throwable $e) {
    if (is_file($path)) { @unlink($path); }
    fwrite(STDERR,"Startup configuration failed: ".$e->getMessage()."\n");
    exit(1);
}
