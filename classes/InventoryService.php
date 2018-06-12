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
    private $urlParts = [];
    private $host = '';
    private $username = '';
    private $password = '';
    private $protocol = 'ftp';
    private $remote_path = '';
    private $path = '';
    private $key = '';

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
        echo "Processing \n";
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

        if(!isset($this->configs['acenda']['subscription']['credentials']['key_type'])) {
            $this->configs['acenda']['subscription']['credentials']['key_type'] = 'sku';
        }
        $this->key =  $this->configs['acenda']['subscription']['credentials']['key_type'];
        if($this->key=="other" && !empty($this->configs['acenda']['subscription']['credentials']['key_custom'])) {
            $this->key = $this->configs['acenda']['subscription']['credentials']['key_custom'];

        } 
        echo "USING KEY: ".$this->key."\n";
        $this->urlParts = parse_url($this->configs['acenda']['subscription']['credentials']['file_url']);
        if(empty($this->urlParts['host'])) {
            $this->host= $this->configs['acenda']['subscription']['credentials']['file_url'];
        } else {
            $this->host = $this->urlParts['host'];
        }

        $this->username = urldecode(@$this->urlParts['user']);
        $this->password = urldecode(@$this->urlParts['pass']);
        $this->protocol = strtok($this->configs['acenda']['subscription']['credentials']['file_url'],':/'); 


        if(!empty($this->configs['acenda']['subscription']['credentials']['username'])) {
            $this->username = $this->configs['acenda']['subscription']['credentials']['username'];
        }
        if(!empty($this->configs['acenda']['subscription']['credentials']['password'])) {
            $this->password = $this->configs['acenda']['subscription']['credentials']['password'];
        }
        if(!empty($this->configs['acenda']['subscription']['credentials']['protocol'])) {
            $this->protocol = $this->configs['acenda']['subscription']['credentials']['protocol'];
        }
        if(empty($this->urlParts['path']) || $this->urlParts['path'] == $this->host) {
            $this->remote_path = '.';
        } else {
            $this->remote_path = $this->urlParts['path'];
        }
        $prefix = $this->configs['acenda']['subscription']['credentials']['file_prefix'];
        $files = $this->getFileList();
        if(is_array($files)) {
            sort($files);
            //$files = array_reverse($files);
            foreach($files as $file) {
                if($prefix && substr($file,0,strlen($prefix))!=$prefix) continue;
                if(strtolower(pathinfo($file, PATHINFO_EXTENSION))!== 'csv' && strtolower(pathinfo($file, PATHINFO_EXTENSION))!== 'txt' ) continue;
                echo "getting ". $file . "\n";
                if(!$this->getFile($file)) break;               
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
    private function getMissing($skus) {
        $page=0;
        $offlineIds=[];
        for($i=1 ; $i; $i++) {
           $response = $this->acenda->get('Variant',['query'=>'{"status":{"$ne":"disabled"}}','limit'=>1000,'attributes'=>$this->key,'sort'=>'id:-1','page'=>$page]);
           if($response->code == 429) {
             sleep(3);
             continue;
           }
           if(!isset($response->body->result)) break;
           if(!count($response->body->result)) break;

           $keytype = $this->key;
           foreach($response->body->result as $r) {
                if(!isset($skus[$r->$keytype])) {
                    $offlineIds[]=$r->id;
                }
           }
           $page++;
        }

       return $offlineIds;
    }
    private function processFile(){
        echo "processing file {$this->path} for {$this->key}\n";
        // write an event
        $eventArr= ['subject_type' => 'inventory', 'subject_id' =>$this->filename, 'message' => "Inventory Feed Started" ,
                 'verb' => 'success'];
        $this->acenda->post('event',$eventArr);


        $fp = fopen($this->path,'r');
        $fieldNames = [$this->key,'current_price','compare_price','quantity'];
        $totalSetOffline = 0;
        $totalSetActive = 0;
        $allskus = [];
        $offlineSkus = [];        
        $numRows = 0;
        $csv = [];
      //  $csv = '"id","status","inventory_quantity","compare_price","price"'."\r\n";
        $csv = '"id","inventory_quantity","compare_price","price"'."\r\n";


        // process 1000 rows at a time
        $data = [];
        while(1) {
            $c=0;
            $storage=[];            
            $skus = [];
            while($data=fgetcsv($fp)) {
                $c++;
               // if(!$c) break;

                $row = array_combine(array_intersect_key($fieldNames, $data), array_intersect_key($data, $fieldNames)); 
                if(isset($row[$this->key])) {
                    $storage[$row[$this->key]]=["row"=>$row,"data"=>[]];
                    $skus[] = $row[$this->key];
                    $allskus[$row[$this->key]] = $row[$this->key];
                } 
                if($c==100) { break; }
            }
            $response = null;

            if(count($skus)) {
                $response = $this->acenda->get('Variant',['query'=>[$this->key=>['$in'=>$skus]],'limit'=>200]);
                if(isset($response->body->result) && is_array($response->body->result)) { 
                    $keytype = $this->key;
                    foreach($response->body->result as $variant) {
                        if(isset($storage[($variant->$keytype)])) {
                           $storage[($variant->$keytype)]['data']=$variant;
                       }
                    }
                } else {
                    if($response && $response->code == 429) {
                         sleep(1); 
                        continue;
                    }
                    // big problems
                    echo "big problems\n";
                    print_r($response);
                    echo "query: ". print_r(['query'=>[$this->key=>['$in'=>$skus]],'limit'=>200],true);
                    break;
                } 
                if(!count($storage)) { 
                    echo "nothing to do\n";
                    break;
                }
            }
            if(!count($storage)) break; 
            foreach($storage as $i=>$d) { 
                $numRows++;
                $row = $d['row'];
                $variant = $d['data'];
                if(!is_object($variant)) continue;
                if(isset($row[$this->key])) {
                    $skus[] = $row[$this->key];

                        $row['current_price'] = str_replace(['$',','],'',$row['current_price']);
                        $row['compare_price'] = str_replace(['$',','],'',$row['compare_price']);  
                        $row['quantity'] = str_replace(['$',','],'',$row['quantity']);                                       
                            $changed = false;
                            $updatedVariant = $variant;                   
                            if(is_numeric($row['current_price']) && $row['current_price'] != $updatedVariant->price) {
                                $updatedVariant->price = $row['current_price'];
                                $changed = 1;
                            }
                            if(is_numeric($row['compare_price']) && $row['compare_price'] != @$updatedVariant->compare_price) {
                                $updatedVariant->compare_price = $row['compare_price'];
                                $changed = 2;                            
                            }
                            if( is_numeric($row['quantity']) && $row['quantity'] != $updatedVariant->inventory_quantity ) {

                                $changed = 3;         
                                $keytype = $this->key;                   
                                $updatedVariant->inventory_quantity = $row['quantity'];                      
                            } 
                            if($changed) {                         
                                $csv_line = '';    
                                $csv_line .= $variant->id.',';
                              //  $csv_line .= $updatedVariant->status.'",';                                                             
                                $csv_line .= $updatedVariant->inventory_quantity.',';
                                $csv_line .= $updatedVariant->compare_price.',';
                                $csv_line .= $updatedVariant->price."\r\n";
                                $csv.=$csv_line;
                            } 
                }
            }
        }

        file_put_contents('/tmp/'.basename($this->filename,'.csv').'-iu.csv',$csv);
       // $csv = 'id,status,inventory_quantity'."\r\n";
        $csv = 'id,inventory_quantity'."\r\n";


        // add the lines to disable missing products
        if(@$this->configs['acenda']['subscription']['credentials']['disable_missing']) {
            $offlineIds=$this->getMissing($allskus);
            foreach($offlineIds as $id) {
                $csv.="$id,0\r\n";
            }
            $totalSetOffline+=count($offlineIds);
        }   

        file_put_contents('/tmp/'.basename($this->filename,'.csv').'-io.csv',$csv);
        $code = 429;
        while($code == 429) {
            $p_response = $this->acenda->post('import/upload',['model'=>'Variant'],['/tmp/'.basename($this->filename,'.csv').'-iu.csv']);
            $code = $p_response->code;
            if($code !== 429) break;             
            sleep(3);
            echo "retrying..\n";
        } 
        echo "uploading inventory updates\n";
        if(!empty($p_response->body->result)) {
            $token = $p_response->body->result;
            $code = 429;
            while($code == 429) {
                $g_response = $this->acenda->post('import/queue/'.$token,(array)json_decode('{"import":{"id":{"name":"id","match":true},"inventory_quantity":{"name":"inventory_quantity"},"compare_price":{"name":"compare_price"},"price":{"name":"price"}}}'));
                $code=$g_response->code;
                if($code !== 429) break; 
                sleep(3);
                echo "retrying..\n";                
            }
            print_r($g_response);

        } else {
             echo "could not upload /tmp/inventoryupdates.csv";
	         print_r($p_response);
        }

        echo "uploading offline setters\n";
        $code = 429;
        while($code == 429) {
            $p_response = $this->acenda->post('import/upload',['model'=>'Variant'],['/tmp/'.basename($this->filename,'.csv').'-io.csv']);
            $code=$p_response->code;
            if($code !== 429) break;         
            sleep(3);
            echo "retrying..\n";    
        }
        if(!empty($p_response->body->result)) {
            $token = $p_response->body->result;
            $code = 429;
            while($code == 429) {            
                $g_response = $this->acenda->post('import/queue/'.$token,(array)json_decode('{"import":{"id":{"name":"id","match":true},"inventory_quantity":{"name":"inventory_quantity"} }}'));
                $code=$g_response->code;
                if($code !== 429) break;
                sleep(3);
                echo "retrying..\n";                   
            }         
            print_r($g_response);
        } else {
             echo "could not upload /tmp/inventorysetoffline.csv";
   	         print_r($p_response);

        }
        // rename file to processed
        if(!$this->renameFile($this->filename,$this->filename.'.processed')) {
            echo "could not rename file! ($this->filename)\n";
        }
        // Send the email    
        $template = new Template();
        $headers = "From: no-reply@acenda.com\r\n";
        $headers .= "Reply-To: no-reply@acenda.com\r\n";
        $headers .= "Return-Path: no-reply@acenda.com\r\n";
        mail($this->configs['acenda']['subscription']['credentials']['email_to'],
            'Processing Inventory Feed File - '.$this->filename,
             $template->render($this->configs['acenda']['subscription']['credentials']['email_template'], ['filename'=>$this->filename,'totalSetOffline'=>$totalSetOffline,'totalSetActive'=>$totalSetActive,'numRows'=>$numRows,'skus'=>$skus,'negativeSkus'=>$offlineSkus])
             ,$headers);

        // write an event
        $eventArr= ['subject_type' => 'inventory', 'subject_id' =>$this->filename, 'message' => "Inventory Feed processed" ,
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
                        if (isset($i['extension']) && $i['extension'] === 'csv' || $i['extension'] === 'txt'){
                            $this->CSVFileCheck($where."/".$dir);
                        }else{
                            array_push($this->errors, "A file in the extracted folder (".$i['filename'].") is not valid.");
                            $this->logger->addError("A file in the extracted folder (".$i['filename'].") is not valid.");
                        }
                    }
                }
            }else{
                $i = pathinfo($where);
                if ($i['extension'] === 'csv' || $i['extension'] === 'txt' ){
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
        echo "connecting to [".$this->host."\nwith ".$this->username.":".$this->password."]\n";
        $conn_id = ftp_connect($this->host,@$this->urlParts['port']?$this->urlParts['port']:21);
        if(@ftp_login($conn_id,$this->username, $this->password)) {
            echo "Getting file list for ".$this->remote_path."\n";            
            ftp_pasv($conn_id, true);
            $contents = ftp_nlist($conn_id,$this->remote_path);
            //print_r($contents);
            return $contents;
        }
        else {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            echo "could not connect to ftp\n";
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        }
    }
    private function getFileListSftp($url) {

        $this->sftp = new SFTP($this->host,@$this->urlParts['port']?$this->urlParts['port']:22);
        if (!$this->sftp->login($this->username, $this->password)) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $files = $this->sftp->nlist($this->remote_path);
        return $files;
    }
    private function renameFileSftp($url,$oldFilename,$newFilename) {
        $this->sftp = new SFTP($this->host);
        if (!$this->sftp->login($this->username, $this->password)) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $this->sftp->chdir($this->remote_path);
        return $this->sftp->rename($oldFilename,$newFilename);
    }
    private function renameFileFtp($url,$oldFilename,$newFilename){

        $conn_id = ftp_connect($this->host,@$this->urlParts['port']?$this->urlParts['port']:21);
        if(!ftp_login($conn_id,$this->username, $this->password)) {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        };
        ftp_pasv($conn_id,true);
        @ftp_chdir($conn_id,($this->remote_path[0]=='/')?substr($this->remote_path,1):$this->remote_path);
        return ftp_rename($conn_id,$oldFilename,$newFilename);
    }
    private function renameFile($oldFilename,$newFilename) {
        echo "renaming $oldFilename to $newFilename\n";
        $protocol = $this->protocol;
        switch(strtolower($protocol)) {
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
        $this->sftp = new SFTP($this->host);
        if (!$this->sftp->login($this->username,$this->password)) {
            array_push($this->errors, 'could not connect via sftp - '.$url);
            $this->logger->addError('could not connect via sftp - '.$url);
            return false;
        };
        $this->sftp->chdir($this->remote_path);
        $data = $this->sftp->get(basename($url));
        return @file_put_contents('/tmp/'.basename($url),$data); 
    }
    private function getFileFtp($url) {
        $conn_id = ftp_connect($this->host,@$this->urlParts['port']?$this->urlParts['port']:21);
        if(!@ftp_login($conn_id,$this->username, $this->password)) {
            array_push($this->errors, 'could not connect via ftp - '.$url);
            $this->logger->addError('could not connect via ftp - '.$url);
            return false;
        };
        ftp_pasv($conn_id,true);
        @ftp_chdir($conn_id,($this->remote_path[0]=='/')?substr($this->remote_path,1):$this->remote_path);
        return ftp_get($conn_id,'/tmp/'.basename($url),basename($url),FTP_ASCII );
    } 

    private function getFileList() {
        $protocol = $this->protocol;
        switch(strtolower($protocol)) {
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
        $protocol = $this->protocol;
        switch(strtolower($protocol)) {
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
            switch(strtolower($info["extension"])){
                case "txt":
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
            return false;
        }
        return true;
    }
}
