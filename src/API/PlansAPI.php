<?php

use Infinex\Exceptions\Error;

class PlansAPI {
    private $log;
    private $amqp;
    private $plans;
    private $assets;
    private $paymentAssetid;
    
    function __construct($log, $amqp, $plans, $assets, $paymentAssetid) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> plans = $plans;
        $this -> assets = $assets;
        $this -> paymentAssetid = $paymentAssetid;
        
        $this -> log -> debug('Initialized plans API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/plans', [$this, 'getAllPlans']);
        $rc -> get('/plans/{planid}', [$this, 'getPlan']);
    }
    
    public function getAllPlans($path, $query, $body, $auth) {
        $resp = $this -> plans -> getPlans([
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$query['limit']
        ]);
        
        foreach($resp['plans'] as $k => $v)
            $resp['plans'][$k] = $this -> ptpPlan($v);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAsset',
            [ 'assetid' => $this -> paymentAssetid ]
        ) -> then(function($asset) use($resp) {
            $resp['paymentAsset'] = $asset['symbol'];
            return $resp;
        });
    }
    
    public function getPlan($path, $query, $body, $auth) {
        $voting = $this -> plans -> getPlan([
            'planid' => $path['planid']
        ]);
        
        $resp = $this -> ptpPlan($voting);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'getAsset',
            [ 'assetid' => $this -> paymentAssetid ]
        ) -> then(function($asset) use($resp) {
            $resp['paymentAsset'] = $asset['symbol'];
            return $resp;
        });
    }
    
    private function ptpAsset($record, $asset) {
        return [
            'symbol' => $asset['symbol'],
            'name' => $asset['name'],
            'iconUrl' => $asset['iconUrl'],
            'avgUnitRevenue' => $record['avgUnitRevenue'],
            'avgPrice' => $record['avgPrice']
        ];
    }
    
    private function ptpPlan($record) {
        return [
            'planid' => $record['planid'],
            'name' => $record['name'],
            'months' => $record['months'],
            'unitName' => $record['unitName'],
            'avblUnits' => $record['totalUnits'] - $record['soldUnits'],
            'orderMinUnits' => $record['orderMinUnits'],
            'unitPrice' => $record['unitPrice'],
            'discountPercEvery' => $record['discountPercEvery'],
            'discountMax' => $record['discountMax'],
            'assets' => $assets
        ];
    }
}

?>