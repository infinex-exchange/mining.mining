<?php

use Infinex\Exceptions\Error;
use React\Promise;

class PlansAPI {
    private $log;
    private $amqp;
    private $plans;
    private $assets;
    private $paymentAssetid;
    
    function __construct($log, $amqp, $plans, $assets, $paymentAssetid, $referenceAssetid) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> plans = $plans;
        $this -> assets = $assets;
        $this -> paymentAssetid = $paymentAssetid;
        $this -> referenceAssetid = $referenceAssetid;
        
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
        
        foreach([$this -> paymentAssetid, $this -> referenceAssetid] as $assetid)
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
        
        return Promise\all($promises) -> then(function() use(&$mapAssets, $resp, $th) {
            foreach($resp['plans'] as $k => $v) {
                $assets = [];
                foreach($v['assets'] as $ak => $av)
                    $assets[] = $th -> ptpAsset($av, $mapAssets[$av['assetid']]);
                
                $resp['plans'][$k] = $th -> ptpPlan($v, $assets);
            }
            
            $resp['paymentAsset'] = $th -> ptpAsset($mapAssets[$th -> paymentAssetid]);
            $resp['refAsset'] = $th -> ptpAsset($mapAssets[$th -> referenceAssetid]);
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
        
        foreach([$this -> paymentAssetid, $this -> referenceAssetid] as $assetid)
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
        
        return Promise\all($promises) -> then(function() use(&$mapAssets, $resp, $th, $planAssets) {
            $assets = [];
            foreach($planAssets['assets'] as $ak => $av)
                $assets[] = $th -> ptpAsset($av, $mapAssets[$av['assetid']]);
                
            $plan = $th -> ptpPlan($resp, $assets);
            
            $plan['paymentAsset'] = $th -> ptpAsset($mapAssets[$th -> paymentAssetid]);
            $plan['refAsset'] = $th -> ptpAsset($mapAssets[$th -> referenceAssetid]);
            return $plan;
        });
    }
    
    private function ptpAsset($asset) {
        return [
            'symbol' => $asset['symbol'],
            'name' => $asset['name'],
            'iconUrl' => $asset['iconUrl'],
            'defaultPrec' => $asset['defaultPrec']
        ];
    }
    
    private function ptpPlanAsset($record, $asset) {
        return array_merge(
            $this -> ptpAsset($asset),
            [
                'avgUnitRevenue' => $record['avgUnitRevenue'],
                'avgPrice' => $record['avgPrice']
            ]
        );
    }
    
    private function ptpPlan($record, $assets) {
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