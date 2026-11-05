<?php
session_start(); error_reporting(0);
chdir(dirname(__DIR__));
require_once './include/reseller_acl.php';
require_once './include/user_transfer.php';
require_once './include/user_transfer_ui.php';
include './include/theme.php';
$transferTheme = $_SESSION['theme'] ?? $theme;
if (!in_array($transferTheme, array('dark','light','blue','green','pink'), true)) $transferTheme='dark';

$session=is_string($_GET['session']??null)?$_GET['session']:'';
if(!isset($_SESSION['mikhmon'])||!canAccessRouterSession($session)||!hasResellerPermission('export_users')||!hasResellerPermission('generate_voucher')) { http_response_code(403);exit(transferText('Access denied')); }
if(empty($_SESSION['transfer_csrf']))$_SESSION['transfer_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['transfer_csrf'];
if(isset($_SESSION['mikhmon_last_activity'])&&time()-(int)$_SESSION['mikhmon_last_activity']>300){http_response_code(401);exit(transferText('Session expired. Sign in again.'));}
function transferReply($value,$status=200){http_response_code($status);header('Content-Type: application/json');header('Cache-Control: no-store');session_write_close();if(isset($value['error']))$value['error']=transferText($value['error']);if(isset($value['message']))$value['message']=transferText($value['message']);echo json_encode($value);exit;}
if($_SERVER['REQUEST_METHOD']==='POST') {
 try {
    if(!is_string($_POST['csrf']??null)||!hash_equals($csrf,$_POST['csrf']))throw new Exception('Reload this page.');
    $_SESSION['mikhmon_last_activity']=time();
    require './include/config.php';require_once './lib/routeros_api.class.php';
    if(!isset($data[$session]))throw new Exception('Router not found.');
    $api=new RouterosAPI();$api->timeout=5;$api->attempts=1;
    if(!$api->connect(explode('!',$data[$session][1],2)[1],explode('@|@',$data[$session][2],2)[1],decrypt(explode('#|#',$data[$session][3],2)[1])))throw new Exception('Router connection failed.');
    $call=function($cmd,$args=[])use($api){$r=$api->comm($cmd,$args);if(!$api->connected||(is_array($r)&&(isset($r['!trap'])||isset($r['!fatal']))))throw new Exception('Router rejected or interrupted the request. Check users before retrying.');return $r;};
    $action=$_POST['action']??'';
    if($action==='lists'){
        $batches=[];foreach($call('/ip/hotspot/user/print',['.proplist'=>'comment']) as $row){$comment=$row['comment']??'';if(preg_match('/^(vc|up)-/',$comment))$batches[$comment]=true;}
        transferReply(['profiles'=>$call('/ip/hotspot/user/profile/print',['.proplist'=>'name']),'servers'=>$call('/ip/hotspot/print',['.proplist'=>'name']),'batches'=>array_keys($batches)]);
    }
    if($action==='export' || $action==='export-rsc') {
        $target=(int)($_POST['target']??0);if(!in_array($target,[6,7],true))throw new Exception('Select destination OS.');
        $mode=$_POST['filter']??'all';$args=[];
        if($mode==='profile')$args['?profile']=(string)($_POST['value']??'');
        elseif($mode==='batch')$args['?comment']=(string)($_POST['value']??'');
        elseif($mode!=='all')throw new Exception('Invalid filter.');
        if($mode!=='all'&&reset($args)==='')throw new Exception('Enter a profile or exact batch comment.');
        $rows=$call('/ip/hotspot/user/print',$args);$users=[];
        foreach($rows as $row) {if(!is_array($row)||!isset($row['name'])||$row['name']==='default-trial'||($row['dynamic']??'false')==='true')continue;
            $users[]=transferUser($row,$row['profile']??'default');
        }
        if($action==='export-rsc')transferReply(['rsc'=>transferRsc($users,$target),'target_os'=>$target,'count'=>count($users)]);
        transferReply(['format'=>'ngw-hotspot-users','version'=>1,'target_os'=>$target,'exported_at'=>gmdate('c'),'users'=>$users]);
    }
    if($action==='prepare') {
        if(($_FILES['file']['error']??1)!==UPLOAD_ERR_OK||($_FILES['file']['size']??0)>10485760)throw new Exception('Choose a transfer file smaller than 10 MB.');
        $file=json_decode(file_get_contents($_FILES['file']['tmp_name']),true);
        if(!is_array($file)||($file['format']??'')!=='ngw-hotspot-users'||($file['version']??0)!==1||!in_array($file['target_os']??0,[6,7],true)||!is_array($file['users']??null)||count($file['users'])>10000)throw new Exception('Invalid transfer file.');
        $resource=$call('/system/resource/print',['.proplist'=>'version']);
        if((int)($resource[0]['version']??0)!==$file['target_os'])throw new Exception('Destination OS does not match export target. Export again with the correct OS.');
        $profile=(string)($_POST['profile']??'');$server=(string)($_POST['server']??'all');
        if(empty($call('/ip/hotspot/user/profile/print',['?name'=>$profile])))throw new Exception('Select an existing destination profile.');
        if($server!=='all'&&empty($call('/ip/hotspot/print',['?name'=>$server])))throw new Exception('Destination server not found.');
        $users=[];$seen=[];
        foreach($file['users'] as $row){if(!is_array($row))throw new Exception('Invalid user row.');$u=transferUser($row,$profile,$server);if(isset($seen[$u['name']]))throw new Exception('Duplicate username in file.');$seen[$u['name']]=true;$users[]=$u;}
        $job=bin2hex(random_bytes(16));$_SESSION['user_transfer']=[$job=>['router'=>$session,'users'=>$users,'done'=>0,'added'=>0,'skipped'=>0]];
        transferReply(['job'=>$job,'total'=>count($users),'message'=>'Validated. Existing usernames will be skipped.']);
    }
    if($action==='step') {
        $id=(string)($_POST['job']??'');if(!isset($_SESSION['user_transfer'][$id])||$_SESSION['user_transfer'][$id]['router']!==$session)throw new Exception('Import job not found.');
        $job=&$_SESSION['user_transfer'][$id];
        $started=microtime(true);
        for($i=0;$i<3&&$job['done']<count($job['users']);$i++){
            if($i>0&&microtime(true)-$started>=6)break;
            $u=$job['users'][$job['done']];$existing=$call('/ip/hotspot/user/print',['?name'=>$u['name'],'.proplist'=>'name']);
            if(count($existing))$job['skipped']++;else{$call('/ip/hotspot/user/add',$u);$job['added']++;}$job['done']++;
        }
        transferReply(['done'=>$job['done'],'total'=>count($job['users']),'added'=>$job['added'],'skipped'=>$job['skipped']]);
    }
    throw new Exception('Unknown action.');
 }catch(Exception $e){transferReply(['error'=>$e->getMessage()],400);}
}
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="<?= $transferLanguage === 'bn' ? 'bn' : 'en' ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= transferHtml('User Transfer') ?></title>
<link rel="stylesheet" href="../css/mikhmon-ui.<?= $transferTheme ?>.min.css">
<link rel="stylesheet" href="../css/font-awesome/css/font-awesome.min.css">
<style>
.ngw-transfer{max-width:860px;margin:0 auto;padding:10px;box-sizing:border-box}
.ngw-transfer .transfer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.ngw-transfer .card{box-sizing:border-box;margin-bottom:10px;min-width:0;border-radius:4px;overflow:hidden}
.ngw-transfer .card-header{padding:8px 10px}.ngw-transfer .card-header h3{margin:0;font-size:15px}
.ngw-transfer .card-body{padding:10px}.ngw-transfer .btn{padding:7px 10px;font-size:13px}
.ngw-transfer .form-control{font-size:13px;padding:6px 8px}
.ngw-transfer input[type=file]{overflow:hidden;font-size:12px}
.ngw-transfer .transfer-grid .card{border-top:0}.ngw-transfer .page-bar{display:flex;align-items:center;justify-content:space-between;gap:8px}
.ngw-transfer .field{margin-bottom:10px}.ngw-transfer label{display:block;margin-bottom:4px;font-weight:600;font-size:13px}
.ngw-transfer .form-control{width:100%;box-sizing:border-box;min-height:32px}
.ngw-transfer .transfer-help{font-size:13px;line-height:1.6;opacity:.85}
.ngw-transfer .transfer-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.ngw-transfer .transfer-status{margin-top:16px;padding:8px 10px;border:1px solid currentColor;border-radius:4px;white-space:pre-wrap;overflow-wrap:anywhere}
.ngw-transfer [hidden]{display:none!important}
.ngw-transfer progress{width:100%;margin-top:12px;accent-color:currentColor}
@media(max-width:720px){.ngw-transfer .transfer-grid{grid-template-columns:1fr}.ngw-transfer{padding:10px}}
</style></head><body><main class="ngw-transfer">
<div class="card"><div class="card-header page-bar"><h3><i class="fa fa-exchange"></i> <?= transferHtml('User Transfer') ?></h3><a class="btn bg-secondary" href="../?session=<?=htmlspecialchars(rawurlencode($session),ENT_QUOTES)?>"><i class="fa fa-arrow-left"></i> <?= transferHtml('Back to router') ?></a></div></div>
<div class="transfer-grid">
<section class="card box-bordered"><div class="card-header bg-primary"><h3><i class="fa fa-download"></i> <?= transferHtml('Export users') ?></h3></div><div class="card-body">
<form id="export"><div class="field"><label for="target"><?= transferHtml('Destination OS') ?></label><select class="form-control" id="target" name="target"><option value="6">RouterOS 6</option><option value="7">RouterOS 7</option></select></div>
<div class="field"><label for="filter"><?= transferHtml('Filter users') ?></label><select class="form-control" id="filter" name="filter"><option value="all"><?= transferHtml('All Hotspot users') ?></option><option value="batch"><?= ($transferLanguage === 'bn' ? 'ব্যাচ' : 'Batch') ?></option><option value="profile"><?= transferHtml('Specific profile') ?></option></select></div>
<div class="field" id="filter-value-field" hidden><label for="filter-value"><?= transferHtml('Batch comment or profile name') ?></label><input class="form-control" id="filter-value" name="value" list="transfer-values" autocomplete="off"></div>
<button class="btn bg-primary" type="submit"><i class="fa fa-download"></i> <?= transferHtml('Download file') ?></button>
<button class="btn bg-success" type="submit" value="rsc"><i class="fa fa-terminal"></i> <?= $transferLanguage === 'bn' ? 'RSC ডাউনলোড' : 'Download RSC' ?></button></form>
</div></section>
<section class="card box-bordered"><div class="card-header bg-success"><h3><i class="fa fa-upload"></i> <?= transferHtml('Import users') ?></h3></div><div class="card-body">

<form id="import"><div class="field"><label for="transfer-file"><?= transferHtml('Transfer file') ?></label><input class="form-control" type="file" id="transfer-file" name="file" accept=".json" required></div>
<div class="field"><label for="profile"><?= transferHtml('Destination profile') ?></label><select class="form-control" name="profile" id="profile" required></select></div>

<button class="btn bg-primary" type="submit"><i class="fa fa-check"></i> <?= transferHtml('Validate file') ?></button></form>
<div class="transfer-actions"><button class="btn bg-success" id="start" hidden><?= transferHtml('Start import') ?></button><button class="btn bg-warning" id="resume" hidden><?= transferHtml('Resume import') ?></button></div>
</div></section></div><datalist id="transfer-values"></datalist>
<div class="transfer-status" role="status" aria-live="polite"><span id="status"><?= transferHtml('Loading router data…') ?></span><progress id="transfer-progress" hidden></progress></div>
<script>window.transferConfig=<?=json_encode(['csrf'=>$csrf,'text'=>$transferStrings],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;</script><script src="../js/user-transfer.js?v=2"></script>
</main></body></html>

