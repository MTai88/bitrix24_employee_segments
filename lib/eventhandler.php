<?php

namespace Mtai\EmployeeSegments;

/**
 * Event handlers of the module.
 */
class EventHandler
{
	/**
	 * Registers the "Portal employees" connector in the Sender module
	 * so it becomes available as a data source of marketing segments.
	 *
	 * Compatible (non-D7) handler: Sender dispatches OnConnectorList the old way,
	 * a returned array is wrapped into an EventResult with this module id.
	 *
	 * @param array $data Event parameters.
	 * @return array
	 */
	public static function onConnectorList($data)
	{
		$data['CONNECTOR'][] = '\Mtai\EmployeeSegments\Connector\Employee';

		return $data;
	}
}
