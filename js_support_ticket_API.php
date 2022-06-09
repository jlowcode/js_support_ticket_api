<?php
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');

	define('_JEXEC', 1);

	// defining the base path.
	if (stristr( $_SERVER['SERVER_SOFTWARE'], 'win32' )) {
    	define( 'JPATH_BASE', realpath(dirname(__FILE__).'\..\..\..' ));
	} else {
		define( 'JPATH_BASE', realpath(dirname(__FILE__).'/../../..' ));
	}

	define('DS', DIRECTORY_SEPARATOR);
	
	// including the main joomla files
	require_once(JPATH_BASE.'/includes/defines.php');
	require_once(JPATH_BASE.'/includes/framework.php');

	// Creating an app instance 
	$app = JFactory::getApplication('site');
	
	$app->initialise();
	jimport('joomla.user.user');
	jimport('joomla.user.helper');

	class PlgJssupportTicketJs_Support_Ticket_API {
		public $response;

    	private $authentication;
    	private $data;
		private $action;

		public function setData() {
			$this->response->error = false;
        	$this->response->msg = '';

			switch ($_SERVER['REQUEST_METHOD']) {
				case 'POST':
				// Dados do POST //
				$this->authentication = json_decode($_POST['authentication']);
				$this->data = json_decode($_POST['data']);
				$this->action = 'add';
				break;
			}

			if ((empty($this->data))) {
				$this->response->error = true;
				$this->response->msg = "Dados necessários para a utilização da API não encontrados";
				return false;
			}

			return true;
		}

		public function authenticate() {
			$key = $this->authentication->api_key;
			$secret = $this->authentication->api_secret;
			$access_token = base64_encode("$key:$secret");

			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			$query
				->select('id')
				->from('#__fabrik_api_access')
				->where("client_id = '{$key}'")
				->where("client_secret = '{$secret}'")
				->where("access_token = '{$access_token}'");
			$db->setQuery($query);
			$result = $db->loadResult();

			if (!$result) {
				$this->response->error = true;
				$this->response->msg = "Acesso não permitido, falha na autenticação";
				return false;
			}

			return true;
		}

		public function defineActionSite() {
			$action = $this->action;
	
			switch ($action) {
				case 'add':
					if(!$this->addRows()) {
						return false;
					}
					break;
			}

			return true;
		}

		public function addRows() {
			$str = "0123456789abcdefghijklmnopqrstuvwxyz".strtoupper('abcdefghijklmnopqrstuvwxyz');
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			
			$data = $this->data;

			$arrColumns = array();
			$arrValues = array();
			$params = array();
			foreach($data as $column => $value) {
				$normal = true;
				switch ($column) {
					case 'projeto':
						$params['ufield_20'] = $value;
						$normal = false;
						break;
					
					case 'instituicao':
						$params['ufield_22'] = $value;
						$normal = false;
						break;
					
					case 'limite_execucao':
						$params['ufield_25'] = $value;
						$duedate = $value;
						$normal = false;
						break;

					case 'etapa':
						$params['ufield_31'] = $value;
						$normal = false;
						break;
				}
				if($normal) {
					$arrColumns[] = '`' . $column . '`';
					$arrValues[] = $value;
				}
			}

			$ticketid = substr(str_shuffle($str), 0, 11);
			$hash = 'Ik' . substr(str_shuffle($str), 0, 6);

			$arrColumnsDefault = array("`ticketid`", "`params`", "`created`", "`hash`", "`ticketviaemail`", "`attachmentdir`", "`status`", "`duedate`");
			foreach($arrColumnsDefault as $column) {
				$arrColumns[] = $column;
			}
			
			$arrValuesDefault = array($ticketid, json_encode($params), date('Y-m-d H:i:s'), $hash, 0, 0, 0, $duedate);
			foreach($arrValuesDefault as $value) {
				$arrValues[] = $value;
			}
						
			$query->clear()
				->insert('#__js_ticket_tickets')
				->columns(implode(",", $arrColumns))
				->values("'" . implode("','", $arrValues) . "'");
			$db->setQuery($query);

			if (!$db->execute()) {
				$this->response->error = true;
				$this->response->msg = "Erro ao inserir registros";
				return false;
			} else {
				$this->response->msg = "Registros inseridos com sucesso";
				$this->response->ticketid = $ticketid;
			}

			return true;
		}
	}

	$request = new PlgJssupportTicketJs_Support_Ticket_API();
	if ($request->setData()) {
		if ($request->authenticate()) {
			$request->defineActionSite();
		}
	}

	echo json_encode($request->response);
?>