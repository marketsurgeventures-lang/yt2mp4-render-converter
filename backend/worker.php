<?php
/** The container supervisor owns this worker; no conversion runs inside a web request. */
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/includes/render.php';
$queue=new \MSVCR\Queue(\MSVCR\config());
$daemon=$queue->store->lock('daemon',true);
if (!$daemon) { fwrite(STDERR,"Another converter worker is already running.\n"); exit(1); }
register_shutdown_function(fn()=>\MSVC\Process::stop());
$stop=false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM,SIGINT] as $signal) { pcntl_signal($signal,function() use(&$stop) { $stop=true; \MSVC\Process::stop(); }); }
}
$queue->recover(); $lastCleanup=0;
while (!$stop) {
    $queue->beat();
    if (time()-$lastCleanup>=30) { $queue->store->cleanup(); $lastCleanup=time(); }
    if (!is_file($queue->store->root.'/paused')&&($job=$queue->next())) { $queue->process($job); }
    else { usleep(500000); }
    if (in_array('--once',$argv,true)) { break; }
}
flock($daemon,LOCK_UN); fclose($daemon);
