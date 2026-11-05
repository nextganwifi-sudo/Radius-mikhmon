<?php
// Only the read-only generation result may skip the RouterOS connection.
// A form POST can retain voucher-result=1 when submitted from that page.
function isLocalVoucherResultRequest($method, $query)
{
    return $method === 'GET'
        && isset($query['hotspot-user']) && $query['hotspot-user'] === 'generate'
        && isset($query['voucher-result']) && $query['voucher-result'] === '1'
        && empty($query['hotspot']);
}
