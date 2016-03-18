<?php
require_once(__DIR__ . "/Couchbase/LastRunTime.php");
require_once(__DIR__ . "/Template.php");
use phpseclib\Net\SFTP;

class InventoryService {
    private $logger;
    private $configs;
    private $subscriber;
    private $subscription;
    private $acenda;
    private $errors = [];

    public $service_id;
    public $store_id;

    public function __construct($configs, $logger, $couchbaseCluster) {
        $this->configs = $configs;
        echo "Inventory".date("Y-m-d H:i:s")." - {$this->configs['acenda']['store']['name']}\n";
        $this->logger = $logger;
        $this->service_id = $this->configs['acenda']['service']['id'];
        $this->store_id = $this->configs['acenda']['store']['id'];
        $this->store_name = $this->configs['acenda']['store']['name'];
        $this->acenda = new Acenda\Client(  $this->configs['acenda']['credentials']['client_id'],
            $this->configs['acenda']['credentials']['client_secret'],
            $this->store_name
            );

        $this->lastRunTime = new lastRunTime(   $this->store_id,
            $couchbaseCluster
            );
        $this->subscription = $this->configs["acenda"]["subscription"];
        if(empty($this->configs["acenda"]["subscription"])) {
            echo "We weren't able to retrieve the subscriber's config.\n";
            die();
        }
    }

    public function process() {
        echo "Processing\n";
        $last_time_ran = $this->lastRunTime->getDatetime('lastTime');
        echo "LastRunTime: ".date("Y-m-d H:i:s",$last_time_ran->getTimestamp())."\n";
        $tmp_query = (!empty($this->subscription['credentials']['query'])) ? json_decode($this->subscription['credentials']['query'], true) : [];
        $tmp_query["date_created"]["\$gt"] = date("Y-m-d H:i:s",$last_time_ran->getTimestamp());
        echo "Query:\n";
        var_dump($tmp_query);
        echo "\n";

        echo "Config:\n";
        var_dump($this->configs);
        echo "\n";
        $prefix = $this->configs['acenda']['subscription']['credentials']['file_prefix'];
        $files = $this->getFileList();
         var_dump($files);
        if(is_array($files)) {
            foreach($files as $file) {
                if($prefix && substr($file,0,strlen($prefix))!=$prefix) continue;
                if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!== 'csv') continue;
                echo "getting ". $file . "\n";
                $this->getFile($file);               
            }
        }
        $this->handleErrors();

        echo "End LastRunTime: ".date("Y-m-d H:i:s", LastRunTime::getCurrentTimestamp())."\n\n";
        $this->lastRunTime->setDatetime('lastTime', LastRunTime::getCurrentTimestamp());
    }

    private function generateUrl(){
        $url = "";
        switch(isset($_SERVER['ACENDA_MODE']) ? $_SERVER['ACENDA_MODE'] : null){
            case "acendavm":
            $url = "http://admin.acendev";
            break;
            case "development":
            $url = "https://admin.acenda.devserver";
            break;
            default:
            $url = "https://admin.acenda.com";
            break;
        }
        return $url."/preview/".md5($this->configs['acenda']['store']['name'])."/api/import/upload?access_token=".Acenda\Authentication::getToken();
    }
    /*
     * Disable Missing Variants
     */
    private function disableMissing($skus) {
        $response=$this->acenda->get('variant',['query'=>['sku'=>['nin'=>$skus]]]);
        $offlineSkus = [];
        if(isset($response->body->result) && is_array($response->body->result)) {
            foreach($response->body->result as $variant) {
                if($variant->status!='disabled') {
                    echo "disabling ".$variant->id."\n";
                    $variant->status='offline';
                    $offlineSkus[] = $variant->sku;
                    $this->acenda->put('variant/'.$variant->id,(array)$variant);
                }       
            }
        }
        return $offlineSkus;
    }
    private function processFile(){
        echo "processing file {$this->path}\n";
        $fp = fopen($this->path,'r');
//    $fieldNames=fgetcsv($fp); 
        $fieldNames = ['sku','current_price','compare_price','quantity'];
        $totalSetOffline = 0;
        $totalSetActive = 0;
        $skus = [];
        $offlineSkus = [];        
        $numRows = 0;
        // rename file to processed
        if(!$this->renameFile($this->filename,$this->filename.'.processed')) {
            echo "could not rename file! ($this->filename)\n";
	}

        while($data=fgetcsv($fp)) {
            $numRows++;

            $row = array_combine(array_intersect_key($fieldNames, $data), array_intersect_key($data, $fieldNames));         
            echo "row ".$numRows."\n";
            if(isset($row['sku'])) {
                $skus[] = $row['sku'];
                $response = $this->acenda->get('variant',['query'=>['sku'=>$row['sku']]]);
                
                if(isset($response->body->result) && is_array($response->body->result) && count($response->body->result)) {
                    foreach($response->body->result as $variant)
                    {
                        $updatedVariant = $variant;                    
                        if(is_numeric($row['current_price'])) {
                            $updatedVariant->price = $row['current_price'];
                        }
                        if(is_numeric($row['compare_price'])) {
                            $updatedVariant->compare_price = $row['compare_price'];
                        }
                        if( is_numeric($row['quantity'])) {
                            $updatedVariant->inventory_quantity = $row['quantity'];
                            if( $updatedVariant->status!= 'disabled' && ($updatedVariant->inventory_quantity < $updatedVariant->inventory_minimum_quantity)) {
                                $updatedVariant->status='offline';
                                $totalSetOffline++;
                            }
                            if( $updatedVariant->status!= 'disabled' && ($updatedVariant->inventory_quantity >= $updatedVariant->inventory_minimum_quantity)) {
                                $updatedVariant->status='active';
                                $totalSetActive++;
                            }                         
                        } 
                        $this->acenda->put('variant/'.$variant->id,(array)$updatedVariant);                                           
                    }
                }
            }


        }
        if(@$this->configs['acenda']['subscription']['credentials']['disable_missing']) {
            $offlineSkus=$this->disableMissing($skus);
            $totalSetOffline+=count($offlineSkus);
        }    

        // Send the email    
        $template = new Template();
        $headers = "From: no-reply@acenda.com\r\n";
        $headers .= "Reply-To: no-reply@acenda.com\r\n";
        $headers .= "Return-Path: no-reply@acenda.com\r\n";
        mail($this->configs['acenda']['subscription']['credentials']['email_to'],
            'Processed Inventory Feed File - '.$this->filename,
             $template->render($this->configs['acenda']['subscription']['credentials']['email_template'], ['filename'=>$this->filename,'totalSetOffline'=>$totalSetOffline,'totalSetActive'=>$totalSetActive,'numRows'=>$numRows,'skus'=>$skus,'negativeSkus'=>$offlineSkus])
             ,$headers);

        // write an event
        $eventArr= ['subject_type' => 'import', 'subject_id' =>$this->filename, 'message' => "Inventory Feed processed" ,
                 'verb' => 'success'];
        $this->acenda->post('event',$eventArr);

        fclose($fp);
    }

