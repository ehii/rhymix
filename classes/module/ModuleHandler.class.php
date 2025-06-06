<?php

/**
 * ModuleHandler
 *
 * @author NAVER (developers@xpressengine.com)
 */
class ModuleHandler extends Handler
{
	var $method = 'GET';
	var $module_info = null;
	var $module_srl = null;
	var $module = null;
	var $act = null;
	var $mid = null;
	var $document_srl = null;
	var $entry = null;
	var $route = null;
	var $error = null;
	var $error_detail = null;
	var $is_mobile = false;
	var $httpStatusCode = 200;

	/**
	 * Valid types and kinds of module instances.
	 */
	protected static $_types = array(
		'model' => 'Model',
		'view' => 'View',
		'controller' => 'Controller',
		'mobile' => 'Mobile',
		'api' => 'Api',
		'wap' => 'Wap',
		'class' => '',
	);
	protected static $_kinds = array(
		'admin' => 'Admin',
		'svc' => '',
	);

	/**
	 * List of HTTP methods that do not require CSRF checks by default.
	 */
	protected static $_nocsrf_methods = array(
		'GET', 'HEAD', 'OPTIONS',
	);

	/**
	 * prepares variables to use in moduleHandler
	 * @param string $module name of module
	 * @param string $act name of action
	 * @param string $mid
	 * @param int $document_srl
	 * @param int $module_srl
	 * @return void
	 * */

	public function __construct($module = '', $act = '', $mid = '', $document_srl = '', $module_srl = '')
	{
		// If XE has not installed yet, set module as install
		if(!Context::isInstalled())
		{
			$this->module = 'install';
			$this->act = Context::get('act');
			return;
		}

		// Check security check status
		$oContext = Context::getInstance();
		switch($oContext->security_check)
		{
			case 'OK':
				break;
			case 'ALLOW ADMIN ONLY':
				if(!Context::get('logged_info') || !Context::get('logged_info')->isAdmin())
				{
					$this->error = 'msg_security_violation';
					$this->error_detail = $oContext->security_check_detail;
					return;
				}
				break;
			case 'DENY ALL':
			default:
				$this->error = 'msg_security_violation';
				$this->error_detail = $oContext->security_check_detail;
				return;
		}

		// Set variables from request arguments
		$this->method = Context::getRequestMethod();
		$this->module = strval($module ?: Context::get('module'));
		$this->act = strval($act ?: Context::get('act'));
		$this->mid = strval($mid ?: Context::get('mid'));
		$this->document_srl = intval($document_srl ?: Context::get('document_srl'));
		$this->module_srl = intval($module_srl ?: Context::get('module_srl'));
		$this->route = Context::getCurrentRequest() ?: new stdClass;
		$this->is_mobile = Mobile::isFromMobilePhone();
        if($entry = Context::get('entry'))
        {
            $this->entry = escape($entry, false);
        }
        if(!$this->module && $this->mid === 'admin')
        {
        	Context::set('module', $this->module = 'admin');
        	Context::set('mid', $this->mid = null);
        }

		// call a trigger before moduleHandler init
		self::triggerCall('moduleHandler.init', 'before', $this);

		// execute addon (before module initialization)
		$called_position = 'before_module_init';
		$oAddonController = AddonController::getInstance();
		$addon_file = $oAddonController->getCacheFilePath($this->is_mobile ? 'mobile' : 'pc');
		if(file_exists($addon_file)) include($addon_file);
	}

