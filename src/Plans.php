<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use function Infinex\Math\trimFloat;
use React\Promise;

class Plans {
    private $log;
    private $amqp;
    private $pdo;
    
    function __construct($log, $amqp, $pdo) {
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized plans manager');
    }
    
    public function start() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getPlans',
            [$this, 'getPlans']
        );
        
        $promises[] = $this -> amqp -> method(
            'getPlan',
            [$this, 'getPlan']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started plans manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start plans manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getPlans');
        $promises[] = $this -> amqp -> unreg('getPlan');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped plans manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop plans manager: '.((string) $e));
            }
        );
    }
    
    public function getPlans($body) {
        if(isset($body['enabled']) && !is_bool($body['enabled']))
            throw new Error('VALIDATION_ERROR', 'enabled');
            
        $pag = new Pagination\Offset(50, 500, $body);
            
        $task = [];
        
        $sql = 'SELECT planid,
                       name,
                       months,
                       unit_name,
                       total_units,
                       sold_units,
                       min_ord_units,
                       unit_price,
                       discount_perc_every,
                       discount_max,
                       enabled
                FROM mining_plans
                WHERE 1=1';
        
        if(isset($body['enabled'])) {
            $task[':enabled'] = $body['enabled'] ? 1 : 0;
            $sql .= ' AND enabled = :enabled';
        }
            
        $sql .= $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $plans = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $plans[] = $this -> rtrPlan($row);
        }
            
        return [
            'plans' => $plans,
            'more' => $pag -> more
        ];
    }
    
    public function getPlan($body) {
        if(!isset($body['planid']))
            throw new Error('MISSING_DATA', 'planid', 400);
        
        if(!validateId($body['planid']))
            throw new Error('VALIDATION_ERROR', 'planid', 400);
        
        $task = [
            ':planid' => $body['planid']
        ];
        
        $sql = 'SELECT planid,
                       name,
                       months,
                       unit_name,
                       total_units,
                       sold_units,
                       min_ord_units,
                       unit_price,
                       discount_perc_every,
                       discount_max,
                       enabled
                FROM mining_plans
                WHERE planid = :planid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Plan '.$body['planid'].' not found', 404);
            
        return $this -> rtrPlan($row);
    }
    
    private function rtrPlan($row) {
        return [
            'planid' => $row['planid'],
            'name' => $row['name'],
            'months' => $row['months'],
            'unitName' => $row['unit_name'],
            'totalUnits' => $row['total_units'],
            'soldUnits' => $row['sold_units'],
            'orderMinUnits' => $row['min_ord_units'],
            'unitPrice' => trimFloat($row['unit_price']),
            'discountPercEvery' => $row['discount_perc_every'],
            'discountMax' => $row['discount_max'],
            'enabled' => $row['enabled']
        ];
    }
}

?>