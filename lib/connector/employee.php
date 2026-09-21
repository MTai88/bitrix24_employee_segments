<?php
/**
 * Connector "Portal employees" for the Bitrix Sender marketing module.
 *
 * Adds intranet users (employees) as a data source of mailing segments:
 * CRM contacts/companies are not required, so it fits corporate portals
 * without CRM.
 */

namespace Mtai\EmployeeSegments\Connector;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Sender\Connector\BaseFilter;
use Bitrix\Sender\Recipient\Type as RecipientType;

Loc::loadMessages(__FILE__);

class Employee extends BaseFilter
{
	public const FIELD_ACTIVE = 'ACTIVE';
	public const FIELD_DEPARTMENT_ID = 'DEPARTMENT_ID';
	public const FIELD_GROUP_ID = 'GROUP_ID';
	public const FIELD_KEYWORD = 'KEYWORD';
	public const FIELD_WORK_POSITION = 'WORK_POSITION';
	public const FIELD_LAST_LOGIN = 'LAST_LOGIN';
	public const FIELD_DATE_REGISTER = 'DATE_REGISTER';

	/** Every user group except "all users" (id 2), it is meaningless for a segment. */
	private const EXCLUDED_GROUP_IDS = [2];

	public function getName()
	{
		return Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_NAME');
	}

	/**
	 * Connector code. Keep it without underscores: Sender composes the ui filter id
	 * as "<module>_<code>_<num>" and parses it back by splitting on underscores.
	 */
	public function getCode()
	{
		return 'employee';
	}

	/**
	 * Personalization tags available in letter bodies for this data source.
	 */
	public static function getPersonalizeList()
	{
		return [
			['CODE' => 'EMPLOYEE_LOGIN', 'TITLE' => Loc::getMessage('MTAI_EMPSEG_PERSONALIZE_LOGIN')],
			['CODE' => 'EMPLOYEE_POSITION', 'TITLE' => Loc::getMessage('MTAI_EMPSEG_PERSONALIZE_POSITION')],
			['CODE' => 'EMPLOYEE_WORK_PHONE', 'TITLE' => Loc::getMessage('MTAI_EMPSEG_PERSONALIZE_WORK_PHONE')],
		];
	}

	/**
	 * Recipients: rows with the address under the current data type key ("email").
	 */
	public function getData()
	{
		$filter = $this->getUsersFilter();
		if ($filter === null)
		{
			return [];
		}

		$resultDb = UserTable::getList([
			'select' => [
				'ID',
				'LOGIN',
				'NAME',
				'LAST_NAME',
				'SECOND_NAME',
				'EMAIL',
				'WORK_POSITION',
				'PERSONAL_PHONE',
				'WORK_PHONE',
			],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
		]);

		$resultDb->addFetchDataModifier(
			static function ($data)
			{
				return [
					RecipientType::getCode(RecipientType::EMAIL) => $data['EMAIL'],
					'NAME' => \CUser::FormatName(\CSite::GetNameFormat(false), $data, true),
					'USER_ID' => $data['ID'],
					'EMPLOYEE_LOGIN' => $data['LOGIN'],
					'EMPLOYEE_POSITION' => $data['WORK_POSITION'],
					'EMPLOYEE_WORK_PHONE' => $data['WORK_PHONE'] ?: $data['PERSONAL_PHONE'],
				];
			}
		);

		return $resultDb;
	}

	/**
	 * Cheap count for the segment ui, same filter as getData().
	 */
	protected function getDataCountByType()
	{
		$filter = $this->getUsersFilter();
		if ($filter === null)
		{
			return [];
		}

		$count = UserTable::getCount($filter);

		return [RecipientType::getCode(RecipientType::EMAIL) => $count];
	}

