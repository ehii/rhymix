<?php
/* Copyright (C) NAVER <http://www.navercorp.com> */
/**
 * @class  memberAdminController
 * @author NAVER (developers@xpressengine.com)
 * member module of the admin controller class
 */
class MemberAdminController extends Member
{
	/**
	 * Initialization
	 * @return void
	 */
	function init()
	{
	}

	/**
	 * Add a user (Administrator)
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminInsert()
	{
		// if(Context::getRequestMethod() == "GET") return new Object(-1, "msg_invalid_request");
		// Extract the necessary information in advance
		$logged_info = Context::get('logged_info');
		if($logged_info->is_admin != 'Y' || !checkCSRF())
		{
			throw new Rhymix\Framework\Exceptions\InvalidRequest;
		}

		$args = Context::gets('member_srl','email_address','find_account_answer', 'allow_mailing','allow_message','is_admin','denied','status','description','group_srl_list','limit_date');
		$oMemberModel = getModel('member');
		$config = $oMemberModel->getMemberConfig();
		$getVars = array();
		if($config->signupForm)
		{
			foreach($config->signupForm as $formInfo)
			{
				if($formInfo->isUse || $formInfo->required || $formInfo->mustRequired)
				{
					$getVars[] = $formInfo->name;
				}
			}
		}
		foreach($getVars as $val)
		{
			$args->{$val} = Context::get($val);
			if ($val === 'phone_number')
			{
				$args->phone_country = preg_replace('/[^A-Z]/', '', Context::get('phone_country'));
			}
		}
		$member_srl = Context::get('member_srl');
		// Check if an original member exists having the member_srl
		$args->member_srl = $member_srl;
		if($args->member_srl)
		{
			// Get memebr profile
			$columnList = array('member_srl');
			$member_info = $oMemberModel->getMemberInfoByMemberSrl($args->member_srl, 0, $columnList);
			// If no original member exists, make a new one
			if($member_info->member_srl != $member_srl)
			{
				unset($args->member_srl);
			}

			if(Context::get('reset_password'))
			{
				$args->password = Context::get('reset_password');
			}
			else
			{
				unset($args->password);
			}
		}

		// Get existing extra vars
		if($args->member_srl)
		{
			$output = executeQuery('member.getMemberInfoByMemberSrl', ['member_srl' => $args->member_srl], ['extra_vars']);
			$extra_vars = ($output->data && $output->data->extra_vars) ? unserialize($output->data->extra_vars) : new stdClass;
			foreach(self::NOUSE_EXTRA_VARS as $key)
			{
				unset($extra_vars->$key);
			}
		}
		else
		{
			$extra_vars = new stdClass;
		}

		// Get list of extra vars
		$all_args = Context::getRequestVars();
		foreach($config->signupForm as $formInfo)
		{
			if (!$formInfo->isDefaultForm)
			{
				$extra_vars->{$formInfo->name} = $all_args->{$formInfo->name};
			}
		}
		foreach(self::ADMIN_EXTRA_VARS as $key)
		{
			$extra_vars->{$key} = escape(utf8_clean($all_args->{$key} ?? ''));
		}
		$args->extra_vars = serialize($extra_vars);

		// Normalize denied and status columns
		if (!in_array($args->status ?? '', self::STATUS_LIST))
		{
			$args->status = 'APPROVED';
		}
		$args->denied = ($args->status === 'APPROVED') ? 'N' : 'Y';

		// Delete invalid or past limit dates #1334
		if (!isset($args->limit_date))
		{
			$args->limit_date = '';
		}
		elseif ($args->limit_date < date('Ymd'))
		{
			$args->limit_date = '';
		}

		// remove whitespace
		foreach(['user_id', 'nick_name', 'email_address'] as $val)
		{
			if(isset($args->{$val}))
			{
				$args->{$val} = preg_replace('/[\pZ\pC]+/u', '', utf8_clean(html_entity_decode($args->{$val})));
			}
		}
		foreach(['user_name'] as $val)
		{
			if(isset($args->{$val}))
			{
				$args->{$val} = utf8_normalize_spaces(utf8_clean(html_entity_decode($args->{$val})));
			}
		}

		// 실제로 디비쿼리시 빈값이 없다면 해당 쿼리를 무시하고 업데이트 하기 때문에 메모의 내용이 삭제가 되지 않습니다.
		if(!isset($args->description))
		{
			$args->description = '';
		}

		$oMemberController = getController('member');
		// Execute insert or update depending on the value of member_srl
		if(!$args->member_srl)
		{
			$args->password = Context::get('password');
			$output = $oMemberController->insertMember($args);
			$msg_code = 'success_registed';
		}
		else
		{
			$output = $oMemberController->updateMember($args);
			$msg_code = 'success_updated';
		}

		if(!$output->toBool()) return $output;

		// Invalidate sessions if denied or limited
		if ($args->denied === 'Y' || $args->limited >= date('Ymd'))
		{
			$validity_info = Rhymix\Framework\Session::getValidityInfo($args->member_srl);
			$validity_info->invalid_before = time();
			Rhymix\Framework\Session::setValidityInfo($args->member_srl, $validity_info);
			executeQuery('member.deleteAutologin', ['member_srl' => $args->member_srl]);
		}

		// Invalidate auth mail if denied or limited
		if ($args->denied === 'Y' || $args->limited >= date('Ymd'))
		{
			executeQuery('member.deleteAuthMail', ['member_srl' => $args->member_srl]);
		}

		// Save Signature
		$signature = Context::get('signature');
		$oMemberController->putSignature($args->member_srl, $signature);
		if($config->member_allow_fileupload === 'Y')
		{
			getController('file')->setFilesValid($args->member_srl, 'sig');
		}

		$profile_image = Context::get('profile_image');
		if(is_uploaded_file($profile_image['tmp_name']))
		{
			$output = $oMemberController->insertProfileImage($args->member_srl, $profile_image['tmp_name']);
			if(!$output->toBool()) return $output;
		}

		$image_mark = Context::get('image_mark');
		if(is_uploaded_file($image_mark['tmp_name']))
		{
			$output = $oMemberController->insertImageMark($args->member_srl, $image_mark['tmp_name']);
			if(!$output->toBool()) return $output;
		}

		$image_name = Context::get('image_name');
		if (is_uploaded_file($image_name['tmp_name']))
		{
			$output = $oMemberController->insertImageName($args->member_srl, $image_name['tmp_name']);
			if(!$output->toBool()) return $output;
		}

		// Clear cache
		MemberController::clearMemberCache($args->member_srl);

		// Return result
		$this->add('member_srl', $args->member_srl);
		$this->setMessage($msg_code);
		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Delete a user (Administrator)
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminDelete()
	{
		// Separate all the values into DB entries and others
		$member_srl = Context::get('member_srl');

		$oMemberController = getController('member');
		$output = $oMemberController->deleteMember($member_srl);
		if(!$output->toBool()) return $output;

		$this->add('page',Context::get('page'));
		$this->setMessage("success_deleted");
	}


	public function procMemberAdminInsertDefaultConfig()
	{
		$args = Context::gets(
			'member_mid',
			'force_mid',
			'enable_join',
			'enable_join_key',
			'enable_confirm',
			'authmail_expires',
			'authmail_expires_unit',
			'password_strength',
			'password_hashing_algorithm',
			'password_hashing_work_factor',
			'password_hashing_auto_upgrade',
			'password_change_invalidate_other_sessions',
			'password_reset_method',
			'allow_nickname_change',
			'update_nickname_log',
			'nickname_symbols',
			'nickname_symbols_allowed_list',
			'allow_duplicate_nickname',
			'member_profile_view'
		);

		// Update member mid
		$config = MemberModel::getMemberConfig();
		if ($args->member_mid !== ($config->mid ?? null))
		{
			if (!preg_match('/^[a-z][a-z0-9_]+$/i', $args->member_mid))
			{
				return new BaseObject(-1, 'msg_limit_mid');
			}

			if (!empty($config->mid) && $this->checkMid($config->mid) == 1)
			{
				$module_info = \ModuleModel::getModuleInfoByMid($config->mid);
			}
			else
			{
				$module_info = null;
			}

			if ($module_info)
			{
				$module_info->mid = $args->member_mid;
				$output = ModuleController::getInstance()->updateModule($module_info);
			}
			else
			{
				$output = $this->createMid($args->member_mid, $config->skin ?: 'default', $config->mskin ?: 'default');
			}

			if ($output->toBool())
			{
				$args->mid = $args->member_mid;
				unset($args->member_mid);
			}
			else
			{
				return $output;
			}
		}

		$args->force_mid = ($args->force_mid === 'Y');

		// Update join key
		if ($args->enable_join === 'KEY')
		{
			$args->enable_join = 'N';
			$args->enable_join_key = escape(trim(utf8_normalize_spaces(rawurldecode($args->enable_join_key))));
		}
		else
		{
			$args->enable_join_key = null;
		}

		$args->authmail_expires = max(0, intval($args->authmail_expires));
		if(!$args->authmail_expires)
		{
			$args->authmail_expires = 1;
		}
		$args->authmail_expires_unit = intval($args->authmail_expires_unit);
		if(!in_array($args->authmail_expires_unit, [1, 60, 3600, 86400]))
		{
			$args->authmail_expires_unit = 86400;
		}

		if(!array_key_exists($args->password_hashing_algorithm, Rhymix\Framework\Password::getSupportedAlgorithms()))
		{
			$args->password_hashing_algorithm = 'md5';
		}

		$args->password_hashing_work_factor = intval($args->password_hashing_work_factor, 10);
		if($args->password_hashing_work_factor < 4)
		{
			$args->password_hashing_work_factor = 4;
		}
		if($args->password_hashing_work_factor > 16)
		{
			$args->password_hashing_work_factor = 16;
		}
		if($args->password_hashing_auto_upgrade != 'Y')
		{
			$args->password_hashing_auto_upgrade = 'N';
		}
		$args->password_reset_method = intval($args->password_reset_method);
		if(!in_array($args->nickname_symbols, ['Y', 'N', 'LIST']))
		{
			$args->nickname_symbols = 'Y';
		}
		$args->nickname_symbols_allowed_list = utf8_trim($args->nickname_symbols_allowed_list);

		$oModuleController = getController('module');
		$output = $oModuleController->updateModuleConfig('member', $args);

		// default setting end
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminDefaultConfig');
		$this->setRedirectUrl($returnUrl);
	}

	public function procMemberAdminInsertFeaturesConfig()
	{
		$config = new stdClass;
		$config->features = array();

		$args = Context::gets(
			'scrapped_documents',
			'saved_documents',
			'my_documents',
			'my_comments',
			'active_logins',
			'nickname_log'
		);
		foreach ($args as $key => $value)
		{
			$config->features[$key] = toBool($value);
		}

		$oModuleController = getController('module');
		$output = $oModuleController->updateModuleConfig('member', $config);

		// default setting end
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminFeaturesConfig');
		$this->setRedirectUrl($returnUrl);
	}

	public function procMemberAdminInsertAgreementsConfig()
	{
		$config = new stdClass;
		$config->agreements = array();
		$config->agreement = null;

		$args = Context::getRequestVars();
		for ($i = 1; $i < 20; $i++)
		{
			if (isset($args->{'agreement_' . $i . '_type'}))
			{
				$agreement = new stdClass;
				$agreement->title = escape(utf8_trim($args->{'agreement_' . $i . '_title'}));
				$agreement->content = $args->{'agreement_' . $i . '_content'};
				$agreement->use_editor = $args->use_editor;
				getModel('editor')->converter($agreement, 'document');
				$agreement->type = $args->{'agreement_' . $i . '_type'};
				if (!in_array($agreement->type, array('required', 'optional', 'disabled')))
				{
					$agreement->type = 'disabled';
				}
				$config->agreements[$i] = $agreement;
			}
		}

		$oModuleController = getController('module');
		$output = $oModuleController->updateModuleConfig('member', $config);
		if (!$output->toBool())
		{
			return $output;
		}

		// Delete old agreement files.
		foreach (Context::loadLangSupported() as $key => $val)
		{
			$agreement_file = RX_BASEDIR . 'files/member_extra_info/agreement_' . $key . '.txt';
			if (Rhymix\Framework\Storage::exists($agreement_file))
			{
				Rhymix\Framework\Storage::delete($agreement_file);
			}
		}

		// default setting end
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminAgreementsConfig');
		$this->setRedirectUrl($returnUrl);
	}

	public function procMemberAdminInsertSignupConfig()
	{
		$oMemberModel = getModel('member');
		$config = $oMemberModel->getMemberConfig();

		$oModuleController = getController('module');

		$args = Context::gets(
			'limit_day',
			'limit_day_description',
			'emailhost_check',
			'special_phone_number', 'special_phone_code', 'max_auth_sms_count', 'max_auth_sms_count_time', 'redirect_url',
			'phone_number_default_country', 'phone_number_hide_country', 'phone_number_allow_duplicate', 'phone_number_verify_by_sms',
			'profile_image_max_width', 'profile_image_max_height', 'profile_image_max_filesize', 'profile_image_force_ratio',
			'image_name_max_width', 'image_name_max_height', 'image_name_max_filesize',
			'image_mark_max_width', 'image_mark_max_height', 'image_mark_max_filesize',
			'signature_editor_skin', 'sel_editor_colorset', 'signature_max_height', 'signature_html', 'signature_html_retroact', 'member_allow_fileupload', 'member_max_filesize'
		);

		$list_order = Context::get('list_order');
		$usable_list = Context::get('usable_list');
		$all_args = Context::getRequestVars();

		$args->limit_day = (int)$args->limit_day;
		$args->limit_day_description = escape(trim(utf8_clean($args->limit_day_description)));
		if($args->emailhost_check != 'allowed' && $args->emailhost_check != 'prohibited') $args->emailhost_check == 'allowed';

		$args->special_phone_number = preg_replace('/[^0-9]/', '', $args->special_phone_number);
		$args->special_phone_code = preg_replace('/[^0-9]/', '', $args->special_phone_code);
		if ($args->special_phone_code !== '' && strlen($args->special_phone_code) !== 6)
		{
			return new BaseObject('-1', 'msg_special_code_incorrect_format');
		}
		$args->max_auth_sms_count = max(0, intval($args->max_auth_sms_count));
		$args->max_auth_sms_count_time = max(0, intval($args->max_auth_sms_count_time));
		$args->redirect_mid = '';
		$args->redirect_url = utf8_trim($args->redirect_url);

		$args->phone_number_default_country = preg_replace('/[^A-Z]/', '', $args->phone_number_default_country);
		if (!array_key_exists($args->phone_number_default_country, Rhymix\Framework\i18n::listCountries()))
		{
			return new BaseObject('-1', 'msg_need_default_country');
		}
		$args->phone_number_hide_country = $args->phone_number_hide_country == 'Y' ? 'Y' : 'N';
		$args->phone_number_allow_duplicate = $args->phone_number_allow_duplicate == 'Y' ? 'Y' : 'N';
		$args->phone_number_verify_by_sms = $args->phone_number_verify_by_sms == 'Y' ? 'Y' : 'N';
		if ($args->phone_number_hide_country === 'Y' && !$args->phone_number_default_country)
		{
			return new BaseObject('-1', 'msg_need_default_country');
		}

		// set default
		$all_args->is_nick_name_public = 'Y';

		// signupForm
		global $lang;
		$signupForm = array();
		$items = array(
			'user_id', 'password', 'user_name', 'nick_name', 'email_address', 'phone_number', 'homepage', 'blog', 'birthday', 'signature',
			'profile_image', 'profile_image_max_width', 'profile_image_max_height', 'profile_image_max_filesize',
			'image_name', 'image_name_max_width', 'image_name_max_height', 'image_name_max_filesize',
			'image_mark', 'image_mark_max_width', 'image_mark_max_height', 'image_mark_max_filesize',
		);
		$mustRequireds = array('email_address', 'nick_name', 'password');
		$extendItems = $oMemberModel->getJoinFormList();

		foreach($list_order as $key)
		{
			$signupItem = new stdClass();
			$signupItem->isIdentifier = ($key == $config->identifier || in_array($key, $config->identifiers ?: []));
			$signupItem->isDefaultForm = in_array($key, $items);
			$signupItem->name = $key;
			if (isset($all_args->{$key . '_title_edit'}) && $all_args->{$key . '_title_edit'} && $all_args->{$key . '_title_edit'} !== $lang->{$key})
			{
				$signupItem->isCustomTitle = true;
				$signupItem->title = $all_args->{$key . '_title_edit'};
				if (!preg_match('/^\\$user_lang->[a-z0-9_]+$/i', $signupItem->title))
				{
					$signupItem->title = escape($signupItem->title);
				}
			}
			else
			{
				$signupItem->isCustomTitle = false;
				$signupItem->title = (!in_array($key, $items)) ? $key : $lang->{$key};
			}
			$signupItem->mustRequired = in_array($key, $mustRequireds);
			$signupItem->imageType = (strpos($key, 'image') !== false);
			$signupItem->required = ($all_args->{$key} == 'required') || $signupItem->mustRequired;
			$signupItem->isUse = in_array($key, $usable_list) || $signupItem->required;
			$signupItem->isPublic = ($all_args->{'is_'.$key.'_public'} == 'Y' && $signupItem->isUse) ? 'Y' : 'N';

			if(in_array($key, ['signature', 'profile_image', 'image_name', 'image_mark']))
			{
				$args->$key = $signupItem->isPublic;
			}
			if($signupItem->imageType)
			{
				$signupItem->max_width = $all_args->{$key.'_max_width'};
				$signupItem->max_height = $all_args->{$key.'_max_height'};
				$signupItem->max_filesize = $all_args->{$key.'_max_filesize'};
				$signupItem->force_ratio = $all_args->{$key.'_force_ratio'} === 'N' ? 'N' : 'Y';
			}

			// set extends form
			if(!$signupItem->isDefaultForm)
			{
				$extendItem = $extendItems[$all_args->{$key.'_member_join_form_srl'}];
				$signupItem->type = $extendItem->column_type;
				$signupItem->member_join_form_srl = $extendItem->member_join_form_srl;
				$signupItem->title = $extendItem->column_title;
				$signupItem->description = $extendItem->description;

				// check usable value change, required/option
				if($signupItem->isUse != ($extendItem->is_active == 'Y') || $signupItem->required != ($extendItem->required == 'Y'))
				{
					unset($update_args);
					$update_args = new stdClass;
					$update_args->member_join_form_srl = $extendItem->member_join_form_srl;
					$update_args->is_active = $signupItem->isUse?'Y':'N';
					$update_args->required = $signupItem->required?'Y':'N';

					$update_output = executeQuery('member.updateJoinForm', $update_args);
				}

				unset($extendItem);
			}
			$signupForm[$key] = $signupItem;
		}
		$args->signupForm = array_values($signupForm);
		$oModuleController->updateModuleConfig('member', $args);

		// default setting end
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminSignUpConfig');
		$this->setRedirectUrl($returnUrl);
	}

	public function procMemberAdminInsertLoginConfig()
	{
		$oMemberModel = getModel('member');
		$config = $oMemberModel->getMemberConfig();
		$oModuleController = getController('module');

		$args = Context::gets(
			'identifiers',
			'change_password_date',
			'enable_login_fail_report',
			'max_error_count',
			'max_error_count_time',
			'login_invalidate_other_sessions',
			'after_login_url',
			'after_logout_url'
		);

		if(!count($args->identifiers))
		{
			return new BaseObject(-1, 'msg_need_identifier');
		}
		$enabled_list = array();
		foreach($config->signupForm as $signupItem)
		{
			if($signupItem->isUse)
			{
				$enabled_list[] = $signupItem->name;
			}
			if(in_array($signupItem->name, $args->identifiers))
			{
				$signupItem->isIdentifier = true;
			}
			else
			{
				$signupItem->isIdentifier = false;
			}
		}
		if(!count(array_intersect($args->identifiers, $enabled_list)))
		{
			return new BaseObject(-1, 'msg_need_enabled_identifier');
		}
		if (in_array('email_address', $args->identifiers) && $config->enable_confirm === 'Y')
		{
			$args->identifier = 'email_address';
		}
		else
		{
			$args->identifier = array_first($args->identifiers) === 'email_address' ? 'email_address' : 'user_id';
		}
		$args->signupForm = $config->signupForm;

		if(!$args->change_password_date)
		{
			$args->change_password_date = 0;
		}

		if(!trim(strip_tags($args->after_login_url)))
		{
			$args->after_login_url = NULL;
		}
		if(!trim(strip_tags($args->after_logout_url)))
		{
			$args->after_logout_url = NULL;
		}

		$output = $oModuleController->updateModuleConfig('member', $args);

		// default setting end
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminLoginConfig');
		$this->setRedirectUrl($returnUrl);
	}

	public function procMemberAdminInsertDesignConfig()
	{
		$oModuleController = getController('module');

		$args = Context::gets(
			'layout_srl',
			'skin',
			'colorset',
			'mlayout_srl',
			'mskin'
		);

		$args->layout_srl = $args->layout_srl ? $args->layout_srl : NULL;
		if(!$args->skin)
		{
			$args->skin = 'default';
		}
		if(!$args->colorset)
		{
			$args->colorset = 'white';
		}

		$args->mlayout_srl = $args->mlayout_srl ? $args->mlayout_srl : NULL;
		if(!$args->mskin)
		{
			$args->mskin = 'default';
		}

		// Update member module config
		$output = $oModuleController->updateModuleConfig('member', $args);
		if (!$output->toBool())
		{
			return $output;
		}

		// Sync member mid info with module config
		$config = MemberModel::getMemberConfig();
		if ($config->mid)
		{
			$module_info = ModuleModel::getModuleInfoByMid($config->mid);
			if ($module_info->module === 'member')
			{
				$module_info->layout_srl = $args->layout_srl ?? -1;
				$module_info->mlayout_srl = $args->mlayout_srl ?? -1;
				$module_info->skin = $args->skin;
				$module_info->mskin = $args->mskin;
				$module_info->is_skin_fix = str_starts_with($module_info->skin, '/') ? 'N' : 'Y';
				$module_info->is_mskin_fix = str_starts_with($module_info->mskin, '/') ? 'N' : 'Y';
				$output = ModuleController::getInstance()->updateModule($module_info);
				if (!$output->toBool())
				{
					return $output;
				}
			}
		}

		// default setting end
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminDesignConfig');
		$this->setRedirectUrl($returnUrl);
	}

	public static function createSignupForm($config)
	{
		// Maintain backward compatibility with inconsistent use of the first parameter of this method.
		if (is_object($config))
		{
			$identifier = $config->identifier ?? 'user_id';
		}
		else
		{
			$identifier = strval($config) ?: 'user_id';
			$config = new \stdClass;
		}

		// Get join form list which is additionally set
		$extendItems = MemberModel::getJoinFormList();

		$items = array('user_id', 'email_address', 'phone_number', 'password', 'user_name', 'nick_name', 'homepage', 'blog', 'birthday', 'signature', 'profile_image', 'image_name', 'image_mark');
		$mustRequireds = array('email_address', 'nick_name', 'password');
		$orgRequireds = array('email_address', 'password', 'user_id', 'nick_name', 'user_name');
		$orgUse = array('email_address', 'password', 'user_id', 'nick_name', 'user_name', 'homepage', 'blog', 'birthday');
		$list_order = array();

		foreach($items as $key)
		{
			$signupItem = new stdClass;
			$signupItem->isDefaultForm = true;
			$signupItem->name = $key;
			$signupItem->title = $key;
			$signupItem->mustRequired = in_array($key, $mustRequireds);
			$signupItem->imageType = (strpos($key, 'image') !== false);
			$signupItem->required = in_array($key, $orgRequireds);
			$signupItem->isUse = ($config->{$key} == 'Y') || in_array($key, $orgUse);
			$signupItem->isPublic = ($signupItem->isUse) ? 'Y' : 'N';
			if(in_array($key, array('find_account_question', 'password', 'email_address', 'phone_number')))
			{
				$signupItem->isPublic = 'N';
			}
			$signupItem->isIdentifier = ($key == $identifier);
			if ($signupItem->imageType){
				$signupItem->max_width = $config->{$key.'_max_width'};
				$signupItem->max_height = $config->{$key.'_max_height'};
			}
			if($signupItem->isIdentifier)
				array_unshift($list_order, $signupItem);
			else
				$list_order[] = $signupItem;
		}
		if(is_array($extendItems))
		{
			foreach($extendItems as $form_srl=>$item_info)
			{
				unset($signupItem);
				$signupItem = new stdClass;
				$signupItem->name = $item_info->column_name;
				$signupItem->title = $item_info->column_title;
				$signupItem->type = $item_info->column_type;
				$signupItem->member_join_form_srl = $form_srl;
				$signupItem->mustRequired = in_array($key, $mustRequireds);
				$signupItem->required = ($item_info->required == 'Y');
				$signupItem->isUse = ($item_info->is_active == 'Y');
				$signupItem->isPublic = ($signupItem->isUse) ? 'Y' : 'N';
				$signupItem->description = $item_info->description;
				if($signupItem->imageType)
				{
					$signupItem->max_width = $config->{$key.'_max_width'};
					$signupItem->max_height = $config->{$key.'_max_height'};
				}
				$list_order[] = $signupItem;
			}
		}

		return $list_order;
	}

	/**
	 * Create ruleset file of signup
	 * @param object $signupForm (user define signup form)
	 * @return void
	 */
	function _createSignupRuleset($signupForm)
	{

	}

