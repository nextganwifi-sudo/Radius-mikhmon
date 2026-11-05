<?php
require __DIR__ . '/../mikhmonv3-upstream/include/user_transfer.php';
$row = array('name'=>'abc','password'=>'secret','profile'=>'source','comment'=>"date,#&' বাংলা\n",
'mac-address'=>'AA:BB:CC:DD:EE:FF','uptime'=>'3h','bytes-in'=>'100','limit-uptime'=>'1s','disabled'=>'yes','email'=>'x');
$out=transferUser($row,'destination');
if(array_keys($out)!==array('name','password','profile','comment','mac-address'))throw new Exception('Unexpected fields');
if($out['comment']!==$row['comment']||$out['password']!==$row['password']||$out['profile']!=='destination')throw new Exception('Changed data');
unset($row['mac-address']);if(isset(transferUser($row,'destination')['mac-address']))throw new Exception('Invented MAC');
echo "PASS: only username/password/profile/comment/optional MAC; usage and limits ignored\n";
