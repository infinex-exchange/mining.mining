<?php

use Infinex\Exceptions\Error;
use React\Promise;

class PlansAPI {
    private $log;
    private $amqp;
    private $plans;
    private $assets;
    
    function __construct($log, $amqp, $plans, $assets) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> plans = $plans;
        $this -> assets = $assets;
        
        $this -> log -> debug('Initialized plans API');
    }
    
    public function initRoutes($rc) {
        $rc -> get('/plans', [$this, 'getAllPlans']);
        $rc -> get('/plans/{planid}', [$this, 'getPlan']);
    }
    
    public function getAllPlans($path, $query, $body, $auth) {
        $th = $this;
        
        $resp = $this -> plans -> getPlans([
            'enabled' => true,
            'offset' => @$query['offset'],
            'limit' => @$query['limit']
        ]);
        
        $ba = $this -> plans -> getBillingAsset();
        $resp['billingAsset'] = $ba['symbol'];
        $resp['billingPrec'] = $ba['defaultPrec'];
        
        $promises = [];
        $mapAssets = [];
        
        foreach($resp['plans'] as $k => $v) {
            $planAssets = $this -> assets -> getPlanAssets([
                'planid' => $v['planid'],
                'orderBy' => 'priority',
                'orderDir' => 'ASC',
                'limit' => 500
            ]);
            
            $resp['plans'][$k]['assets'] = $planAssets['assets'];
            
            foreach($planAssets['assets'] as $ak => $av) {
                $assetid = $av['assetid'];
                
                if(!array_key_exists($assetid, $mapAssets)) {
                    $mapAssets[$assetid] = null;
                    
                    $promises[] = $this -> amqp -> call(
                        'wallet.wallet',
                        'getAsset',
                        [ 'assetid' => $assetid ]
                    ) -> then(function($asset) use(&$mapAssets, $assetid) {
                        $mapAssets[$assetid] = $asset;
                    });
                }
            }
        }
        
        return Promise\all($promises) -> then(function() use(&$mapAssets, $resp, $th) {
            foreach($resp['plans'] as $k => $v) {
                $assets = [];
                foreach($v['assets'] as $ak => $av)
                    $assets[] = $th -> ptpAsset($av, $mapAssets[$av['assetid']]);
                
                $resp['plans'][$k] = $th -> ptpPlan($v, $assets);
            }
            
            return $resp;
        });
    }
    
    public function getPlan($path, $query, $body, $auth) {
        $th = $this;
        
        $resp = $this -> plans -> getPlan([
            'planid' => $path['planid']
        ]);
        
        $planAssets = $this -> assets -> getPlanAssets([
            'planid' => $resp['planid'],
            'orderBy' => 'priority',
            'orderDir' => 'ASC',
            'limit' => 500
        ]);
        
        $promises = [];
        $mapAssets = [];
        
        foreach($planAssets['assets'] as $ak => $av) {
            $assetid = $av['assetid'];
            
            if(!array_key_exists($assetid, $mapAssets)) {
                $mapAssets[$assetid] = null;
                
                $promises[] = $this -> amqp -> call(
                    'wallet.wallet',
                    'getAsset',
                    [ 'assetid' => $assetid ]
                ) -> then(function($asset) use(&$mapAssets, $assetid) {
                    $mapAssets[$assetid] = $asset;
                });
            }
        }
        
        return Promise\all($promises) -> then(function() use(&$mapAssets, $resp, $th, $planAssets) {
            $assets = [];
            foreach($planAssets['assets'] as $ak => $av)
                $assets[] = $th -> ptpAsset($av, $mapAssets[$av['assetid']]);
                
            $plan = $th -> ptpPlan($resp, $assets);
            
            $ba = $this -> plans -> getBillingAsset();
            $plan['billingAsset'] = $ba['symbol'];
            $plan['billingPrec'] = $ba['defaultPrec'];
            return $plan;
        });
    }
    
    private function ptpAsset($record, $asset) {
        return [
            'symbol' => $asset['symbol'],
            'name' => $asset['name'],
            'iconUrl' => $asset['iconUrl'],
            'prec' => $asset['defaultPrec'],
            'avgUnitRevenue' => $record['avgUnitRevenue'],
            'avgPrice' => $record['avgPrice']
        ];
    }
    
    private function ptpPlan($record, $assets) {
        return [
            'planid' => $record['planid'],
            'name' => $record['name'],
            'months' => $record['months'],
            'unitName' => $record['unitName'],
            'avblUnits' => $record['avblUnits'],
            'orderMinUnits' => $record['orderMinUnits'],
            'unitPrice' => $record['unitPrice'],
            'discountPercEvery' => $record['discountPercEvery'],
            'discountMax' => $record['discountMax'],
            'assets' => $assets
        ];
    }
}

?>