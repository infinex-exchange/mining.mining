<?php

require __DIR__.'/Plans.php';
require __DIR__.'/PlanAssets.php';
require __DIR__.'/Contracts.php';

require __DIR__.'/API/PlansAPI.php';

use React\Promise;

class App extends Infinex\App\App {
    private $pdo;
    
    private $plans;
    private $assets;
    private $contracts;
    
    private $plansApi;
    private $rest;
    
    function __construct() {
        parent::__construct('mining.mining');
        
        $this -> pdo = new Infinex\Database\PDO(
            $this -> loop,
            $this -> log,
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME
        );
        
        $this -> plans = new Plans(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> assets = new PlanAssets(
            $this -> log,
            $this -> amqp,
            $this -> pdo
        );
        
        $this -> contracts = new Contracts(
            $this -> loop,
            $this -> log,
            $this -> amqp,
            $this -> pdo,
            $this -> plans,
            BILLING_ASSETID
        );
        
        $this -> plansApi = new PlansAPI(
            $this -> log,
            $this -> amqp,
            $this -> plans,
            $this -> assets,
            BILLING_ASSETID
        );
        
        $this -> rest = new Infinex\API\REST(
            $this -> log,
            $this -> amqp,
            [
                $this -> plansApi
            ]
        );
    }
    
    public function start() {
        $th = $this;
        
        parent::start() -> then(
            function() use($th) {
                return $th -> pdo -> start();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> plans -> start(),
                    $th -> assets -> start()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> contracts -> start();
            }
        ) -> then(
            function() use($th) {
                return $th -> rest -> start();
            }
        ) -> catch(
            function($e) use($th) {
                $th -> log -> error('Failed start app: '.((string) $e));
            }
        );
    }
    
    public function stop() {
        $th = $this;
        
        $th -> rest -> stop() -> then(
            function() use($th) {
                return $th -> contracts -> stop();
            }
        ) -> then(
            function() use($th) {
                return Promise\all([
                    $th -> plans -> stop(),
                    $th -> assets -> stop()
                ]);
            }
        ) -> then(
            function() use($th) {
                return $th -> pdo -> stop();
            }
        ) -> then(
            function() use($th) {
                $th -> parentStop();
            }
        );
    }
    
    private function parentStop() {
        parent::stop();
    }
}

?>