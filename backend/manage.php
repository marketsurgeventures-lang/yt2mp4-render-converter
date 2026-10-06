<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/includes/render.php';
$q=new \MSVCR\Queue(\MSVCR\config());
switch ($argv[1]??'status') {
    case 'pause': file_put_contents($q->store->root.'/paused','paused'); echo "New submissions paused; the current job may finish.\n"; break;
    case 'resume': @unlink($q->store->root.'/paused'); echo "Submissions resumed.\n"; break;
    case 'prune': $q->store->cleanup(); echo "Expired jobs cleaned.\n"; break;
    case 'status':
        $states=[]; foreach ($q->jobs() as $j) { $states[$j['state']]=($states[$j['state']]??0)+1; }
        echo json_encode(['worker_alive'=>$q->alive(),'paused'=>is_file($q->store->root.'/paused'),'jobs'=>$states,'daily_limit'=>$q->config['daily']],JSON_PRETTY_PRINT)."\n"; break;
    default: fwrite(STDERR,"Use status, pause, resume or prune.\n"); exit(2);
}