	/**
	 * Create ruleset file of login
	 * @param string $identifier (login identifier)
	 * @return void
	 */
	function _createLoginRuleset($identifier)
	{

	}

	/**
	 * Create ruleset file of find account
	 * @param string $identifier (login identifier)
	 * @return void
	 */
	function _createFindAccountByQuestion($identifier)
	{

	}

	/**
	 * Add a user group
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminInsertGroup()
	{
		$args = Context::gets('title','description','is_default','image_mark');
		$output = $this->insertGroup($args);
		if(!$output->toBool()) return $output;

		$this->add('group_srl', $output->get('group_srl'));
		$this->add('page',Context::get('page'));
		$this->setMessage('success_registed');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Update user group information
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminUpdateGroup()
	{
		$args = Context::gets('group_srl','title','description','is_default','image_mark');
		$output = $this->updateGroup($args);
		if(!$output->toBool()) return $output;

		$this->add('group_srl','');
		$this->add('page',Context::get('page'));
		$this->setMessage('success_updated');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Update user group information
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminDeleteGroup()
	{
		$group_srl = Context::get('group_srl');

		$output = $this->deleteGroup($group_srl);
		if(!$output->toBool()) return $output;

		$this->add('group_srl','');
		$this->add('page',Context::get('page'));
		$this->setMessage('success_deleted');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Add a join form
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminInsertJoinForm()
	{
		$args = new stdClass();
		$args->member_join_form_srl = Context::get('member_join_form_srl');

		$args->column_type = Context::get('column_type');
		$args->column_name = strtolower(Context::get('column_id'));
		$args->column_title = Context::get('column_title');
		$args->default_value = trim(utf8_clean(Context::get('default_value')));
		$args->options = trim(utf8_clean(Context::get('options')));
		if ($args->options !== '')
		{
			$args->options = array_map('trim', explode("\n", $args->options));
			$args->options = json_encode($args->options, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
		}
		else
		{
			$args->options = null;
		}

		$args->required = Context::get('required');
		if (!in_array(strtoupper($args->required), array('Y','N')))
		{
			$args->required = 'N';
		}

		$args->is_active = (isset($args->required));
		$args->description = Context::get('description') ? Context::get('description') : '';

		// Check ID duplicated
		if (Context::isReservedWord($args->column_name))
		{
			throw new Rhymix\Framework\Exception('msg_column_id_not_available');
		}
		$oMemberModel = getModel('member');
		$config = $oMemberModel->getMemberConfig();
		foreach($config->signupForm as $item)
		{
			if($item->name == $args->column_name)
			{
				if($args->member_join_form_srl && $args->member_join_form_srl == $item->member_join_form_srl) continue;
				throw new Rhymix\Framework\Exception('msg_column_id_not_available');
			}
		}
		// Fix if member_join_form_srl exists. Add if not exists.
		$isInsert = false;
		if(!$args->member_join_form_srl)
		{
			$isInsert = true;
			$args->list_order = $args->member_join_form_srl = getNextSequence();
			$output = executeQuery('member.insertJoinForm', $args);
		}
		else
		{
			$output = executeQuery('member.updateJoinForm', $args);
		}

		if(!$output->toBool()) return $output;

		// memberConfig update
		$signupItem = new stdClass();
		$signupItem->name = $args->column_name;
		$signupItem->title = $args->column_title;
		$signupItem->type = $args->column_type;
		$signupItem->member_join_form_srl = $args->member_join_form_srl;
		$signupItem->required = ($args->required == 'Y');
		$signupItem->isUse = ($args->is_active == 'Y');
		$signupItem->description = $args->description;
		$signupItem->isPublic = 'Y';

		$oMemberModel = getModel('member');
		$config = $oMemberModel->getMemberConfig();

		if($isInsert)
		{
			$config->signupForm[] = $signupItem;
		}
		else
		{
			foreach($config->signupForm as $key=>$val)
			{
				if($val->member_join_form_srl == $signupItem->member_join_form_srl)
				{
					$config->signupForm[$key] = $signupItem;
				}
			}
		}
		$oModuleController = getController('module');
		$output = $oModuleController->updateModuleConfig('member', $config);

		$this->setMessage('success_registed');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminJoinFormList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Delete a join form
	 * @return void
	 */
	function procMemberAdminDeleteJoinForm()
	{
		$member_join_form_srl = Context::get('member_join_form_srl');
		$this->deleteJoinForm($member_join_form_srl);

		$oMemberModel = getModel('member');
		$config = $oMemberModel->getMemberConfig();

		foreach($config->signupForm as $key=>$val)
		{
			if($val->member_join_form_srl == $member_join_form_srl)
			{
				unset($config->signupForm[$key]);
				break;
			}
		}
		$oModuleController = getController('module');
		$output = $oModuleController->updateModuleConfig('member', $config);
	}

