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
		private $options;

		public function setData() {
			$this->response->error = false;
        	$this->response->msg = '';

			switch ($_SERVER['REQUEST_METHOD']) {
				case 'POST':
					$request = json_decode(file_get_contents("php://input"), true);
 					$this->authentication = $request['authentication'];
					$this->data = $request['data'];
					$this->options = $request['options'];
					switch ($this->options['type']) {
						case 'internal_notes':
							$this->action = 'addNotes';
							break;
						
						case 'replies':
							$this->action = 'addReplies';
							break;

						default:
							$this->action = 'add';
							break;
					}
					break;
				case 'GET':
					$request = json_decode($_GET['request']);
					$this->authentication = (array) $request->authentication;
					$this->data = (array) $request->data;
					if($this->data['tables']){
						$this->action = 'getTables';
					} else {
						$this->action = 'getData';
					}
					break;
				case 'PUT':
					$request = json_decode(file_get_contents("php://input"), true);
					$this->authentication = $request['authentication'];
					$this->data = $request['data'];
					$this->action = 'update';
					break;
			}

			if (empty($this->data)) {
				$this->response->error = true;
				$this->response->msg = "Dados necessários para a utilização da API não encontrados";
				return false;
			}

			return true;
		}

		public function authenticate() {
			$key = $this->authentication['api_key'];
			$secret = $this->authentication['api_secret'];
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
				case 'addNotes':
					if(!$this->addNotes()){
						return false;
					}
					break;
				case 'addReplies':
					if(!$this->addReplies()){
						return false;
					}
					break;
				case 'getData':
					if(!$this->getRows()) {
						return false;
					}
					break;
				case 'update':
					if(!$this->updateRows()) {
						return false;
					}
					break;
				case 'getTables':
					if(!$this->getTables()) {
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
			$insertTicket = $db->execute();

			$id = $db->insertid();
			$relationships = $this->addRelationships($id);

			if (!$insertTicket) {
				$this->response->error = true;
				$this->response->msg = "Erro ao inserir registros";
				return false;
			}

			if(!$relationships) {
				$this->response->error = true;
				$this->response->msg = "Erro ao inserir relacionamentos";
				return false;
			}

			$this->response->msg = "Registros inseridos com sucesso";
			$this->response->ticketid = $ticketid;

			return true;
		}

		public function addRelationships($id) {
			$options = $this->options;
			$data = $this->data;
			$relationships = $options['relationships'];

			if(empty($relationships)) {
				return false;
			}

			foreach($relationships as $key => $values) {
				if($key == 'products') {
					if(!$this->addRelationshipsProducts($id, $values)) {
						return false;
					}
				}
			}

			return true;
		}

		private function addRelationshipsProducts($id, $values) {
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);

			$arrColumns = Array('date_time', 'id_ticket');
			$arrValuesPed = Array(date('Y-m-d H:i:s'), $id);

			$query->clear()
				->insert('#__0_pedidos')
				->columns("`" . implode("`,`", $arrColumns) . "`")
				->values("'" . implode("','", $arrValuesPed) . "'");
			$db->setQuery($query);
			$db->execute();
			
			$idPedido = $db->insertid();
			if(!$idPedido) {
				return false;
			}

			foreach($values as $value) {
				$idProduct = $value['id'];
				$qtnProduct = $value['qtn'];

				$arrColumns = Array('parent_id', 'produto_ped', 'quantidade_ped');
				$arrValuesPed = Array($idPedido, $idProduct, $qtnProduct);
				
				$query->clear()
					->insert('#__0_pedidos_7_repeat')
					->columns("`" . implode("`,`", $arrColumns) . "`")
					->values("'" . implode("','", $arrValuesPed) . "'");
				$db->setQuery($query);
				
				if(!$db->execute()) {
					return false;
				}
			}

			return true;
		}

		public function addNotes() {
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			
			$data = $this->data;
			$ticketIdRandom = $this->data['ticketid'];

			$query->clear()
				->select('t.id, t.staffid')
				->from('#__js_ticket_tickets as t')
				->where('ticketid = ' . $db->quote($ticketIdRandom));
			$db->setQuery($query);
			$result = $db->loadObject();

			$data['ticketid'] = $result->id;
			$data['staffid'] = $result->staffid;

			$arrColumns = array();
			$arrValues = array();
			foreach($data as $column => $value) {
				$arrColumns[] = '`' . $column . '`';
				$arrValues[] = $value;
			}

			$arrColumnsDefault = array("`status`", "`created`");
			foreach($arrColumnsDefault as $column) {
				$arrColumns[] = $column;
			}
			
			$arrValuesDefault = array(1, date('Y-m-d H:i:s'));
			foreach($arrValuesDefault as $value) {
				$arrValues[] = $value;
			}
						
			$query->clear()
				->insert('#__js_ticket_notes')
				->columns(implode(",", $arrColumns))
				->values("'" . implode("','", $arrValues) . "'");
			$db->setQuery($query);

			if (!$db->execute()) {
				$this->response->error = true;
				$this->response->msg = "Erro ao inserir notas";
				return false;
			} else {
				$this->response->msg = "Notas inseridas com sucesso";
			}

			return true;
		}

		public function addReplies() {
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			
			$data = $this->data;
			$ticketIdRandom = $this->data['ticketid'];

			$query->clear()
				->select('t.id, t.staffid')
				->from('#__js_ticket_tickets as t')
				->where('ticketid = ' . $db->quote($ticketIdRandom));
			$db->setQuery($query);
			$result = $db->loadObject();

			$data['ticketid'] = $result->id;
			$data['staffid'] = $result->staffid;

			$arrColumns = array();
			$arrValues = array();
			foreach($data as $column => $value) {
				$arrColumns[] = '`' . $column . '`';
				$arrValues[] = $value;
			}

			$arrColumnsDefault = array("`status`", "`created`");
			foreach($arrColumnsDefault as $column) {
				$arrColumns[] = $column;
			}
			
			$arrValuesDefault = array(1, date('Y-m-d H:i:s'));
			foreach($arrValuesDefault as $value) {
				$arrValues[] = $value;
			}
						
			$query->clear()
				->insert('#__js_ticket_replies')
				->columns(implode(",", $arrColumns))
				->values("'" . implode("','", $arrValues) . "'");
			$db->setQuery($query);

			if (!$db->execute()) {
				$this->response->error = true;
				$this->response->msg = "Erro ao inserir resposta";
				return false;
			} else {
				$this->response->msg = "Resposta inserida com sucesso";
			}

			return true;
		}

		public function getRows() {
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			$ticketsId = $this->data['ticketid'];

			$query->clear()
				->select('t.*, p.priority, d.departmentname, u.username')
				->from('#__js_ticket_tickets as t')
				->join('LEFT', '#__js_ticket_priorities AS p ON t.priorityid = p.id')
				->join('LEFT', '#__js_ticket_departments AS d ON t.departmentid = d.id')
				->join('LEFT', '#__users AS u ON t.uid = u.id')
				->where('ticketid IN ("' . implode('","', (array) $ticketsId) . '")');
			$db->setQuery($query);
			$result = $db->loadObjectList();

			if (empty($result)) {
				$this->response->error = true;
				$this->response->msg = "Registros não encontrados";
				return false;
			}

			$registros = (array) $result;
			foreach($registros as $key => $registro) {
				/* FUNÇÃO INDESEJADA
				//Altera os nomes ufields para significados
				$newParam = new stdClass();
				$oldParam = (array) json_decode($registro->params);
				foreach($oldParam as $uField => $value) {
					$base = 'ufield';
					switch ($uField) {
						case $base.'_20':
							$newParam->projeto = $value;
							break;
						case $base.'_22':
							$newParam->instituicao = $value;
							break;
						case $base.'_23':
							$newParam->limite_contratacao = $value;
							break;
						case $base.'_25':
							$newParam->limite_execucao = $value;
							break;
						case $base.'_31':
							$newParam->etapa = $value;
							break;
						default:
							$newParam->$uField = $value;
							break;
					}
				}
				
				$registros[$key]->params = (array) $newParam;
				*/

				//Retorna as notas internas e as insere na resposta
				$query->clear()
					->select('n.id as idNote, n.title as titleNote, n.note as note')
					->from('#__js_ticket_notes as n')
					->where('n.ticketid = "' . $registros[$key]->id . '"')
					->where('n.status = 1');
				$db->setQuery($query);
				$resultNotes = $db->loadObjectList();
				$registros[$key]->notes = $resultNotes;

				//Retorna as repostas e as insere na resposta
				$query->clear()
					->select('r.id as idReplies, r.name as nameReplies, r.message as messageReplies')
					->from('#__js_ticket_replies as r')
					->where('r.ticketid = "' . $registros[$key]->id . '"')
					->where('r.status = 1');
					$db->setQuery($query);
				$resultReplies = $db->loadObjectList();
				$registros[$key]->replies = $resultReplies;
			}
			
			$this->response->msg = "Registros buscados com sucesso";
			$this->response->tickets = json_encode($registros);

			return true;
		}

		public function updateRows() {
			$db = JFactory::getDbo();

			
			$dataUpdate = new stdClass();
			foreach($this->data as $column => $value) {
				$dataUpdate->$column = $value;
			}
			
			if(!$db->updateObject('#__js_ticket_tickets', $dataUpdate, 'ticketid')) {
				$this->response->error = true;
				$this->response->msg = "Registros não atualizados";
				return false;
			}

			$this->response->msg = "Registros atualizados com sucesso";
			return true;
		}

		public function getTables() {
			$db = JFactory::getDbo();
			$query = $db->getQuery(true);
			$tables = $this->data['tables'];
			$prefix = $db->getPrefix();
			$result = array();

			$query->clear()
				->select('distinct TABLE_NAME')
				->from('information_schema.tables')
				->where('TABLE_NAME IN ("'. $prefix .'' . implode('","' . $prefix . '', (array) $tables) . '")');
			$db->setQuery($query);
			$resultTables = $db->loadAssocList('TABLE_NAME', 'TABLE_NAME');

			$tablesBD = array();
			$othersData = array();
			foreach($tables as $table) {
				if(array_search($prefix . $table, $resultTables)) {
					switch ($table) {
						case 'js_ticket_departments':
							$tablesBD[$prefix . $table] = 'departmentname';
							break;
						case 'js_ticket_priorities':
							$tablesBD[$prefix . $table] = 'priority';
							break;
						case 'users':
							$tablesBD[$prefix . $table] = 'name';
							break;
					}
				}

				if(!array_search($prefix . $table, $resultTables)){
					$othersData[] = $table;
				}
			}

			if (empty($tablesBD) && empty($othersData)) {
				$this->response->error = true;
				$this->response->msg = "Registros não encontrados";
				return false;
			}

			foreach($tablesBD as $table => $column) {
				$query->clear()
					->select('id, ' . $column)
					->from($table)
					->order('id');
				$db->setQuery($query);
				$dataTables = $db->loadAssocList();

				$result[$table] = $dataTables;
			}

			foreach($othersData as $data) {
				switch ($data) {
					case 'projeto':
						$uField = 'ufield_20';
						break;
					
					case 'instituicao':
						$uField = 'ufield_22';
						break;

					case 'etapa':
						$uField = 'ufield_31';
						break;
				}

				$query->clear()
					->select('userfieldparams')
					->from('#__js_ticket_fieldsordering')
					->where('field = ' . $db->quote($uField));
				$db->setQuery($query);
				$resultOthers = json_decode($db->loadObjectList()[0]->userfieldparams);

				$valuesOthers = array();
				$x = 0;
				foreach((array) $resultOthers as $key => $value) {
					if(!is_array($value)) {
						$valuesOthers[$key]['id'] = $value;
						$valuesOthers[$key]['value'] = $value;
					}

					if(is_array($value)) {
						foreach($value as $id => $name) {
							$valuesOthers[$x]['id'] = $name;
							$valuesOthers[$x]['value'] = $name;
							$valuesOthers[$x]['projeto'] = $key;
							$x++;
						}
					}
				}

				$result[$uField . '___' . $data] = $valuesOthers;
			}

			$this->response->msg = "Tabelas buscadas com sucesso";
			$this->response->tickets = json_encode($result);
			
			return true;
		}
	}

	$request = new PlgJssupportTicketJs_Support_Ticket_API();
	if ($request->setData()) {
		if ($request->authenticate()) {
			try {
				$request->defineActionSite();
			} catch(Exception $e) {
				$request->response->error = true;
				$request->response->msg = $e;
			}
		}
	} 

	echo json_encode($request->response);
?>