	/**
	 * Build the D7 filter for UserTable by the connector field values.
	 * Returns null when the connector is not configured yet (no filter applied,
	 * "select all" is not checked) - so the ui shows zero instead of the whole portal.
	 *
	 * @return array|null
	 */
	protected function getUsersFilter()
	{
		$selectAll = $this->getFieldValue(self::FIELD_FOR_PRESET_ALL, '') === 'Y';
		if (!$this->hasFieldValues() && !$selectAll)
		{
			return null;
		}

		// an address is required for any recipient
		$filter = [
			'!=EMAIL' => null,
		];
		$filter[] = ['!=EMAIL' => ''];

		if ($selectAll)
		{
			return $filter;
		}

		$active = (string)$this->getFieldValue(self::FIELD_ACTIVE, '');
		if ($active === 'Y' || $active === 'N')
		{
			$filter['=ACTIVE'] = $active;
		}

		$position = trim((string)$this->getFieldValue(self::FIELD_WORK_POSITION, ''));
		if ($position !== '')
		{
			$filter['%WORK_POSITION'] = $position;
		}

		$keyword = trim((string)$this->getFieldValue(self::FIELD_KEYWORD, ''));
		if ($keyword !== '')
		{
			// D7 "%" filters add the wildcards themselves, pass the plain substring
			$filter[] = [
				'LOGIC' => 'OR',
				'%NAME' => $keyword,
				'%LAST_NAME' => $keyword,
				'%SECOND_NAME' => $keyword,
				'%LOGIN' => $keyword,
				'%EMAIL' => $keyword,
				'%WORK_POSITION' => $keyword,
			];
		}

		$departments = $this->normalizeIdList($this->getFieldValue(self::FIELD_DEPARTMENT_ID, ''));
		if ($departments)
		{
			// a department means the department with all its subdepartments
			$departments = $this->expandDepartments($departments);
			if ($departments)
			{
				$filter['=UF_DEPARTMENT'] = $departments;
			}
		}

		$groups = $this->normalizeIdList($this->getFieldValue(self::FIELD_GROUP_ID, ''));
		if ($groups)
		{
			$filter['=GROUPS.GROUP_ID'] = $groups;
		}

		$dateFields = [
			self::FIELD_LAST_LOGIN => 'LAST_LOGIN',
			self::FIELD_DATE_REGISTER => 'DATE_REGISTER',
		];
		foreach ($dateFields as $fieldId => $columnName)
		{
			$from = $this->getFieldDateFrom($fieldId);
			if ($from)
			{
				$filter['>=' . $columnName] = $from;
			}
			$to = $this->getFieldDateTo($fieldId);
			if ($to)
			{
				$filter['<=' . $columnName] = $to;
			}
		}

		return $filter;
	}

	/**
	 * Values of a multiple list field come as an id array or a single id.
	 *
	 * @param mixed $value Field value.
	 * @return int[]
	 */
	protected function normalizeIdList($value)
	{
		if (is_string($value) && str_contains($value, ','))
		{
			$value = explode(',', $value);
		}

		$value = is_array($value) ? $value : [$value];

		$result = [];
		foreach ($value as $item)
		{
			$item = (int)$item;
			if ($item > 0)
			{
				$result[] = $item;
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * Add all subdepartments of the given departments.
	 * Returns the input unchanged when the intranet structure is not available.
	 *
	 * @param int[] $departmentIds Department ids.
	 * @return int[]
	 */
	protected function expandDepartments(array $departmentIds)
	{
		$iblockId = self::getStructureIblockId();
		if ($iblockId <= 0)
		{
			return $departmentIds;
		}

		$result = $departmentIds;
		$sections = \CIBlockSection::GetList(
			[],
			['IBLOCK_ID' => $iblockId, 'ID' => $departmentIds],
			false,
			['ID', 'LEFT_MARGIN', 'RIGHT_MARGIN']
		);
		while ($section = $sections->Fetch())
		{
			$children = \CIBlockSection::GetList(
				[],
				[
					'IBLOCK_ID' => $iblockId,
					'>LEFT_MARGIN' => (int)$section['LEFT_MARGIN'],
					'<RIGHT_MARGIN' => (int)$section['RIGHT_MARGIN'],
				],
				false,
				['ID']
			);
			while ($child = $children->Fetch())
			{
				$result[] = (int)$child['ID'];
			}
		}

		return array_values(array_unique($result));
	}

	/**
	 * Ui filter fields definition (main.ui.filter).
	 */
	public static function getUiFilterFields(bool $checkAccessRights = true): array
	{
		$list = [];

		$list[] = [
			'id' => self::FIELD_ACTIVE,
			'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_ACTIVE'),
			'type' => 'list',
			'items' => [
				'' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_ACTIVE_ALL'),
				'Y' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_ACTIVE_Y'),
				'N' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_ACTIVE_N'),
			],
			'default' => true,
		];

		$departments = self::getDepartmentItems();
		if ($departments)
		{
			$list[] = [
				'id' => self::FIELD_DEPARTMENT_ID,
				'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_DEPARTMENT'),
				'type' => 'list',
				'params' => ['multiple' => 'Y'],
				'items' => $departments,
				'default' => true,
			];
		}

		$list[] = [
			'id' => self::FIELD_GROUP_ID,
			'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_GROUP'),
			'type' => 'list',
			'params' => ['multiple' => 'Y'],
			'items' => self::getGroupItems(),
			'default' => true,
		];

		$list[] = [
			'id' => self::FIELD_KEYWORD,
			'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_KEYWORD'),
			'type' => 'string',
			'default' => true,
		];