	/**
	 * Move up/down the member join form and modify it
	 * @deprecated
	 * @return void
	 */
	function procMemberAdminUpdateJoinForm()
	{
		$member_join_form_srl = Context::get('member_join_form_srl');
		$mode = Context::get('mode');

		switch($mode)
		{
			case 'up' :
				$output = $this->moveJoinFormUp($member_join_form_srl);
				$msg_code = 'success_moved';
				break;
			case 'down' :
				$output = $this->moveJoinFormDown($member_join_form_srl);
				$msg_code = 'success_moved';
				break;
			case 'delete' :
				$output = $this->deleteJoinForm($member_join_form_srl);
				$msg_code = 'success_deleted';
				break;
			case 'update' :
				break;
		}
		if(!$output->toBool()) return $output;

		$this->setMessage($msg_code);
	}

	/**
	 * selected member manager layer in dispAdminList
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminSelectedMemberManage()
	{
		$var = Context::getRequestVars();
		$groups = $var->groups ?? [];
		$members = $var->member_srls ?? [];

		$oDB = DB::getInstance();
		$oDB->begin();

		$oMemberController = getController('member');
		foreach($members as $key=>$member_srl)
		{
			$args = new stdClass();
			$args->member_srl = $member_srl;
			switch($var->type)
			{
				case 'modify':
					{
						if(count($groups) > 0)
						{
							// One of its members to delete all the group
							$output = executeQuery('member.deleteMemberGroupMember', $args);
							if(!$output->toBool())
							{
								$oDB->rollback();
								return $output;
							}
							// Enter one of the loop a
							foreach($groups as $group_srl)
							{
								$output = $oMemberController->addMemberToGroup($args->member_srl,$group_srl);
								if(!$output->toBool())
								{
									$oDB->rollback();
									return $output;
								}
							}
						}
						if($var->denied)
						{
							$args->denied = $var->denied;
							$args->status = $var->denied === 'Y' ? 'DENIED' : 'APPROVED';
							$output = executeQuery('member.updateMemberDeniedInfo', $args);
							if(!$output->toBool())
							{
								$oDB->rollback();
								return $output;
							}
						}
						$this->setMessage('success_updated');
						break;
					}
				case 'delete':
					{
						$oMemberController->memberInfo = null;
						$output = $oMemberController->deleteMember($member_srl);
						if(!$output->toBool())
						{
							$oDB->rollback();
							return $output;
						}
						$this->setMessage('success_deleted');
					}
			}
			MemberController::clearMemberCache($args->member_srl);
		}

		$message = $var->message;
		// Send a message
		if($message)
		{
			$oCommunicationController = getController('communication');

			$logged_info = Context::get('logged_info');
			$title = cut_str($message,10,'...');
			$sender_member_srl = $logged_info->member_srl;

			foreach($members as $member_srl)
			{
				$oCommunicationController->sendMessage($sender_member_srl, $member_srl, $title, $message, true, null, false);
			}
		}

		$oDB->commit();

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Delete the selected members
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminDeleteMembers()
	{
		$target_member_srls = Context::get('target_member_srls');
		if(!$target_member_srls) throw new Rhymix\Framework\Exceptions\InvalidRequest;
		$member_srls = explode(',', $target_member_srls);
		$oMemberController = getController('member');

		foreach($member_srls as $member)
		{
			$output = $oMemberController->deleteMember($member);
			if(!$output->toBool())
			{
				$this->setMessage('failed_deleted');
				return $output;
			}
		}

		$this->setMessage('success_deleted');
	}

	/**
	 * Update a group of selected memebrs
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminUpdateMembersGroup()
	{
		$member_srl = Context::get('member_srl');
		if(!$member_srl) throw new Rhymix\Framework\Exceptions\InvalidRequest;
		$member_srls = explode(',',$member_srl);

		$group_srl = Context::get('group_srls');
		if(!is_array($group_srl)) $group_srls = explode('|@|', $group_srl);
		else $group_srls = $group_srl;

		$oDB = DB::getInstance();
		$oDB->begin();

		// Delete a group of selected members
		$args = new stdClass;
		$args->member_srl = $member_srl;
		$output = executeQuery('member.deleteMembersGroup', $args);
		if(!$output->toBool())
		{
			$oDB->rollback();
			return $output;
		}
		// Add to a selected group
		$group_count = count($group_srls);
		$member_count = count($member_srls);
		for($j=0;$j<$group_count;$j++)
		{
			$group_srl = (int)trim($group_srls[$j]);
			if(!$group_srl) continue;
			for($i=0;$i<$member_count;$i++)
			{
				$member_srl = (int)trim($member_srls[$i]);
				if(!$member_srl) continue;

				$args = new stdClass;
				$args->member_srl = $member_srl;
				$args->group_srl = $group_srl;

				$output = executeQuery('member.addMemberToGroup', $args);
				if(!$output->toBool())
				{
					$oDB->rollback();
					return $output;
				}
			}
		}
		$oDB->commit();

		$this->_deleteMemberGroupCache();

		$this->setMessage('success_updated');

		if(!in_array(Context::getRequestMethod(),array('XMLRPC','JSON')))
		{
			global $lang;
			htmlHeader();
			alertScript($lang->success_updated);
			reload(true);
			closePopupScript();
			htmlFooter();
			Context::close();
			exit;
		}
	}

	/**
	 * Add a denied ID
	 * @return void
	 */
	function procMemberAdminInsertDeniedID()
	{
		$user_ids = Context::get('user_id');

		$user_ids = explode(',',$user_ids);
		$success_ids = array();

		foreach($user_ids as $val)
		{
			$val = trim($val);
			if(!$val) continue;

			$output = $this->insertDeniedID($val, '');
			if($output->toBool()) $success_ids[] = $val;
		}

		$this->add('user_ids', implode(',',$success_ids));

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminDeniedIDList');
		$this->setRedirectUrl($returnUrl);
	}