	/**
	 * Initialization. It finds the target module based on module, mid, document_srl, and prepares to execute an action
	 * @return boolean true: OK, false: redirected
	 * */
	public function init()
	{
		$site_module_info = Context::get('site_module_info');

		// Check unregistered domain action.
		if (!$site_module_info || !isset($site_module_info->domain_srl) || ($site_module_info->is_default_replaced ?? false))
		{
			$site_module_info = ModuleModel::getDefaultDomainInfo();
			if ($site_module_info)
			{
				$domain_action = config('url.unregistered_domain_action') ?: 'redirect_301';
				switch ($domain_action)
				{
					case 'redirect_301':
						header('Location: ' . Context::getDefaultUrl($site_module_info) . RX_REQUEST_URL, true, 301);
						return false;

					case 'redirect_302':
						header('Location: ' . Context::getDefaultUrl($site_module_info) . RX_REQUEST_URL, true, 302);
						return false;

					case 'block':
						$this->error = 'The site does not exist';
						$this->error_detail = 'ERR_DOMAIN_NOT_FOUND';
						$this->httpStatusCode = 404;
						return true;

					case 'display':
						// pass
				}
			}
		}

		// Check success_return_url and error_return_url to prevent dangerous redirects.
		$urls = array('success_return_url', 'error_return_url');
		foreach($urls as $key)
		{
			$url = strval(Context::get($key));
			if ($url && !Rhymix\Framework\URL::isInternalURL($url))
			{
				Context::set($key, null);
			}
		}

		// If the Router returned an error earlier, show an error here.
		if($this->route && $this->route->getRouteStatus() > 200)
		{
			$this->error = 'msg_module_is_not_exists';
			$this->error_detail = 'ERR_ROUTE_NOT_FOUND';
			$this->httpStatusCode = 404;
			return true;
		}

		// Convert document alias (entry) to document_srl
		if(!$this->document_srl && $this->mid && $this->entry)
		{
			$this->document_srl = DocumentModel::getDocumentSrlByAlias($this->mid, $this->entry);
			if($this->document_srl)
			{
				Context::set('document_srl', $this->document_srl);
			}
		}

		// Get module info from document_srl.
		if($this->document_srl)
		{
			$module_info = $this->_checkDocumentSrl();
			if ($module_info === false)
			{
				return false;
			}
		}
		else
		{
			$module_info = null;
		}

		// Get module info from mid.
		if(!$module_info && $this->mid)
		{
			$module_info = ModuleModel::getModuleInfoByMid($this->mid);
			if($module_info && isset($module_info->domain_srl) && $module_info->domain_srl > -1)
			{
				if($module_info->domain_srl != $site_module_info->domain_srl)
				{
					$this->error = 'msg_module_is_not_exists';
					$this->httpStatusCode = 404;
					return true;
				}
			}
		}

		// Set module info as the default module for the domain.
		if(!$module_info && !$this->module && !$this->mid)
		{
			$module_info = $site_module_info;
		}

		// Set the index document.
		if($site_module_info->index_document_srl && !$this->module && !$this->mid && !$this->document_srl && $this->method === 'GET' && !$this->route->args)
		{
			Context::set('document_srl', $this->document_srl = $site_module_info->index_document_srl, true);
		}

		// Redirect if the index module was requested.
		if(!$site_module_info->index_document_srl && $this->method === 'GET' && isset($this->route->args['mid']) && $this->route->args['mid'] === $site_module_info->mid && count($this->route->args) === 1)
		{
			Context::setCacheControl(0);
			header('Location: ' . getNotEncodedSiteUrl($site_module_info->domain), true, 301);
			return false;
		}

		// If module info was set, retrieve variables from the module information
		if($module_info)
		{
			// Set instance variables and SEO info.
			$this->module = $module_info->module;
			$this->mid = $module_info->mid;
			$this->module_info = $module_info;
			$this->_setModuleSEOInfo($module_info, $site_module_info);

			// Check if the current request is from a mobile device.
			$this->is_mobile = Mobile::isFromMobilePhone();
			$viewType = $this->is_mobile ? 'M' : 'P';
			$targetSrl = $viewType === 'M' ? 'mlayout_srl' : 'layout_srl';

			// Apply default layouts.
			if($module_info->{$targetSrl} == -1)
			{
				$oLayoutAdminModel = getAdminModel('layout');
				$layoutSrl = $oLayoutAdminModel->getSiteDefaultLayout($viewType, $module_info->site_srl);
			}
			elseif($module_info->{$targetSrl} == -2 && $viewType === 'M')
			{
				$layoutSrl = $module_info->layout_srl;
				if($layoutSrl == -1)
				{
					$viewType = 'P';
					$oLayoutAdminModel = getAdminModel('layout');
					$layoutSrl = $oLayoutAdminModel->getSiteDefaultLayout($viewType, $module_info->site_srl);
				}
			}
			else
			{
				$layoutSrl = $module_info->{$targetSrl};
			}

			// Reset layout_srl in module_info.
			$module_info->{$targetSrl} = $layoutSrl;
		}
		else
		{
			$this->module_info = new stdClass;
			$this->module_info->module = $this->module;
			$this->module_info->mid = $this->mid;
		}

		$this->_setModuleColorScheme($site_module_info);

		// Always overwrite site_srl (deprecated)
		$this->module_info->site_srl = $site_module_info->site_srl;

		// Still no module? it's an error
		if(!$this->module)
		{
			$this->error = 'msg_module_is_not_exists';
			$this->error_detail = 'ERR_MODULE_NOT_FOUND';
			$this->httpStatusCode = 404;
			return true;
		}

		// If mid exists, set mid into context
		if($this->mid)
		{
			Context::set('mid', $this->mid, TRUE);
		}

		// Call a trigger after moduleHandler init
		$output = self::triggerCall('moduleHandler.init', 'after', $this->module_info);
		if(!$output->toBool())
		{
			$this->error = $output->getMessage();
			return true;
		}

		// Set current module info into context
		Context::set('current_module_info', $this->module_info);

		return true;
	}

