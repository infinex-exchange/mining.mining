<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use Infinex\Database\Sorting;
use function Infinex\Validation\validateId;
use function Infinex\Math\trimFloat;
use React\Promise;

class PlanAssets {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized plan assets manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getPlanAssets',
            [$this, 'getPlanAssets']
        );
        
        $promises[] = $this -> amqp -> method(
            'getPlanAsset',
            [$this, 'getPlanAsset']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started plan assets manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start plan assets manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getPlanAssets');
        $promises[] = $this -> amqp -> unreg('getPlanAsset');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped plan assets manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop plan assets manager: '.((string) $e));
            }
        );
    }
    
    public function getPlanAssets($body) {
        if(isset($body['planid']) && !validateId($body['planid']))
            throw new Error('VALIDATION_ERROR', 'planid');
            
        $pag = new Pagination\Offset(50, 500, $body);
        
        $sort = new Sorting(
            [
                'planid' => 'planid',
                'priority' => 'priority'
            ],
            'planid', 'ASC',
            $body
        );
            
        $task = [];
        
        $sql = 'SELECT planid,
                       assetid,
                       priority,
                       unit_avg_revenue,
                       asset_price_avg
                FROM plan_assets
                WHERE 1=1';
        
        if(isset($body['planid'])) {
            $task[':planid'] = $body['planid'];
            $sql .= ' AND planid = :planid';
        }
            
        $sql .= $sort -> sql()
             .  $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $assets = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $assets[] = $this -> rtrAsset($row);
        }
            
        return [
            'assets' => $assets,
            'more' => $pag -> more
        ];
    }
    
    public function getPlanAsset($body) {
        if(!isset($body['planid']))
            throw new Error('MISSING_DATA', 'planid');
        if(!isset($body['assetid']))
            throw new Error('MISSING_DATA', 'assetid');
        
        if(!validateId($body['planid']))
            throw new Error('VALIDATION_ERROR', 'planid');
        if(!is_string($body['assetid']))
            throw new Error('VALIDATION_ERROR', 'assetid');
        
        $task = [
            ':planid' => $body['planid'],
            ':assetid' => $body['assetid']
        ];
        
        $sql = 'SELECT planid,
                       assetid,
                       priority,
                       unit_avg_revenue,
                       asset_price_avg
                FROM plan_assets
                WHERE planid = :planid
                AND assetid = :assetid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Asset '.$body['assetid'].' not found in plan '.$body['planid']);
            
        return $this -> rtrAsset($row);
    }
    
    private function rtrAsset($row) {
        return [
            'planid' => $row['planid'],
            'assetid' => $row['assetid'],
            'priority' => $row['priority'],
            'avgUnitRevenue' => trimFloat($row['unit_avg_revenue']),
            'avgPrice' => trimFloat($row['asset_price_avg'])
        ];
    }
}

?>