	/**
	 * Add allowed or denied email hostnames
	 * @return void
	 */
	function procMemberAdminUpdateManagedEmailHosts()
	{
		$email_hosts = Context::get('email_hosts');

		$mode = Context::get('mode');
		$mode = $mode ? $mode : 'insert';

		if($mode == 'delete')
		{
			$output = $this->deleteManagedEmailHost($email_hosts);
			if(!$output->toBool())
			{
				return $output;
			}
			$msg_code = 'success_deleted';
			$this->setMessage($msg_code);
		}
		else
		{
			$email_hosts = preg_replace('/([^a-z0-9\.\-\_\n]*)/i','',$email_hosts);
			$email_hosts = array_unique(explode("\n",$email_hosts."\n"));
			$success_email_hosts = array();
			foreach($email_hosts as $val)
			{
				$val = trim($val);
				if(!$val) continue;
				$output = $this->insertManagedEmailHost($val, '');
				if($output->toBool()) $success_email_hosts[] = $val;
			}

			$this->add('email_hosts', implode("\n",$success_email_hosts));
		}
	}

	/**
	 * Add a denied nick name
	 * @return void
	 */
	function procMemberAdminUpdateDeniedNickName()
	{
		$nick_name = Context::get('nick_name');

		$mode = Context::get('mode');
		$mode = $mode ? $mode : 'insert';

		if($mode == 'delete')
		{
			$output = $this->deleteDeniedNickName($nick_name);
			if(!$output->toBool())
			{
				return $output;
			}
			$msg_code = 'success_deleted';
			$this->setMessage($msg_code);
		}
		else
		{
			$nick_names = explode(',',$nick_name);
			$success_nick_names = array();

			foreach($nick_names as $val)
			{
				$val = trim($val);
				if(!$val) continue;

				$output = $this->insertDeniedNickName($val, '');
				if($output->toBool()) $success_nick_names[] = $val;
			}

			$this->add('nick_names', implode(',',$success_nick_names));
		}
	}