// This function check the file and rewrite the file in local under UNIX code
    private function CSVFileCheck($path_to_file){
        ini_set("auto_detect_line_endings", true);
        $this->filename = basename($path_to_file);
        $this->path = "/tmp/".uniqid().".csv";
        $fd_read = fopen($path_to_file, "r");
        $fd_write = fopen($this->path, "w");

        while($line = fgetcsv($fd_read)){
            fputcsv($fd_write, $line);
        }

        fclose($fd_read);
        fclose($fd_write);

        $this->processFile();
    }

    private function UnzipFile($info){
        $where = '/tmp/'.$info['filename'];
        file_put_contents($where, $c);

        if (\Comodojo\Zip\Zip::check($where)){
            $zip = \Comodojo\Zip\Zip::open($where);
            $where = '/tmp/'.uniqid();
            $zip->extract($where);

            if (is_dir($where)){
                $directories = scandir($where);
                foreach($directories as $dir){
                    if ($dir != "." && $dir != ".."){
                        $i = pathinfo($where."/".$dir);
                        if (isset($i['extension']) && $i['extension'] === 'csv'){
                            $this->CSVFileCheck($where."/".$dir);
                        }else{
                            array_push($this->errors, "A file in the extracted folder (".$i['filename'].") is not valid.");
                            $this->logger->addError("A file in the extracted folder (".$i['filename'].") is not valid.");
                        }
                    }
                }
            }else{
                $i = pathinfo($where);
                if ($i['extension'] === 'csv'){
                    $this->checkFileFromZip($where);
                }else{
                    array_push($this->errors, "The file extracted is not a proper CSV file (".$i['extension'].").");
                    $this->logger->addError("The file extracted is not a proper CSV file (".$i['extension'].").");
                }
            }
        }else{
            array_push($this->errors, "The ZIP file provided seems corrupted (".$where.").");
            $this->logger->addError("The ZIP file provided seems corrupted (".$where.").");
        }
    }

    private function handleErrors(){
        $return = $this->acenda->post("/log", [
            'type' => 'url_based_import',
            'type_id' => $this->configs['acenda']['service']['id'],
            'data' => json_encode($this->errors)
            ]);
    }
    private function getFileListFtp($url) {
        $urlParts = parse_url($url);
        $conn_id = ftp_connect($urlParts['host'],@$urlParts['port']?$urlParts['port']:21);
        echo urldecode($urlParts['user']).' ' . urldecode($urlParts['pass'])."\n";
        if(ftp_login($conn_id,urldecode($urlParts['user']), urldecode($urlParts['pass']))) {
            ftp_pasv($conn_id, true);
            $contents = ftp_nlist($conn_id,@$urlParts['path']?$urlParts['path']:'.');
            return $contents;
        }
        else {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        }
    }
    private function getFileListSftp($url) {
        $urlParts = parse_url($url);

        $this->sftp = new SFTP($urlParts['host'],@$urlParts['port']?$urlParts['port']:22);
        if (!$this->sftp->login(urldecode($urlParts['user']), urldecode($urlParts['pass']))) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $files = $this->sftp->nlist(@$urlParts['path']?$urlParts['path']:'.');
        return $files;
    }
    private function renameFileSftp($url,$oldFilenname,$newFilename) {
        $urlParts = parse_url($url);

        $this->sftp = new SFTP($urlParts['host']);
        if (!$this->sftp->login(urldecode($urlParts['user']), urldecode($urlParts['pass']))) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $this->sftp->chdir($urlParts['path']);
        return $this->sftp->rename($oldFilename,$newFilename);
    }
    private function renameFileFtp($url,$oldFilename,$newFilename){
        $urlParts = parse_url($url);
        $conn_id = ftp_connect($urlParts['host'],@$urlParts['port']?$urlParts['port']:21);
        if(!ftp_login($conn_id,urldecode($urlParts['user']), urldecode($urlParts['pass']))) {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        };
        ftp_pasv($conn_id,true);
        @ftp_chdir($conn_id,($urlParts['path'][0]=='/')?substr($urlParts['path'],1):$urlParts['path']);
        return ftp_rename($conn_id,$oldFilename,$newFilename);
    }
    private function renameFile($oldFilename,$newFilename) {
        echo "renaming $oldFilename to $newFilename\n";
        $protocol = strtok($this->configs['acenda']['subscription']['credentials']['file_url'],':/');
        switch($protocol) {
            case 'sftp':
            $ret=$this->renameFileSftp($this->configs['acenda']['subscription']['credentials']['file_url'],$oldFilename,$newFilename);
            break;
            case 'ftp':
            $ret=$this->renameFileFtp($this->configs['acenda']['subscription']['credentials']['file_url'],$oldFilename,$newFilename);
            break;
            default:
            $ret=false;
            break;
        }  

        return $ret;     
    }
    private function getFileSftp($url) {
        $urlParts = parse_url($url);

        $this->sftp = new SFTP($urlParts['host']);
        if (!$this->sftp->login(urldecode($urlParts['user']), urldecode($urlParts['pass']))) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $this->sftp->chdir($urlParts['path']);
        $data = $this->sftp->get(basename($urlParts['path']));
        return @file_put_contents('/tmp/'.basename($url),$data); 
    }
    private function getFileFtp($url) {
        $urlParts = parse_url($url);
        $conn_id = ftp_connect($urlParts['host'],@$urlParts['port']?$urlParts['port']:21);
        if(!ftp_login($conn_id,urldecode($urlParts['user']), urldecode($urlParts['pass']))) {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        };
        ftp_pasv($conn_id,true);
        @ftp_chdir($conn_id,($urlParts['path'][0]=='/')?substr($urlParts['path'],1):$urlParts['path']);
        return ftp_get($conn_id,'/tmp/'.basename($url),basename($urlParts['path']),FTP_ASCII );
    } 

    private function getFileList() {
        $protocol = strtok($this->configs['acenda']['subscription']['credentials']['file_url'],':/');
        switch($protocol) {
            case 'sftp':
            $files=$this->getFileListSftp($this->configs['acenda']['subscription']['credentials']['file_url']);
            break;
            case 'ftp':
            $files=$this->getFileListFtp($this->configs['acenda']['subscription']['credentials']['file_url']);
            break;
            default:
            $files=false;
            break;
        }  

        return $files;
    }

    private function getFile($filename){
        $protocol = strtok($this->configs['acenda']['subscription']['credentials']['file_url'],':/');
        switch($protocol) {
            case 'sftp':
            $resp=$this->getFileSftp($this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename);
            break;
            case 'ftp':
            $resp=$this->getFileFtp($this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename);
            break;
            default:
            $resp=false;
            break;
        }
        if($resp){
            $info = pathinfo($this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename);
            switch($info["extension"]){
                case "csv":
                $this->CSVFileCheck('/tmp/'.$filename);
                break;
                case "zip":
                $this->UnzipFile($info);
                break;
                default:
                array_push($this->errors, "The extension ".$info['extension']." is not allowed for the moment.");
                $this->logger->addError("The extension ".$info['extension']." is not allowed for the moment.");
                break;
            }
        }else{
            array_push($this->errors, "The file provided at the URL ".$this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename." couldn't be reached.");
            $this->logger->addError("The file provided at the URL ".$this->configs['acenda']['subscription']['credentials']['file_url'].'/'.$filename." couldn't be reached.");
        }
    }
}