	/**
	 * get a module instance and execute an action
	 * @return ModuleObject executed module instance
	 * */
	public function procModule()
	{
		// Set the display mode for the current device type.
		$this->is_mobile = Mobile::isFromMobilePhone();

		// If error occurred while preparation, return a message instance
		if($this->error)
		{
			return self::_createErrorMessage(-1, $this->error, $this->httpStatusCode, $this->error_detail);
		}

		// Get action information with conf/module.xml
		$xml_info = ModuleModel::getModuleActionXml($this->module);

		// If not installed yet, modify act
		if($this->module == "install")
		{
			if(!$this->act || !$xml_info->action->{$this->act})
			{
				$this->act = $xml_info->default_index_act;
			}
		}

		// if act exists, find type of the action, if not use default index act
		if(!$this->act)
		{
			$this->act = $xml_info->default_index_act;
		}

		// still no act means error
		if(!$this->act)
		{
			return self::_createErrorMessage(-1, 'msg_module_is_not_exists', 404, 'ERR_NO_DEFAULT_ACT');
		}

		// get type, kind
		$type = $xml_info->action->{$this->act}->type ?? null;
		$class_name = $xml_info->action->{$this->act}->class_name ?? '';
		$ruleset = $xml_info->action->{$this->act}->ruleset ?? '';
		$meta_noindex = $xml_info->action->{$this->act}->meta_noindex ?? '';
		$kind = (stripos($this->act ?? '', 'admin') !== false || stripos($class_name, 'admin') !== false) ? 'admin' : '';
		$is_namespaced_module = false;
		if ($meta_noindex === 'true')
		{
			Context::addMetaTag('robots', 'noindex');
		}

		if(!$kind && $this->module == 'admin')
		{
			$kind = 'admin';
		}

		// check REQUEST_METHOD
		if(isset($xml_info->action->{$this->act}))
		{
			$allowedMethodList = explode('|', $xml_info->action->{$this->act}->method);
			if(!in_array($_SERVER['REQUEST_METHOD'], $allowedMethodList))
			{
				return self::_createErrorMessage(-1, 'msg_method_not_allowed', 405);
			}
		}

		// check CSRF for non-GET (POST, PUT, etc.) actions
		if(!in_array(Context::getRequestMethod(), self::$_nocsrf_methods) && Context::isInstalled())
		{
			if(isset($xml_info->action->{$this->act}) && $xml_info->action->{$this->act}->check_csrf !== 'false' && !checkCSRF())
			{
				return self::_createErrorMessage(-1, 'msg_security_violation', 403, 'ERR_CSRF_CHECK_FAILED');
			}
		}

		// check if the current action allows standalone access (without mid)
		if(isset($xml_info->action->{$this->act}))
		{
			if($xml_info->action->{$this->act}->standalone === 'auto' && (!$this->module && !$this->mid))
			{
				return self::_createErrorMessage(-1, 'msg_invalid_request', 403, 'ERR_ACT_IS_NOT_STANDALONE');
			}
			if($xml_info->action->{$this->act}->standalone === 'false' && !$this->mid)
			{
				return self::_createErrorMessage(-1, 'msg_invalid_request', 403, 'ERR_ACT_IS_NOT_STANDALONE');
			}
		}

		if(!isset($this->module_info->use_mobile))
		{
			$this->module_info->use_mobile = 'N';
		}
		if($this->module_info->use_mobile !== 'Y')
		{
			Mobile::setMobile(FALSE);
			$this->is_mobile = Mobile::isFromMobilePhone();
		}

		// Replace lang codes in member menu
		$logged_info = Context::get('logged_info');
		if ($logged_info)
		{
			MemberController::replaceLangForMemberMenu($logged_info);
		}

		// Create an instance of the requested module and class
		if($class_name)
		{
			if (isset($xml_info->namespaces) && count($xml_info->namespaces))
			{
				$class_fullname = array_first($xml_info->namespaces) . '\\' . $class_name;
			}
			else
			{
				$class_fullname = sprintf('Rhymix\\Modules\\%s\\%s', $this->module, $class_name);
			}

			if (class_exists($class_fullname))
			{
				$oModule = $class_fullname::getInstance();
				$is_namespaced_module = true;
			}
			else
			{
				return self::_createErrorMessage(-1, 'msg_module_class_not_found', 404);
			}
		}
		elseif($type == "view" && $this->is_mobile && Context::isInstalled())
		{
			$orig_type = "view";
			$type = "mobile";
			$oModule = self::getModuleInstance($this->module, $type, $kind);
			if(!is_object($oModule) || !method_exists($oModule, $this->act))
			{
				$type = $orig_type;
				Mobile::setMobile(FALSE);
				$this->is_mobile = Mobile::isFromMobilePhone();
				$oModule = self::getModuleInstance($this->module, $type, $kind);
			}
		}
		else
		{
			$oModule = self::getModuleInstance($this->module, $type ?: 'class', $kind);
			if (!$oModule)
			{
				$oModule = ModuleModel::getModuleDefaultClass($this->module, $xml_info);
			}
		}

		// If the base module is not found, return an error now.
		if (!isset($oModule) || !is_object($oModule))
		{
			return self::_createErrorMessage(-1, 'msg_module_class_not_found', 404);
		}

		// If there is no such action in the module object
		if(!isset($xml_info->action->{$this->act}) || !method_exists($oModule, $this->act))
		{
			if(!Context::isInstalled())
			{
				return self::_createErrorMessage(-1, 'msg_invalid_request', 403, 'ERR_NOT_FORWARDABLE');
			}

			// 1. Look for the module with action name
			if(preg_match('/^[a-z]+([A-Z][a-z0-9\_]+).*$/', $this->act, $matches))
			{
				$module = strtolower($matches[1]);
				$xml_info = ModuleModel::getModuleActionXml($module);

				if(!isset($xml_info->action->{$this->act}))
				{
					return self::_createErrorMessage(-1, 'msg_invalid_request', 403, 'ERR_ACT_NOT_FOUND');
				}
				elseif ($xml_info->action->{$this->act}->standalone === 'auto' && $this->module !== 'admin' && $this->module !== $module)
				{
					return self::_createErrorMessage(-1, 'msg_invalid_request', 403, 'ERR_ACT_IS_NOT_STANDALONE');
				}
				elseif ($xml_info->action->{$this->act}->standalone === 'false' && $this->module !== 'admin')
				{
					return self::_createErrorMessage(-1, 'msg_invalid_request', 403, 'ERR_ACT_IS_NOT_STANDALONE');
				}
				else
				{
					$forward = new stdClass();
					$forward->module = $module;
					$forward->type = $xml_info->action->{$this->act}->type;
					$forward->class_name = $xml_info->action->{$this->act}->class_name;
					$forward->ruleset = $xml_info->action->{$this->act}->ruleset;
					$forward->meta_noindex = $xml_info->action->{$this->act}->meta_noindex;
					$forward->act = $this->act;
				}
			}

			if(empty($forward->module))
			{
				$forward = ModuleModel::getActionForward($this->act);
			}

			if(!empty($forward->module))
			{
				$kind = (stripos($forward->act, 'admin') !== false || stripos($forward->class_name ?? '', 'admin') !== false) ? 'admin' : '';
				$type = $forward->type ?? null;
				$ruleset = $forward->ruleset ?? null;
				$tpl_path = $oModule->getTemplatePath();
				$orig_module = $oModule;
				if(isset($forward->meta_noindex) && $forward->meta_noindex === 'true')
				{
					Context::addMetaTag('robots', 'noindex');
				}

				$xml_info = ModuleModel::getModuleActionXml($forward->module);

				// Protect admin action
				if(($this->module == 'admin' || $kind == 'admin') && !ModuleModel::getGrant($forward, $logged_info)->root)
				{
					if($this->module == 'admin' || empty($xml_info->action->{$this->act}->permission->target))
					{
						return self::_createErrorMessage(-1, 'admin.msg_is_not_administrator');
					}
				}

				// SECISSUE also check REQUEST_METHOD for forwarded actions
				$allowedMethodList = explode('|', $xml_info->action->{$this->act}->method);
				if(!in_array($_SERVER['REQUEST_METHOD'], $allowedMethodList))
				{
					return self::_createErrorMessage(-1, 'msg_method_not_allowed', 405);
				}

				// check CSRF for non-GET (POST, PUT, etc.) actions
				if(!in_array(Context::getRequestMethod(), self::$_nocsrf_methods) && Context::isInstalled())
				{
					if($xml_info->action->{$this->act} && $xml_info->action->{$this->act}->check_csrf !== 'false' && !checkCSRF())
					{
						return self::_createErrorMessage(-1, 'msg_security_violation', 403, 'ERR_CSRF_CHECK_FAILED');
					}
				}

				if(!empty($forward->class_name))
				{
					if (isset($xml_info->namespaces) && count($xml_info->namespaces))
					{
						$class_fullname = array_first($xml_info->namespaces) . '\\' . $forward->class_name;
					}
					else
					{
						$class_fullname = sprintf('Rhymix\\Modules\\%s\\%s', $forward->module, $forward->class_name);
					}

					if (class_exists($class_fullname))
					{
						$oModule = $class_fullname::getInstance();
						$is_namespaced_module = true;
					}
					else
					{
						return self::_createErrorMessage(-1, 'msg_module_class_not_found', 404);
					}
				}
				elseif($type == "view" && $this->is_mobile)
				{
					$orig_type = "view";
					$type = "mobile";
					// create a module instance
					$oModule = self::getModuleInstance($forward->module, $type, $kind);
					if(!is_object($oModule) || !method_exists($oModule, $this->act))
					{
						$type = $orig_type;
						Mobile::setMobile(FALSE);
						$this->is_mobile = Mobile::isFromMobilePhone();
						$oModule = self::getModuleInstance($forward->module, $type, $kind);
					}
				}
				else
				{
					$oModule = self::getModuleInstance($forward->module, $type, $kind);
				}

				if(!is_object($oModule))
				{
					return self::_createErrorMessage(-1, 'msg_module_class_not_found', 404);
				}

				// Admin page layout
				if($this->module == 'admin' && $type == 'view' && $this->act != 'dispLayoutAdminLayoutModify')
				{
					Rhymix\Modules\Admin\Controllers\Base::getInstance()->loadAdminMenu($forward->module);
					$oModule->setLayoutPath("./modules/admin/tpl");
					$oModule->setLayoutFile("layout.html");
				}
			}
			else if($xml_info->default_index_act && method_exists($oModule, $xml_info->default_index_act))
			{
				$this->act = $xml_info->default_index_act;
			}
			else
			{
				$this->error = 'msg_invalid_request';
				$oModule->setError(-1);
				$oModule->setMessage($this->error);
				return $oModule;
			}
		}

		// ruleset check...
		if(!empty($ruleset))
		{
			// Rulesets are deprecated for namespaced modules
			if ($is_namespaced_module)
			{
				trigger_error('Ruleset is deprecated in namespaced modules', E_USER_WARNING);
			}

			$rulesetModule = !empty($forward->module) ? $forward->module : $this->module;
			$rulesetFile = ModuleModel::getValidatorFilePath($rulesetModule, $ruleset, $this->mid);
			if(!empty($rulesetFile))
			{
				if($_SESSION['XE_VALIDATOR_ERROR_LANG'])
				{
					$errorLang = $_SESSION['XE_VALIDATOR_ERROR_LANG'];
					foreach($errorLang as $key => $val)
					{
						Context::setLang($key, $val);
					}
					unset($_SESSION['XE_VALIDATOR_ERROR_LANG']);
				}

				$Validator = new Validator($rulesetFile);
				$result = $Validator->validate();
				if(!$result)
				{
					$lastError = $Validator->getLastError();
					$returnUrl = Context::get('error_return_url');
					$errorMsg = $lastError['msg'] ? $lastError['msg'] : 'validation error';

					//for xml response
					$oModule->setError(-1);
					$oModule->setMessage($errorMsg);
					$oModule->setRedirectUrl($returnUrl);
					//for html redirect
					$this->error = $errorMsg;
					$_SESSION['XE_VALIDATOR_ERROR'] = -1;
					$_SESSION['XE_VALIDATOR_MESSAGE'] = $this->error;
					$_SESSION['XE_VALIDATOR_MESSAGE_TYPE'] = 'error';
					$_SESSION['XE_VALIDATOR_RETURN_URL'] = $returnUrl;
					$_SESSION['XE_VALIDATOR_ID'] = Context::get('xe_validator_id');
					self::_setInputValueToSession();
					return $oModule;
				}
			}
		}

		$oModule->setAct($this->act);

		$this->module_info->module_type = $type;
		$oModule->setModuleInfo($this->module_info, $xml_info);

		if(($type === 'view' || $type === 'mobile') && $kind !== 'admin')
		{
			$domain_info = Context::get('site_module_info');
			if ($domain_info && $domain_info->settings && !empty($domain_info->settings->html_header))
			{
				Context::addHtmlHeader($domain_info->settings->html_header);
			}
			if ($domain_info && $domain_info->settings && !empty($domain_info->settings->html_footer))
			{
				Context::addHtmlFooter($domain_info->settings->html_footer);
			}
			if ($domain_info && $domain_info->settings && !empty($domain_info->settings->title))
			{
				if(!Context::getBrowserTitle())
				{
					Context::setBrowserTitle($domain_info->settings->title);
				}
			}
		}

		if ($kind === 'admin')
		{
			Context::addMetaTag('robots', 'noindex');
		}

		// if failed message exists in session, set context
		self::_setInputErrorToContext();

		$procResult = $oModule->proc();

		$methodList = array('XMLRPC' => 1, 'JSON' => 1, 'JS_CALLBACK' => 1);
		if(!$oModule->stop_proc && !isset($methodList[Context::getRequestMethod()]))
		{
			$error = $oModule->getError();
			$message = $oModule->getMessage();
			$messageType = $oModule->getMessageType();
			$redirectUrl = $oModule->getRedirectUrl();
			if(!$procResult)
			{
				$this->error = $message;
				if(!$redirectUrl && Context::get('error_return_url'))
				{
					$redirectUrl = Context::get('error_return_url');
				}
				self::_setInputValueToSession();
			}
			if($error != 0)
			{
				$_SESSION['XE_VALIDATOR_ERROR'] = $error;
			}
			if($validator_id = Context::get('xe_validator_id'))
			{
				$_SESSION['XE_VALIDATOR_ID'] = $validator_id;
			}
			if($message != 'success')
			{
				$_SESSION['XE_VALIDATOR_MESSAGE'] = $message;
				$_SESSION['XE_VALIDATOR_MESSAGE_TYPE'] = $messageType;
			}
			if(Context::get('xeVirtualRequestMethod') === 'xml')
			{
				$oModule->setRedirectUrl(null);
			}
			elseif($redirectUrl)
			{
				$_SESSION['XE_VALIDATOR_RETURN_URL'] = $redirectUrl;
				$oModule->setRedirectUrl($redirectUrl);
			}
		}

		return $oModule;
	}