	/**
	 * Update denied ID
	 * @return void|Object (void : success, Object : fail)
	 */
	function procMemberAdminUpdateDeniedID()
	{
		$user_id = Context::get('user_id');
		$mode = Context::get('mode');

		switch($mode)
		{
			case 'delete' :
				$output = $this->deleteDeniedID($user_id);
				if(!$output->toBool()) return $output;
				$msg_code = 'success_deleted';
				break;
		}

		$this->add('page',Context::get('page'));
		$this->setMessage($msg_code);
	}

	/**
	 * Add an administrator
	 * @param object $args
	 * @return object (info of added member)
	 */
	function insertAdmin($args)
	{
		// Assign an administrator
		$args->is_admin = 'Y';
		// Get admin group and set
		$oMemberModel = getModel('member');
		$admin_group = $oMemberModel->getAdminGroup();
		$args->group_srl_list = $admin_group->group_srl;

		$oMemberController = getController('member');
		return $oMemberController->insertMember($args);
	}

	/**
	 * Change the group values of member
	 * @param int $source_group_srl
	 * @param int $target_group_srl
	 * @return Object
	 */
	function changeGroup($source_group_srl, $target_group_srl)
	{
		$args = new stdClass;
		$args->source_group_srl = $source_group_srl;
		$args->target_group_srl = $target_group_srl;

		$output = executeQuery('member.changeGroup', $args);
		$this->_deleteMemberGroupCache();

		return $output;
	}

