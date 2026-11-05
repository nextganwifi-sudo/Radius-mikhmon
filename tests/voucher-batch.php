<?php
require __DIR__ . '/../mikhmonv3-upstream/include/voucher_batch.php';
function verify($ok, $message) { if (!$ok) { throw new RuntimeException($message); } }
class BatchRouter {
    public $connected = true;
    public $users = array();
    public $adds = 0;
    public $dropNext = false;
    public $rejectNext = false;
    public function comm($command, $args = array()) {
        if ($command === '/ip/hotspot/user/profile/print') { return array(array('name'=>'daily', 'on-login'=>',rem,10,1d,12,,Disable,')); }
        if ($command === '/ip/hotspot/user/print') { return isset($this->users[$args['?name']]) ? array($this->users[$args['?name']]) : array(); }
        if ($this->rejectNext) { return array('!trap'=>array(array('message'=>'permission denied'))); }
        verify(!isset($this->users[$args['name']]), 'Duplicate add attempted');
        $this->users[$args['name']] = $args; $this->adds++;
        if ($this->dropNext) { $this->dropNext = false; $this->connected = false; return array(); }
        return '*1';
    }
}
$input = array('qty'=>200,'user'=>'vc','userl'=>8,'char'=>'mix2','profile'=>'daily','server'=>'all','prefix'=>'','timelimit'=>'','datalimit'=>'','mbgb'=>'1048576','adcomment'=>'test');
foreach (array(1,2,100,200,500) as $qty) {
    $input['qty'] = $qty;
    $job = voucherBatchCreate($input, 'router-a', bin2hex(random_bytes(16))); $api = new BatchRouter(); $steps = 0;
    while ($job['completed'] < $qty) {
        $before = $api->adds;
        voucherBatchStep($job, $api);
        verify($api->adds - $before <= 3, 'One request exceeded the chunk size');
        $steps++;
    }
    verify($api->adds === $qty && count($api->users) === $qty, 'Wrong final quantity');
    voucherBatchStep($job, $api);
    verify($api->adds === $qty, 'Completed step replay added vouchers');
    echo "PASS: $qty vouchers completed in $steps bounded steps\n";
}
$input['qty'] = 6;
$job = voucherBatchCreate($input, 'router-a', bin2hex(random_bytes(16))); $api = new BatchRouter();
$api->dropNext = true;
try { voucherBatchStep($job, $api); throw new LogicException('Lost acknowledgement was ignored'); } catch (RuntimeException $e) {}
verify($job['completed'] === 0 && $api->adds === 1, 'Lost acknowledgement fixture failed');
$api->connected = true;
voucherBatchStep($job, $api);
verify($job['completed'] === 3 && $api->adds === 3, 'Resume duplicated an accepted voucher');
// Simulate worker loss before PHP persisted the last three acknowledgements.
$job['completed'] = 0;
voucherBatchStep($job, $api);
verify($api->adds === 3 && $job['completed'] === 3, 'Recovery after lost checkpoint duplicated users');
voucherBatchStep($job, $api, 3, 0);
verify($job['completed'] === 4, 'Time budget did not stop the chunk');
$api->rejectNext = true;
try { voucherBatchStep($job, $api); throw new LogicException('Trap ignored'); } catch (InvalidArgumentException $e) {}
verify($job['completed'] === 4, 'Rejected add was counted');
$api->rejectNext = false;
$next = $job['users'][4]['name'];
$api->users[$next] = array('name'=>$next,'profile'=>'daily','comment'=>'another-batch');
try { voucherBatchStep($job, $api); throw new LogicException('Collision ignored'); } catch (InvalidArgumentException $e) {}
verify($job['completed'] === 4, 'Foreign voucher counted as ours');
foreach (array(0,501,-1,'invalid') as $qty) {
    $input['qty'] = $qty;
    try { voucherBatchCreate($input, 'router-a', str_repeat('a',32)); throw new LogicException('Invalid quantity accepted'); } catch (InvalidArgumentException $e) {}
}
echo "PASS: lost acknowledgements, lost checkpoints, time budget, router rejection, collisions and validation\n";
