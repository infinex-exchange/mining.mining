<?php

use Infinex\Exceptions\Error;
use Infinex\Pagination;
use function Infinex\Validation\validateId;
use function Infinex\Math\trimFloat;
use React\Promise;

class Contracts {
    private $loop;
    private $log;
    private $amqp;
    private $pdo;
    
    private $timerFinalizeContracts;
    
    function __construct($loop, $log, $amqp, $pdo) {
        $this -> loop = $loop;
        $this -> log = $log;
        $this -> amqp = $amqp;
        $this -> pdo = $pdo;
        
        $this -> log -> debug('Initialized contracts manager');
    }
    
    public function start() {
        $th = $this;
        
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
             RETURNING contractid, planid, units';
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