	/**
	 * Insert a group
	 * @param object $args
	 * @return Object
	 */
	function insertGroup($args)
	{
		// Call trigger (before)
		$trigger_output = ModuleHandler::triggerCall('member.insertGroup', 'before', $args);
		if(!$trigger_output->toBool())
		{
			return $trigger_output;
		}

		$oDB = DB::getInstance();
		$oDB->begin();

		// Check the value of is_default.
		$args->is_default = $args->is_default ?? 'N';
		if($args->is_default === 'Y')
		{
			$output = executeQuery('member.updateGroupDefaultClear', $args);
			if(!$output->toBool()) return $output;
		}
		$args->group_srl = !empty($args->group_srl) ? $args->group_srl : getNextSequence();
		$args->list_order = $args->list_order ?? $args->group_srl;
		$args->title = escape($args->title, false, true);
		$args->description = escape($args->description);

		$output = executeQuery('member.insertGroup', $args);
		if ($output->toBool())
		{
			$oDB->commit();
		}
		else
		{
			$oDB->rollback();
		}

		$this->_deleteMemberGroupCache();

		// Call trigger (after)
		ModuleHandler::triggerCall('member.insertGroup', 'after', $args);

		$output->add('group_srl', $args->group_srl);
		return $output;
	}

	/**
	 * Modify Group Information
	 * @param object $args
	 * @return Object
	 */
	function updateGroup($args)
	{
		if(!$args->group_srl)
		{
			throw new Rhymix\Framework\Exceptions\TargetNotFound;
		}

		// Call trigger (before)
		$trigger_output = ModuleHandler::triggerCall('member.updateGroup', 'before', $args);
		if(!$trigger_output->toBool())
		{
			return $trigger_output;
		}

		$oDB = DB::getInstance();
		$oDB->begin();

		// Check the value of is_default.
		$args->is_default = $args->is_default ?? 'N';
		if($args->is_default === 'Y')
		{
			$output = executeQuery('member.updateGroupDefaultClear', $args);
			if(!$output->toBool()) return $output;
		}
		$args->title = isset($args->title) ? escape($args->title, false, true) : null;
		$args->description = isset($args->description) ? escape($args->description) : null;

		$output = executeQuery('member.updateGroup', $args);
		if ($output->toBool())
		{
			$oDB->commit();
		}
		else
		{
			$oDB->rollback();
		}

		$this->_deleteMemberGroupCache();

		// Call trigger (after)
		ModuleHandler::triggerCall('member.updateGroup', 'after', $args);

		return $output;
	}