	/**
	 * Check the value of $document_srl. This method is called during init().
	 *
	 * This method returns:
	 * - Module info object if the document can be shown in the module,
	 * - null if the document can be shown but the module is unspecified,
	 * - false if we should redirect to another module.
	 *
	 * @return object|null|false
	 */
	protected function _checkDocumentSrl()
	{
		// Get the module that the document belongs to.
		$module_info = ModuleModel::getModuleInfoByDocumentSrl($this->document_srl);
		if($module_info)
		{
			// Compare the current mid to the module that the document belongs to.
			if(!$this->mid || $this->mid !== $module_info->mid)
			{
				// If the document is notice-all, preserve the current mid.
				if($module_info->is_notice === 'A' && !empty($this->mid))
				{
					return null;
				}
				// If the requested mid is set to include documents from other modules, preserve the current mid.
				elseif($this->mid && ($mid_info = ModuleModel::getModuleInfoByMid($this->mid)) && $mid_info->include_modules && in_array($module_info->module_srl, explode(',', $mid_info->include_modules)))
				{
					return $mid_info;
				}
				// If this is a GET request, redirect to the correct mid.
				elseif(Context::getRequestMethod() === 'GET')
				{
					Context::setCacheControl(0);
					header('Location: ' . getNotEncodedUrl('', 'mid', $module_info->mid, 'document_srl', $this->document_srl), true, 301);
					return false;
				}
				// If this is NOT a GET request, don't redirect. Just overwrite the mid for the current request.
				else
				{
					$this->mid = $module_info->mid;
					Context::set('mid', $this->mid);
				}
			}

			// Remove module info if a different module has already been selected for the current request.
			if($this->module && $module_info->module !== $this->module)
			{
				$module_info = null;
			}
		}

		// Block access to secret or temporary documents.
		if(Context::getRequestMethod() === 'GET')
		{
			$oDocument = DocumentModel::getDocument($this->document_srl);
			if(!$oDocument->isExists())
			{
				$this->httpStatusCode = 404;
			}
			elseif(!$oDocument->isAccessible())
			{
				$this->httpStatusCode = 403;
			}
		}

		// Return the module info for further processing.
		return $module_info;
	}

