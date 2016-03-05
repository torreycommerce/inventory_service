<?php
error_reporting(E_ALL);
ini_set('display_errors',1);

//  AcendaWorker\Base handles: gearman connection - loads of configs - init of logger
require_once __DIR__."/../../core/Base.php";
require_once(__DIR__ . "/classes/InventoryService.php");
require_once __DIR__ . "/vendors/autoload.php";

class WorkerInventoryService extends AcendaWorker\Base {
    private $InventoryService;

    public function __construct() {
        parent::__construct(__DIR__);
    }

    public function InventoryService($job) {
        $this->InventoryService = new InventoryService(array_merge_recursive($this->configs->service, $this->configs->job), $this->logger, $this->getCouchBase());
        $this->InventoryService->process();
    }
}


$workerInventoryService  = new WorkerInventoryService();
$workerInventoryService->worker->addFunction('inventory',
                                            [$workerInventoryService, 'InventoryService']);
$workerInventoryService->worker->work();