		$list[] = [
			'id' => self::FIELD_WORK_POSITION,
			'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_POSITION'),
			'type' => 'string',
			'default' => true,
		];

		$list[] = [
			'id' => self::FIELD_LAST_LOGIN,
			'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_LAST_LOGIN'),
			'type' => 'date',
			'allow_years_switcher' => true,
			'default' => true,
		];

		$list[] = [
			'id' => self::FIELD_DATE_REGISTER,
			'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_FIELD_DATE_REGISTER'),
			'type' => 'date',
			'allow_years_switcher' => true,
			'default' => true,
		];

		return $list;
	}

	protected static function getUiFilterPresets()
	{
		return [
			'custom_empseg_all' => [
				'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_PRESET_ALL'),
				'fields' => [
					self::FIELD_FOR_PRESET_ALL => 'Y',
				],
			],
			'custom_empseg_active' => [
				'name' => Loc::getMessage('MTAI_EMPSEG_CONNECTOR_EMPLOYEE_PRESET_ACTIVE'),
				'fields' => [
					self::FIELD_ACTIVE => 'Y',
				],
			],
		];
	}

	/**
	 * Departments as a tree with indentation.
	 *
	 * @return array<string, string> id => name.
	 */
	protected static function getDepartmentItems()
	{
		$iblockId = self::getStructureIblockId();
		if ($iblockId <= 0)
		{
			return [];
		}

		$items = [];
		$sections = \CIBlockSection::GetList(
			['LEFT_MARGIN' => 'ASC'],
			['IBLOCK_ID' => $iblockId],
			false,
			['ID', 'NAME', 'DEPTH_LEVEL']
		);
		while ($section = $sections->Fetch())
		{
			$indent = str_repeat('. ', max(0, (int)$section['DEPTH_LEVEL'] - 1));
			$items[(string)(int)$section['ID']] = $indent . $section['NAME'];
		}

		return $items;
	}

	/**
	 * User groups available for filtering.
	 *
	 * @return array<string, string> id => name.
	 */
	protected static function getGroupItems()
	{
		$items = [];
		$groups = \Bitrix\Main\GroupTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => [
				'!ID' => self::EXCLUDED_GROUP_IDS,
				'!=ANONYMOUS' => 'Y',
			],
			'order' => ['C_SORT' => 'ASC', 'NAME' => 'ASC'],
		]);
		foreach ($groups as $group)
		{
			$items[(string)$group['ID']] = $group['NAME'];
		}

		return $items;
	}

	/**
	 * Iblock id of the intranet structure, 0 when not available.
	 */
	protected static function getStructureIblockId(): int
	{
		if (!Loader::includeModule('intranet') || !Loader::includeModule('iblock'))
		{
			return 0;
		}

		return (int)\COption::GetOptionInt('intranet', 'iblock_structure', 0);
	}
}