	/**
	 * Set color scheme.
	 *
	 * @param object $site_module_info
	 */
	protected function _setModuleColorScheme($site_module_info)
	{
		$color_scheme = ($site_module_info->settings->color_scheme ?? '') ?: 'auto';
		if ($color_scheme === 'auto')
		{
			$color_scheme = Rhymix\Framework\UA::getColorScheme();
		}
		if (!in_array($color_scheme, ['auto', 'light', 'dark']))
		{
			$color_scheme = 'auto';
		}
		if ($color_scheme !== 'auto')
		{
			Context::addBodyClass('color_scheme_' . $color_scheme);
		}
	}

	/**
	 * Set SEO information to Context.
	 *
	 * @param object $module_info
	 * @param object $site_module_info
	 */
	protected function _setModuleSEOInfo($module_info, $site_module_info)
	{
		// Set the browser title.
		if ($module_info->mid == $site_module_info->mid)
		{
			$seo_title = config('seo.main_title') ?: '$SITE_TITLE - $SITE_SUBTITLE';
		}
		else
		{
			$seo_title = config('seo.subpage_title') ?: '$SITE_TITLE - $SUBPAGE_TITLE';
		}
		$seo_title = Context::replaceUserLang($seo_title);
		Context::setBrowserTitle($seo_title, array(
			'site_title' => Context::getSiteTitle(),
			'site_subtitle' => Context::getSiteSubtitle(),
			'subpage_title' => $module_info->browser_title,
			'page' => Context::get('page') ?: 1,
		));

		// Set meta keywords.
		$module_config = ModuleModel::getModuleConfig('module');
		if (!empty($module_info->meta_keywords))
		{
			Context::addMetaTag('keywords', $module_info->meta_keywords);
		}
		elseif (!empty($site_module_info->settings->meta_keywords))
		{
			Context::addMetaTag('keywords', $site_module_info->settings->meta_keywords);
		}
		elseif (!empty($module_config->meta_keywords))
		{
			Context::addMetaTag('keywords', $module_config->meta_keywords);
		}

		// Set meta description.
		if (!empty($module_info->meta_description))
		{
			Context::addMetaTag('description', $module_info->meta_description);
		}
		elseif (!empty($site_module_info->settings->meta_description))
		{
			Context::addMetaTag('description', $site_module_info->settings->meta_description);
		}
		elseif (!empty($module_config->meta_description))
		{
			Context::addMetaTag('description', $module_config->meta_description);
		}
	}

	/**
	 * Save input values to session so that they can be recovered after returning to the previous form.
	 *
	 * @return void
	 */
	protected static function _setInputValueToSession()
	{
		$requestVars = getDestroyXeVars(Context::getRequestVars());
		unset($requestVars->act, $requestVars->mid, $requestVars->vid);
		foreach($requestVars as $key => $value)
		{
			$_SESSION['INPUT_ERROR'][$key] = $value;
		}
	}