	/**
	 * Delete a Group
	 * @param int $group_srl
	 * @return Object
	 */
	function deleteGroup($group_srl)
	{
		// Create a member model object
		$oMemberModel = getModel('member');

		// Check the group_srl (If is_default == 'Y', it cannot be deleted)
		$columnList = array('group_srl', 'is_default');
		$group_info = $oMemberModel->getGroup($group_srl, $columnList);

		if(!$group_info) throw new Rhymix\Framework\Exceptions\TargetNotFound;
		if($group_info->is_default == 'Y') throw new Rhymix\Framework\Exception('msg_not_delete_default');

		// Call trigger (before)
		$trigger_output = ModuleHandler::triggerCall('member.deleteGroup', 'before', $group_info);
		if(!$trigger_output->toBool())
		{
			return $trigger_output;
		}

		// Get groups where is_default == 'Y'
		$default_group = $oMemberModel->getDefaultGroup();
		$default_group_srl = $default_group->group_srl;

		// Change to default_group_srl
		$this->changeGroup($group_srl, $default_group_srl);

		$args = new stdClass;
		$args->group_srl = $group_srl;
		$output = executeQuery('member.deleteGroup', $args);
		$this->_deleteMemberGroupCache();
		if (!$output->toBool())
		{
			return $output;
		}

		// Call trigger (after)
		ModuleHandler::triggerCall('member.deleteGroup', 'after', $group_info);

		return $output;
	}

	/**
	 * Set group config
	 * @return void
	 */
	public function procMemberAdminGroupConfig()
	{
		$vars = Context::getRequestVars();

		$oMemberModel = getModel('member');
		$oModuleController = getController('module');

		// group image mark option
		$config = $oMemberModel->getMemberConfig();
		$config->group_image_mark = $vars->group_image_mark;
		$output = $oModuleController->updateModuleConfig('member', $config);

		$defaultGroup = $oMemberModel->getDefaultGroup(0);
		$defaultGroupSrl = $defaultGroup->group_srl;
		$group_srls = $vars->group_srls;
		foreach($group_srls as $order=>$group_srl)
		{
			$isInsert = false;
			$update_args = new stdClass();
			$update_args->title = $vars->group_titles[$order];
			$update_args->description = $vars->descriptions[$order];
			$update_args->image_mark = $vars->image_marks[$order];
			$update_args->list_order = $order + 1;

			if(!$update_args->title) continue;

			if(is_numeric($group_srl)) {
				$update_args->group_srl = $group_srl;
				$output = $this->updateGroup($update_args);
			}
			else {
				$update_args->group_srl = getNextSequence();
				$output = $this->insertGroup($update_args);
			}

			if($vars->defaultGroup == $group_srl) {
				$defaultGroupSrl = $update_args->group_srl;
			}
		}

		//set default group
		$default_args = $oMemberModel->getGroup($defaultGroupSrl);
		$default_args->is_default = 'Y';
		$default_args->group_srl = $defaultGroupSrl;
		$output = $this->updateGroup($default_args);

		$this->setMessage(lang('success_updated').' ('.lang('msg_insert_group_name_detail').')');

		$returnUrl = Context::get('success_return_url') ? Context::get('success_return_url') : getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList');
		$this->setRedirectUrl($returnUrl);
	}


