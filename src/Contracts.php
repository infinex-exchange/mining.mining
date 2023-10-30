<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use function Infinex\Math\trimFloat;
use React\Promise;
use Decimal\Decimal;

class Contracts {
    private $loop;
    private $log;
    private $amqp;
    private $pdo;
    private $plans;
    
    private $timerFinalizeContracts;
    private $billingAsset;
    
    function __construct($loop, $log, $amqp, $pdo, $plans) {
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        $this -> plans = $plans;
        
        $this -> log -> debug('Initialized contracts manager');
    }
    
    public function start() {
        $th = $this;
        
        $this -> billingAsset = $this -> plans -> getBillingAsset();
        
        $this -> timerFinalizeContracts = $this -> loop -> addPeriodicTimer(
            300,
            function() use($th) {
                $th -> finalizeContracts();
            }
        );
        $this -> finalizeContracts();
        
        $promises = [];
        
        $promises[] = $this -> amqp -> method(
            'getContracts',
            [$this, 'getContracts']
        );
        
        $promises[] = $this -> amqp -> method(
            'getContract',
            [$this, 'getContract']
        );
        
        return Promise\all($promises) -> then(
            function() use($th) {
                $th -> log -> info('Started contracts manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to start contracts manager: '.((string) $e));
                throw $e;
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $this -> loop -> cancelTimer($this -> timerFinalizeContracts);
        
        $promises = [];
        
        $promises[] = $this -> amqp -> unreg('getContracts');
        $promises[] = $this -> amqp -> unreg('getContract');
        
        return Promise\all($promises) -> then(
            function() use ($th) {
                $th -> log -> info('Stopped contracts manager');
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed to stop plans manager: '.((string) $e));
            }
        );
    }
    
    public function getContracts($body) {
        if(isset($body['uid']) && !validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(isset($body['planid']) && !validateId($body['planid']))
            throw new Error('VALIDATION_ERROR', 'planid');
        if(isset($body['active']) && !is_bool($body['active']))
            throw new Error('VALIDATION_ERROR', 'active');
            
        $pag = new Pagination\Offset(50, 500, $body);
            
        $task = [];
        
        $sql = 'SELECT contractid,
                       planid,
                       uid,
                       units,
                       price_paid,
                       payment_lockid,
                	   EXTRACT(epoch FROM begin_time) AS begin_time,
                	   EXTRACT(epoch FROM end_time) AS end_time,
                       active
                FROM contracts
                WHERE 1=1';
        
        if(isset($body['uid'])) {
            $task[':uid'] = $body['uid'];
            $sql .= ' AND uid = :uid';
        }
        
        if(isset($body['planid'])) {
            $task[':planid'] = $body['planid'];
            $sql .= ' AND planid = :planid';
        }
        
        if(isset($body['active'])) {
            $task[':active'] = $body['active'] ? 1 : 0;
            $sql .= ' AND active = :active';
        }
            
        $sql .= $pag -> sql();
            
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
            
        $contracts = [];
        
        while($row = $q -> fetch()) {
            if($pag -> iter()) break;
            $contracts[] = $this -> rtrContract($row);
        }
            
        return [
            'contracts' => $contracts,
            'more' => $pag -> more
        ];
    }
    
    public function getContract($body) {
        if(!isset($body['contractid']))
            throw new Error('MISSING_DATA', 'contractid', 400);
        
        if(!validateId($body['contractid']))
            throw new Error('VALIDATION_ERROR', 'contractid', 400);
        
        $task = [
            ':contractid' => $body['contractid']
        ];
        
        $sql = 'SELECT contractid,
                       planid,
                       uid,
                       units,
                       price_paid,
                       payment_lockid,
                	   EXTRACT(epoch FROM begin_time) AS begin_time,
                	   EXTRACT(epoch FROM end_time) AS end_time,
                       active
                FROM contracts
                WHERE contractid = :contractid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        if(!$row)
            throw new Error('NOT_FOUND', 'Contract '.$body['contractid'].' not found', 404);
            
        return $this -> rtrContract($row);
    }
    
    public function createContract($body) {
        if(!isset($body['planid']))
            throw new Error('MISSING_DATA', 'planid', 400);
        if(!isset($body['uid']))
            throw new Error('MISSING_DATA', 'uid');
        if(!isset($body['units']))
            throw new Error('MISSING_DATA', 'units');
        if(!isset($body['pricePaid']))
            throw new Error('MISSING_DATA', 'pricePaid');
        
        if(!validateId($body['planid']))
            throw new Error('VALIDATION_ERROR', 'planid', 400);
        if(!validateId($body['uid']))
            throw new Error('VALIDATION_ERROR', 'uid');
        if(!is_int($body['units']) || $body['units'] < 1)
            throw new Error('VALIDATION_ERROR', 'units', 400);
        if(!validateFloat($body['pricePaid']))
            throw new Error('VALIDATION_ERROR', 'pricePaid');
        
        if(isset($body['paymentLockid']) && !validateId($body['paymentLockid']))
            throw new Error('VALIDATION_ERROR', 'paymentLockid');
        
        $this -> pdo -> beginTransaction();
        
        $task = [
            ':planid' => $body['planid']
        ];
        
        $sql = 'SELECT total_units,
                       sold_units,
                       months
                FROM mining_plans
                WHERE planid = :planid
                FOR UPDATE';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $plan = $q -> fetch();
        
        if(!$plan) {
            $this -> pdo -> rollBack();
            throw new Error('NOT_FOUND', 'Plan '.$body['planid'].' not found');
        }
        
        $avblUnits = $plan['total_units'] - $plan['sold_units'];
        if($body['units'] > $avblUnits) {
            $this -> pdo -> rollBack();
            throw new Error('TOO_MANY_UNITS', 'Specified amount of power units is not available', 416);
        }
        
        $task = [
            ':planid' => $body['planid'],
            ':units' => $body['units']
        ];
        
        $sql = 'UPDATE mining_plans
                SET sold_units = sold_units + :units
                WHERE planid = :planid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        
        $task = [
            ':planid' => $body['planid'],
            ':uid' => $body['uid'],
            ':units' => $body['units'],
            ':price_paid' => $body['pricePaid'],
            ':payment_lockid' => @$body['paymentLockid'],
            ':interval' => $plan['months'].' months'
        ];
        
        $sql = 'INSERT INTO contracts(
                    planid,
                    uid,
                    units,
                    price_paid,
                    payment_lockid,
                    begin_time,
                    end_time,
                    active
                ) VALUES (
                    :planid,
                    :uid,
                    :price_paid,
                    :payment_lockid,
                    NOW(),
                    NOW() + INTERVAL :interval,
                    TRUE
                )
                RETURNING contractid';
        
        $q = $this -> pdo -> prepare($sql);
        $q -> execute($task);
        $row = $q -> fetch();
        
        $this -> pdo -> commit();
        
        return [
            'contractid' => $row['contractid']
        ];
    }
    
    public function buyContract($body) {
        $th = $this;
        
        if(!isset($body['units']))
            throw new Error('MISSING_DATA', 'units');
            
        if(!is_int($body['units']) || $body['units'] < 1)
            throw new Error('VALIDATION_ERROR', 'units', 400);
        
        $plan = $this -> getPlan([
            'planid' => @$body['planid']
        ]);
        
        if(!$plan['enabled'])
            throw new Error('FORBIDDEN', 'Plan '.$body['planid'].' is out of service');
        
        if($body['units'] < $plan['orderMinUnits'])
            throw new Error('TOO_FEW_UNITS', 'Specified amount of power units is less than minimal order amount', 416);
        
        if($body['units'] > $plan['avblUnits'])
            throw new Error('TOO_MANY_UNITS', 'Specified amount of power units is not available', 416);
        
        $dPrice = new Decimal($plan['unitPrice']);
        $dPrice *= $body['units'];
        
        if($plan['discountPercEvery']) {
            $dDiscountTotalPerc = new Decimal($body['units']);
            $dDiscountTotalPerc /= $plan['discountPercEvery']);
            $dDiscountTotalPerc = $dDiscountTotalPerc -> floor();
            
            if($plan['discountMax'] && $dDiscountTotalPerc > $plan['discountMax'])
                $dDiscountTotalPerc = new Decimal($plan['discountMax']);
            
            $dDiscountFactor = new Decimal(100);
            $dDiscountFactor -= $dDiscountTotalPerc;
            
            $dPrice = $dPrice * $dDiscountFactor / 100;
            $dPrice = $dPrice -> round($this -> billingAsset['defaultPrec']);
        }
        
        $strPrice = trimFloat($dPrice -> toFixed($this -> billingAsset['defaultPrec']);
        
        return $this -> amqp -> call(
            'wallet.wallet',
            'lock',
            [
                'uid' => @$body['uid'],
                'assetid' => $this -> billingAsset['assetid'],
                'amount' => $strPrice,
                'reason' => 'MINING_BUY_CONTRACT'
            ]
        ) -> then(function($lock) use($th, $body, $strPrice) {
            try {
                $resp = $th -> createContract([
                    'planid' => $body['planid'],
                    'uid' => $body['uid'],
                    'units' => $body['units'],
                    'pricePaid' => $strPrice,
                    'paymentLockid' => $lock['lockid']
                ]);
                
                $th -> amqp -> call(
                    'wallet.wallet',
                    'commit',
                    [ 'lockid' => $lock['lockid'] ]
                );
                
                return $resp;
            }
            catch(Error $e) {
                $th -> amqp -> call(
                    'wallet.wallet',
                    'release',
                    [ 'lockid' => $lock['lockid'] ]
                );
                
                throw $e;
            }
        });
    }
    
    private function rtrContract($row) {
        return [
            'contractid' => $row['contractid'],
            'planid' => $row['planid'],
            'uid' => $row['uid'],
            'units' => $row['units'],
            'pricePaid' => trimFloat($row['price_paid']),
            'paymentLockid' => $row['payment_lockid'],
            'beginTime' => intval($row['begin_time']),
            'endTime' => intval($row['end_time']),
            'active' => $row['active']
        ];
    }
    
    private function finalizeContracts() {
        $this -> log -> debug('Finalizing expired contracts');
        
        $this -> pdo -> beginTransaction();
        
        $q = $this -> pdo -> query(
            'UPDATE contracts
             SET active = FALSE
             WHERE end_time < NOW()
             AND active = TRUE
             RETURNING contractid, planid, units'
        );
        
        while($row = $q -> fetch()) {
            $task = [
                ':planid' => $row['planid'],
                ':units' => $row['units']
            ];
            
            $sql = 'UPDATE mining_plans
                    SET sold_units = sold_units - :units
                    WHERE planid = :planid';
            
            $q2 = $this -> pdo -> prepare($sql);
            $q2 -> execute($task);
            
            $this -> log -> info(
                'Finalized contract '.$row['contractid'].'. Released '.
                $row['units'].' units to plan '.$row['planid']
            );
        }
        
        $this -> pdo -> commit();
    }
}

?>