	/**
	 * Get previous error information and restore it to Context so that it is available to templates.
	 *
	 * @return void
	 */
	protected static function _setInputErrorToContext()
	{
		$keys = [
			'XE_VALIDATOR_ID',
			'XE_VALIDATOR_MESSAGE',
			'XE_VALIDATOR_MESSAGE_TYPE',
			'XE_VALIDATOR_ERROR',
			'XE_VALIDATOR_RETURN_URL',
		];

		foreach ($keys as $key)
		{
			if (!Context::get($key))
			{
				Context::set($key, empty($_SESSION[$key]) ? null : $_SESSION[$key]);
			}
		}

		if (!empty($_SESSION['INPUT_ERROR']))
		{
			Context::set('INPUT_ERROR', $_SESSION['INPUT_ERROR']);
		}

		self::_clearErrorSession();
	}

	/**
	 * clear error message to Session.
	 * @return void
	 * */
	protected static function _clearErrorSession()
	{
		unset($_SESSION['XE_VALIDATOR_ERROR']);
		unset($_SESSION['XE_VALIDATOR_MESSAGE']);
		unset($_SESSION['XE_VALIDATOR_MESSAGE_TYPE']);
		unset($_SESSION['XE_VALIDATOR_RETURN_URL']);
		unset($_SESSION['XE_VALIDATOR_ID']);
		unset($_SESSION['INPUT_ERROR']);
	}

	/**
	 * Create a message module instance with an error message.
	 */
	protected static function _createErrorMessage($error, $message, $status_code = 403, $detail = '', $location = null)
	{
		if (!$location)
		{
			$backtrace = debug_backtrace(false);
			$caller = array_shift($backtrace);
			$location = $caller['file'] . ':' . $caller['line'];
		}

		self::_setInputErrorToContext();

		if (Mobile::isFromMobilePhone())
		{
			$oMessageObject = MessageMobile::getInstance();
		}
		else
		{
			$oMessageObject = MessageView::getInstance();
		}
		$oMessageObject->setError($error);
		$oMessageObject->setMessage($message);
		$oMessageObject->setHttpStatusCode($status_code ?: 403);
		$oMessageObject->dispMessage($detail, $location);
		return $oMessageObject;
	}

	/**
	 * display contents from executed module
	 * @param ModuleObject $oModule module instance
	 * @return void
	 * */
	public function displayContent($oModule = NULL)
	{
		// Set the display mode for the current device type.
		$this->is_mobile = Mobile::isFromMobilePhone();

		// If the module is not set or not an object, set error
		if(!$oModule || !is_object($oModule))
		{
			$this->error = 'msg_module_is_not_exists';
			$this->httpStatusCode = 404;
		}

		// If connection to DB has a problem even though it's not install module, set error
		if($this->module != 'install' && !DB::getInstance()->getHandle())
		{
			$this->error = 'msg_dbconnect_failed';
		}

		// Call trigger after moduleHandler proc
		$output = self::triggerCall('moduleHandler.proc', 'after', $oModule);
		if(!$output->toBool())
		{
			$this->error = $output->getMessage();
		}

		// Use message view object, if HTML call
		$methodList = array('XMLRPC' => 1, 'JSON' => 1, 'JS_CALLBACK' => 1);
		if(!isset($methodList[Context::getRequestMethod()]))
		{
			// Handle redirects.
			if($oModule->getRedirectUrl())
			{
				if (!empty($_SESSION['is_new_session']))
				{
					ob_end_clean();
					echo sprintf('<html><head><meta charset="UTF-8" /><meta http-equiv="refresh" content="0; url=%s" /></head><body></body></html>', escape($oModule->getRedirectUrl()));
					return;
				}
				else
				{
					ob_end_clean();
					if (in_array($oModule->getHttpStatusCode(), [301, 303, 307, 308]))
					{
						self::_setHttpStatusMessage($oModule->getHttpStatusCode());
					}
					else
					{
						self::_setHttpStatusMessage(302);
					}
					header(sprintf('HTTP/1.1 %d %s', Context::get('http_status_code'), Context::get('http_status_message')));
					header(sprintf('Location: %s', $oModule->getRedirectUrl()));
					Context::setCacheControl(0);
					return;
				}
			}

			// If error occurred, handle it
			if($this->error)
			{
				// display content with message module instance
				$oMessageObject = self::_createErrorMessage(-1, $this->error, $this->httpStatusCode, $this->error_detail, $oModule->get('rx_error_location'));

				// display Error Page
				if(!in_array($oMessageObject->getHttpStatusCode(), array(200, 403)))
				{
					$oMessageObject->setTemplateFile('http_status_code');
				}

				// If module was called normally, change the templates of the module into ones of the message view module
				if($oModule)
				{
					$oModule->setTemplatePath($oMessageObject->getTemplatePath());
					$oModule->setTemplateFile($oMessageObject->getTemplateFile());
					$oModule->setHttpStatusCode($oMessageObject->getHttpStatusCode());
				}
				// Otherwise, set message instance as the target module
				else
				{
					$oModule = $oMessageObject;
				}

				self::_clearErrorSession();
			}

			// Check if layout_srl exists for the module
			$viewType = $this->is_mobile ? 'M' : 'P';
			if($viewType === 'M')
			{
				$layout_srl = $oModule->module_info->mlayout_srl ?? 0;
				if($layout_srl == -2)
				{
					$layout_srl = $oModule->module_info->layout_srl ?? 0;
					$viewType = 'P';
				}
			}
			else
			{
				$layout_srl = $oModule->module_info->layout_srl ?? 0;
			}

			// if layout_srl is rollback by module, set default layout
			if($layout_srl == -1)
			{
				$oLayoutAdminModel = LayoutAdminModel::getInstance();
				$layout_srl = $oLayoutAdminModel->getSiteDefaultLayout($viewType, $oModule->module_info->site_srl);
			}

			if($layout_srl && !$oModule->getLayoutFile())
			{
				// If layout_srl exists, get information of the layout, and set the location of layout_path/ layout_file
				$oLayoutModel = LayoutModel::getInstance();
				$layout_info = $oLayoutModel->getLayout($layout_srl);
				if($layout_info)
				{
					// Input extra_vars into $layout_info
					if(isset($layout_info->extra_var_count) && $layout_info->extra_var_count)
					{

						foreach($layout_info->extra_var as $var_id => $val)
						{
							if($val->type == 'image')
							{
								if(strncmp('./files/attach/images/', $val->value ?? '', 22) === 0)
								{
									$val->value = Context::getRequestUri() . substr($val->value, 2);
								}
							}
							$layout_info->{$var_id} = $val->value;
						}
					}
					// Set menus into context
					if(isset($layout_info->menu_count) && $layout_info->menu_count)
					{
						$oMenuAdminController = getAdminController('menu');
						$homeMenuCacheFile = null;

						foreach($layout_info->menu as $menu_id => $menu)
						{							// No menu selected
							if($menu->menu_srl == 0)
							{
								$menu->list = array();
							}
							else
							{
								if($menu->menu_srl == -1)
								{
									if ($homeMenuCacheFile === null)
									{
										$homeMenuCacheFile = $oMenuAdminController->getHomeMenuCacheFile();
									}

									$homeMenuSrl = 0;
									if(FileHandler::exists($homeMenuCacheFile))
									{
										include($homeMenuCacheFile);
									}

									$menu->xml_file = './files/cache/menu/' . $homeMenuSrl . '.xml.php';
									$menu->php_file = './files/cache/menu/' . $homeMenuSrl . '.php';
									$menu->menu_srl = $homeMenuSrl;
								}

								$php_file = FileHandler::exists($menu->php_file);
								if(!$php_file)
								{
									$oMenuAdminController->makeXmlFile($menu->menu_srl);
									$php_file = FileHandler::exists($menu->php_file);
								}
								if($php_file)
								{
									include($php_file);
								}
							}

							Context::set($menu_id, $menu);
						}
					}

					// Set layout information into context
					Context::set('layout_info', $layout_info);

					$oModule->setLayoutPath($layout_info->path);
					$oModule->setLayoutFile('layout');

					// If layout was modified, use the modified version
					$edited_layout = $oLayoutModel->getUserLayoutHtml($layout_info->layout_srl);
					if(file_exists($edited_layout))
					{
						$oModule->setEditedLayoutFile($edited_layout);
					}
				}
			}
			$isLayoutDrop = Context::get('isLayoutDrop');
			if($isLayoutDrop)
			{
				$kind = stripos($this->act, 'admin') !== FALSE ? 'admin' : '';
				if($kind == 'admin')
				{
					$oModule->setLayoutFile('popup_layout');
				}
				elseif (HTMLDisplayHandler::isPartialPageRendering())
				{
					$oModule->setLayoutPath('common/tpl');
					$oModule->setLayoutFile('default_layout');
				}
			}
		}

		// Set http status code
		if($this->httpStatusCode && $oModule->getHttpStatusCode() === 200)
		{
			$oModule->setHttpStatusCode($this->httpStatusCode);
		}

		// Set http status message
		self::_setHttpStatusMessage($oModule->getHttpStatusCode());

		// Display contents
		$oDisplayHandler = new DisplayHandler();
		$oDisplayHandler->printContent($oModule);
	}

