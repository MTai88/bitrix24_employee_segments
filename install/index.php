<?php
/**
 * Installer of the module mtai.employeesegments.
 */

use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

class mtai_employeesegments extends CModule
{
	public $MODULE_ID = 'mtai.employeesegments';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $MODULE_GROUP_RIGHTS = 'N';

	public function __construct()
	{
		$arModuleVersion = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = (string)($arModuleVersion['VERSION'] ?? '');
		$this->MODULE_VERSION_DATE = (string)($arModuleVersion['VERSION_DATE'] ?? '');

		$this->MODULE_NAME = Loc::getMessage('MTAI_EMPSEG_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('MTAI_EMPSEG_MODULE_DESCRIPTION');
		$this->PARTNER_NAME = Loc::getMessage('MTAI_EMPSEG_PARTNER_NAME');
		$this->PARTNER_URI = Loc::getMessage('MTAI_EMPSEG_PARTNER_URI');
	}

	/**
	 * The module has no own tables, only the module record and an event handler.
	 */
	public function InstallDB(): bool
	{
		ModuleManager::registerModule($this->MODULE_ID);

		return true;
	}

	public function UnInstallDB(): bool
	{
		ModuleManager::unRegisterModule($this->MODULE_ID);

		return true;
	}

	public function InstallEvents(): bool
	{
		EventManager::getInstance()->registerEventHandlerCompatible(
			'sender',
			'OnConnectorList',
			$this->MODULE_ID,
			'\Mtai\EmployeeSegments\EventHandler',
			'onConnectorList'
		);

		return true;
	}

	public function UnInstallEvents(): bool
	{
		EventManager::getInstance()->unRegisterEventHandler(
			'sender',
			'OnConnectorList',
			$this->MODULE_ID,
			'\Mtai\EmployeeSegments\EventHandler',
			'onConnectorList'
		);

		return true;
	}

	public function InstallFiles(): bool
	{
		return true;
	}

	public function UnInstallFiles(): bool
	{
		return true;
	}

	public function DoInstall(): bool
	{
		global $APPLICATION;

		if (!ModuleManager::isModuleInstalled('sender') || !\Bitrix\Main\Loader::includeModule('sender'))
		{
			$APPLICATION->ThrowException(Loc::getMessage('MTAI_EMPSEG_INSTALL_ERROR_SENDER'));

			return false;
		}

		$this->InstallDB();
		$this->InstallEvents();

		return true;
	}

	public function DoUninstall(): bool
	{
		$this->UnInstallEvents();
		$this->UnInstallDB();

		return true;
	}
}
