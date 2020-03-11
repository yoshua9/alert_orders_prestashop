<?php   

if ( !defined('_PS_VERSION_') )
  exit;

class alert_duplicity_orders extends Module {

    public function __construct() {
		$this->name = 'alert_duplicity_orders';
		$this->tab = 'administration';
		$this->version = '0.0.1';
		$this->author = 'Krack Zapaterías';
		$this->need_instance = 0;
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_); 
		$this->bootstrap = true;
	
		parent::__construct();
	
		$this->displayName = $this->l('Alerta Pedidos Duplicados');
		$this->description = $this->l('Enviar un email para alertar de pedidos duplicados');
    }

    public function install() {
    	if (!parent::install()) {
			return false;
		} 	
      	else {
			return $this->createAlertMailsTable() && $this->addAlertMails() && $this->registerHook('actionOrderHistoryAddAfter');
		}
	}
	
	public function uninstall() {
        if (!parent::uninstall()) {
            return false;
        } else {
			return $this->dropAlertMailsTable();
        }
    }

	private function createAlertMailsTable() {
      	return $this->executeSQL(
			"CREATE TABLE IF NOT EXISTS `". _DB_PREFIX_ ."alert_duplicity_orders` (
				`id_alert_duplicity_orders`            int(10) unsigned NULL AUTO_INCREMENT,
				`mails`                                text NOT NULL,
				`minutes`                              int(10) NOT NULL,
				PRIMARY KEY (`id_alert_duplicity_orders`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8"
		);
	}
	
	private function dropAlertMailsTable() {
      	return $this->executeSQL(
			"DROP TABLE IF EXISTS `". _DB_PREFIX_ ."alert_duplicity_orders`"
		);
	}

	private function executeSQL($sql) {
		if( !Db::getInstance()->execute($sql) ) 
			return false;
			
      	return true;
	}

    public function getContent()
    {
        $html = '';
        if (Tools::isSubmit('submitModule'))
        {
            $mails = Tools::getValue('mails');
            $minutes = Tools::getValue('minutes');
            $this->updateAlertMails($mails,$minutes);
            $html = $this->displayConfirmation($this->l('Configuration updated'));
        }

        return $html.$this->renderForm();
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('mails'),
                        'name' => 'mails',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('minutes'),
                        'name' => 'minutes',
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save')
                )
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table =  $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false).'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => array(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

            $info = Db::getInstance()->getRow(
                "SELECT `id_alert_duplicity_orders`, `mails`, `minutes`
            FROM `". _DB_PREFIX_ ."alert_duplicity_orders`");

            $helper->tpl_vars['fields_value']['mails'] = $info['mails'];
            $helper->tpl_vars['fields_value']['minutes'] = $info['minutes'];

        return $helper->generateForm(array($fields_form));
    }


	protected function addAlertMails() {
		return Db::getInstance()->execute(
			"INSERT INTO `". _DB_PREFIX_ ."alert_duplicity_orders` (id_alert_duplicity_orders, mails, minutes)
			 VALUES(1, 'yoshua@galvintec.com,luis.alvarez@galvintec.com,', 0)");
	}

    protected function updateAlertMails($mails, $minutes) {

        return Db::getInstance()->execute(
            "UPDATE `". _DB_PREFIX_ ."alert_duplicity_orders`
			 SET `mails` = '". $mails ."',
					 `minutes` = ". (int) $minutes ."
			 WHERE `id_alert_duplicity_orders` = 1");
    }

    public function hookActionOrderHistoryAddAfter($params) {
        if (isset($params['order_history']->id_order)){

            $id_order = (int)$params['order_history']->id_order;
            $order = new Order((int)$params['order_history']->id_order);

            if((((int)$order->current_state ) == 2)){

                $sql = "SELECT mails,minutes FROM `". _DB_PREFIX_ ."alert_duplicity_orders` WHERE `id_alert_duplicity_orders` = 1";
                $emails = Db::getInstance()->executeS($sql);

                $customer = new Customer((int)$order->id_customer);

                $sql2 = "SELECT o.id_order
                        FROM `". _DB_PREFIX_ ."orders` o
                        JOIN `". _DB_PREFIX_ ."order_history` oh ON oh.id_order=o.id_order
                        JOIN `". _DB_PREFIX_ ."customer` c ON c.id_customer=o.id_customer
                        WHERE (c.email LIKE '".$customer->email."' OR o.id_customer = ".(int)$order->id_customer.") AND oh.id_order_state = 2 AND o.id_order != ".$id_order." AND o.date_add BETWEEN DATE_SUB(NOW(), INTERVAL ".$emails[0]['minutes']." MINUTE) AND NOW()";
                $orders_duplicates = Db::getInstance()->executeS($sql2);

                if(count($orders_duplicates) > 0) {
                    $data = array(
                        '{order_id}' => $id_order
                    );

                    $emails = $emails[0]['mails'];
                    if (strlen($emails) > 0) {

                        $emails = explode(',', $emails);
                        foreach ($emails as $email) {
                            $email = trim($email);
                            if (preg_match("/^(([A-Za-z0-9]+_+)|([A-Za-z0-9]+\-+)|([A-Za-z0-9]+\.+)|([A-Za-z0-9]+\++))*[A-Za-z0-9]+@((\w+\-+)|(\w+\.))*\w{1,63}\.[a-zA-Z]{2,6}$/", $email)) {
                                Mail::Send(
                                    1,
                                    'order_duplicate',
                                    Mail::l('Alert Order Duplicate #' . $id_order, 1),
                                    $data,
                                    $email,
                                    '',
                                    null,
                                    null,
                                    null,
                                    null, _PS_MAIL_DIR_, false, (int)$order->id_shop
                                );

                            }

                        }
                    }

                }
            }

        }

    }

}