	/**
	 * Process command line arguments.
	 *
	 * @param array $args
	 * @return void
	 */
	public static function procCommandLineArguments(array $args)
	{
		$command = count($args) > 1 ? $args[1] : '';
		$args = count($args) > 2 ? array_slice($args, 2) : [];
		if (!$command)
		{
			echo 'Command not supplied.' . PHP_EOL;
			exit(1);
		}
		if (!preg_match('/^([a-z0-9_-]+)\.([a-z0-9_-]+)$/i', $command, $matches))
		{
			echo 'Invalid command: ' . $command . PHP_EOL;
			exit(1);
		}

		$path = sprintf('%s%s/scripts/%s.php', \RX_BASEDIR , $matches[1] === 'common' ? 'common' : ('modules/' . $matches[1]), $matches[2]);
		if (!file_exists($path) || !is_readable($path))
		{
			echo 'Command not found: ' . $command . PHP_EOL;
			exit(1);
		}

		require_once \RX_BASEDIR . 'common/scripts/common.php';
		require_once $path;
	}

	/**
	 * returns module's path
	 * @param string $module module name
	 * @return string path of the module
	 * */
	public static function getModulePath($module)
	{
		return sprintf('./modules/%s/', $module);
	}

	/**
	 * It creates a module instance
	 * @param string $module module name
	 * @param string $type instance type, (e.g., view, controller, model)
	 * @param string $kind admin or svc
	 * @return ModuleObject module instance (if failed it returns null)
	 * @remarks if there exists a module instance created before, returns it.
	 * */
	public static function getModuleInstance($module, $type = 'view', $kind = '')
	{
		$type = strtolower($type);
		if (!isset(self::$_types[$type]))
		{
			$type = 'view';
		}

		$kind = strtolower($kind);
		if (!isset(self::$_kinds[$kind]) || $type === 'class')
		{
			$kind = 'svc';
		}

		$class_name = $module . self::$_kinds[$kind] . self::$_types[$type];
		if (class_exists($class_name) && is_subclass_of($class_name, 'ModuleObject'))
		{
			return $class_name::getInstance($module);
		}
	}

