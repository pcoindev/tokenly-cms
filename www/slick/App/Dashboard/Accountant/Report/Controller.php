<?php
class Slick_App_Dashboard_Accountant_Report_Controller extends Slick_App_ModControl
{
    function __construct()
    {
        parent::__construct();
        $this->model = new Slick_App_Dashboard_Accountant_Report_Model;
        
    }
    
    public function init()
    {
		$output = parent::init();
		$output['template'] = 'admin';
		$output['view'] = 'index';
		$output['form'] = $this->model->getAddressReportForm();
		$output['error'] = '';
		
		if(posted()){
			$data = $output['form']->grabData();
			
			try{
				$report = $this->model->generateAddressReport($data);
			}
			catch(Exception $e){
				$output['error'] = $e->getMessage();
				$report = false;
			}
			
			if($report){
				$getCsv = arrayToCSV($report);
				$filename = $this->data['user']['username'].'-address-report-'.timestamp().'.csv';
				
				$now = gmdate("D, d M Y H:i:s");
				header("Expires: Tue, 03 Jul 2001 06:00:00 GMT");
				header("Cache-Control: max-age=0, no-cache, must-revalidate, proxy-revalidate");
				// force download  
				header("Content-Type: application/force-download");
				header("Content-Type: application/octet-stream");
				header("Content-Type: application/download");

				// disposition / encoding on response body
				header("Content-Disposition: attachment;filename={$filename}");
				header("Content-Transfer-Encoding: binary");		
				
				ob_end_clean();
				echo $getCsv;
				die();
			}			
		}

		
		return $output;
    }
    
    
    
}