	/**
	 * Set group order
	 * @return void
	 */
	function procMemberAdminUpdateGroupOrder()
	{
		$vars = Context::getRequestVars();

		foreach($vars->group_srls as $key => $val)
		{
			$args = new stdClass;
			$args->group_srl = $val;
			$args->list_order = $key + 1;
			executeQuery('member.updateMemberGroupListOrder', $args);
		}

		$this->_deleteMemberGroupCache();

		$this->setRedirectUrl(getNotEncodedUrl('', 'module', 'admin', 'act', 'dispMemberAdminGroupList'));
	}

	/**
	 * Delete cached group data
	 * @return void
	*/
	function _deleteMemberGroupCache()
	{
		//remove from cache
		Rhymix\Framework\Cache::clearGroup('member');
	}

	/**
	 * Register denied ID
	 * @param string $user_id
	 * @param string $description
	 * @return Object
	 */
	function insertDeniedID($user_id, $description = '')
	{
		$args = new stdClass();
		$args->user_id = $user_id;
		$args->description = $description;
		$args->list_order = -1*getNextSequence();

		return executeQuery('member.insertDeniedID', $args);
	}

	function insertDeniedNickName($nick_name, $description = '')
	{
		$args = new stdClass();
		$args->nick_name = $nick_name;
		$args->description = $description;

		return executeQuery('member.insertDeniedNickName', $args);
	}

	/**
	 * Register managed Email Hostname
	 * @param string $email_host
	 * @param string $description
	 * @return Object
	 */
	function insertManagedEmailHost($email_host, $description = '')
	{
		$args = new stdClass();
		$args->email_host = trim(strtolower($email_host));
		$args->description = $description;

		return executeQuery('member.insertManagedEmailHost', $args);
	}

	/**
	 * delete a denied id
	 * @param string $user_id
	 * @return object
	 */
	function deleteDeniedID($user_id)
	{
		if(!$user_id) unset($user_id);

		$args = new stdClass;
		$args->user_id = $user_id;
		return executeQuery('member.deleteDeniedID', $args);
	}

	/**
	 * delete a denied nick name
	 * @param string $nick_name
	 * @return object
	 */
	function deleteDeniedNickName($nick_name)
	{
		if(!$nick_name) unset($nick_name);

		$args = new stdClass;
		$args->nick_name = $nick_name;
		return executeQuery('member.deleteDeniedNickName', $args);
	}

	/**
	 * delete a denied nick name
	 * @param string $email_host
	 * @return object
	 */
	function deleteManagedEmailHost($email_host)
	{
		$args = new stdClass();
		$args->email_host = $email_host;
		return executeQuery('member.deleteManagedEmailHost', $args);
	}

	/**
	 * Delete a join form
	 * @param int $member_join_form_srl
	 * @return Object
	 */
	function deleteJoinForm($member_join_form_srl)
	{
		$args = new stdClass();
		$args->member_join_form_srl = $member_join_form_srl;
		$output = executeQuery('member.deleteJoinForm', $args);
		return $output;
	}

	/**
	 * Move up a join form
	 * @deprecated
	 * @param int $member_join_form_srl
	 * @return Object
	 */
	function moveJoinFormUp($member_join_form_srl)
	{
		$oMemberModel = getModel('member');
		// Get information of the join form
		$args = new stdClass;
		$args->member_join_form_srl = $member_join_form_srl;
		$output = executeQuery('member.getJoinForm', $args);

		$join_form = $output->data;
		$list_order = $join_form->list_order;
		// Get a list of all join forms
		$join_form_list = $oMemberModel->getJoinFormList();
		$join_form_srl_list = array_keys($join_form_list);
		if(count($join_form_srl_list)<2) return new BaseObject();

		$prev_member_join_form = NULL;
		foreach($join_form_list as $key => $val)
		{
			if($val->member_join_form_srl == $member_join_form_srl) break;
			$prev_member_join_form = $val;
		}
		// Return if no previous join form exists
		if(!$prev_member_join_form) return new BaseObject();
		// Information of the join form
		$cur_args = new stdClass;
		$cur_args->member_join_form_srl = $member_join_form_srl;
		$cur_args->list_order = $prev_member_join_form->list_order;
		// Information of the target join form
		$prev_args = new stdClass;
		$prev_args->member_join_form_srl = $prev_member_join_form->member_join_form_srl;
		$prev_args->list_order = $list_order;
		// Execute Query
		$output = executeQuery('member.updateMemberJoinFormListorder', $cur_args);
		if(!$output->toBool()) return $output;

		executeQuery('member.updateMemberJoinFormListorder', $prev_args);
		if(!$output->toBool()) return $output;

		return new BaseObject();
	}

	/**
	 * Move down a join form
	 * @deprecated
	 * @param int $member_join_form_srl
	 * @return Object
	 */
	function moveJoinFormDown($member_join_form_srl)
	{
		$oMemberModel = getModel('member');
		// Get information of the join form
		$args = new stdClass;
		$args->member_join_form_srl = $member_join_form_srl;
		$output = executeQuery('member.getJoinForm', $args);

		$join_form = $output->data;
		$list_order = $join_form->list_order;
		// Get information of all join forms
		$join_form_list = $oMemberModel->getJoinFormList();
		$join_form_srl_list = array_keys($join_form_list);
		if(count($join_form_srl_list)<2) return new BaseObject();

		for($i=0;$i<count($join_form_srl_list);$i++)
		{
			if($join_form_srl_list[$i]==$member_join_form_srl) break;
		}

		$next_member_join_form_srl = $join_form_srl_list[$i+1];
		// Return if no previous join form exists
		if(!$next_member_join_form_srl) return new BaseObject();
		$next_member_join_form = $join_form_list[$next_member_join_form_srl];
		// Information of the join form
		$cur_args = new stdClass;
		$cur_args->member_join_form_srl = $member_join_form_srl;
		$cur_args->list_order = $next_member_join_form->list_order;
		// Information of the target join form
		$next_args = new stdClass;
		$next_args->member_join_form_srl = $next_member_join_form->member_join_form_srl;
		$next_args->list_order = $list_order;
		// Execute Query
		$output = executeQuery('member.updateMemberJoinFormListorder', $cur_args);
		if(!$output->toBool()) return $output;

		$output = executeQuery('member.updateMemberJoinFormListorder', $next_args);
		if(!$output->toBool()) return $output;

		return new BaseObject();
	}
}
/* End of file member.admin.controller.php */
/* Location: ./modules/member/member.admin.controller.php */