	/**
	 * call a trigger
	 * @param string $trigger_name trigger's name to call
	 * @param string $called_position called position
	 * @param object $obj an object as a parameter to trigger
	 * @return BaseObject
	 * */
	public static function triggerCall($trigger_name, $called_position, &$obj)
	{
		// skip if not installed
		if(!Context::isInstalled())
		{
			return new BaseObject();
		}

		$triggers = ModuleModel::getTriggers($trigger_name, $called_position);
		if(!$triggers)
		{
			$triggers = array();
		}

		foreach($triggers as $item)
		{
			$module = $item->module;
			$type = $item->type;
			$called_method = $item->called_method;

			// Get instance of module class
			if (!preg_match('/^(controller|model|view|mobile|api|wap|class)$/', $type))
			{
				$class_name = ($type[0] === '\\') ? $type : sprintf('Rhymix\\Modules\\%s\\%s', $module, $type);
				if (class_exists($class_name))
				{
					$oModule = $class_name::getInstance();
				}
			}
			else
			{
				$oModule = getModule($module, $type);
			}

			if(!$oModule || !method_exists($oModule, $called_method))
			{
				continue;
			}

			// do not call if module is blacklisted
			if (Context::isBlacklistedPlugin($oModule->module))
			{
				continue;
			}

			try
			{
				$before_each_trigger_time = microtime(true);
				$output = $oModule->{$called_method}($obj);
				$after_each_trigger_time = microtime(true);
			}
			catch (Rhymix\Framework\Exception $e)
			{
				$output = new BaseObject(-2, $e->getMessage());
			}

			if ($trigger_name !== 'common.flushDebugInfo')
			{
				$trigger_target = $module . ($type === 'class' ? '' : $type) . '.' . $called_method;

				if (Rhymix\Framework\Debug::isEnabledForCurrentUser())
				{
					Rhymix\Framework\Debug::addTrigger(array(
						'name' => $trigger_name . '.' . $called_position,
						'target' => $trigger_target,
						'target_plugin' => $module,
						'elapsed_time' => $after_each_trigger_time - $before_each_trigger_time,
					));
				}
			}

			if($output instanceof BaseObject && !$output->toBool())
			{
				return $output;
			}
			unset($oModule);
		}

		$trigger_functions = ModuleModel::getTriggerFunctions($trigger_name, $called_position);
		foreach($trigger_functions as $item)
		{
			try
			{
				$before_each_trigger_time = microtime(true);
				$output = $item($obj);
				$after_each_trigger_time = microtime(true);
			}
			catch (Rhymix\Framework\Exception $e)
			{
				$output = new BaseObject(-2, $e->getMessage());
			}

			if ($trigger_name !== 'common.writeSlowlog')
			{
				if (is_string($item))
				{
					$trigger_target = $item;
				}
				elseif (is_array($item) && count($item))
				{
					if (is_object($item[0]))
					{
						$trigger_target = get_class($item[0]) . '.' . strval($item[1]);
					}
					else
					{
						$trigger_target = implode('.', $item);
					}
				}
				else
				{
					$trigger_target = 'closure';
				}

				if (Rhymix\Framework\Debug::isEnabledForCurrentUser())
				{
					Rhymix\Framework\Debug::addTrigger(array(
						'name' => $trigger_name . '.' . $called_position,
						'target' => $trigger_target,
						'target_plugin' => null,
						'elapsed_time' => $after_each_trigger_time - $before_each_trigger_time,
					));
				}
			}

			if(is_object($output) && method_exists($output, 'toBool') && !$output->toBool())
			{
				return $output;
			}
		}

		return new BaseObject();
	}

	/**
	 * get http status message by http status code
	 * @param string $code
	 * @return string
	 * */
	public static function _setHttpStatusMessage($code)
	{
		$statusMessageList = array(
			'100' => 'Continue',
			'101' => 'Switching Protocols',
			'102' => 'Processing',
			'103' => 'Checkpoint',
			'200' => 'OK',
			'201' => 'Created',
			'202' => 'Accepted',
			'203' => 'Non-Authoritative Information',
			'204' => 'No Content',
			'205' => 'Reset Content',
			'206' => 'Partial Content',
			'207' => 'Multi-Status',
			'208' => 'Already Reported',
			'226' => 'IM Used',
			'300' => 'Multiple Choices',
			'301' => 'Moved Permanently',
			'302' => 'Found',
			'303' => 'See Other',
			'304' => 'Not Modified',
			'305' => 'Use Proxy',
			'306' => 'Switch Proxy',
			'307' => 'Temporary Redirect',
			'308' => 'Permanent Redirect',
			'400' => 'Bad Request',
			'401' => 'Unauthorized',
			'402' => 'Payment Required',
			'403' => 'Forbidden',
			'404' => 'Not Found',
			'405' => 'Method Not Allowed',
			'406' => 'Not Acceptable',
			'407' => 'Proxy Authentication Required',
			'408' => 'Request Timeout',
			'409' => 'Conflict',
			'410' => 'Gone',
			'411' => 'Length Required',
			'412' => 'Precondition Failed',
			'413' => 'Payload Too Large',
			'414' => 'URI Too Long',
			'415' => 'Unsupported Media Type',
			'416' => 'Range Not Satisfiable',
			'417' => 'Expectation Failed',
			'418' => 'I\'m a teapot',
			'420' => 'Enhance Your Calm',
			'421' => 'Misdirected Request',
			'422' => 'Unprocessable Entity',
			'423' => 'Locked',
			'424' => 'Failed Dependency',
			'425' => 'Unordered Collection',
			'426' => 'Upgrade Required',
			'428' => 'Precondition Required',
			'429' => 'Too Many Requests',
			'431' => 'Request Header Fields Too Large',
			'444' => 'No Response',
			'449' => 'Retry With',
			'451' => 'Unavailable For Legal Reasons',
			'500' => 'Internal Server Error',
			'501' => 'Not Implemented',
			'502' => 'Bad Gateway',
			'503' => 'Service Unavailable',
			'504' => 'Gateway Timeout',
			'505' => 'HTTP Version Not Supported',
			'506' => 'Variant Also Negotiates',
			'507' => 'Insufficient Storage',
			'508' => 'Loop Detected',
			'509' => 'Bandwidth Limit Exceeded',
			'510' => 'Not Extended',
			'511' => 'Network Authentication Required',
		);
		$statusMessage = $statusMessageList[strval($code)];
		if(!$statusMessage)
		{
			$statusMessage = 'OK';
		}

		Context::set('http_status_code', $code);
		Context::set('http_status_message', $statusMessage);
	}

}
