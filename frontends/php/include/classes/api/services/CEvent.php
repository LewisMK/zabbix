<?php
/*
** Zabbix
** Copyright (C) 2001-2016 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


/**
 * Class containing methods for operations with events.
 *
 * @package API
 */
class CEvent extends CApiService {

	protected $tableName = 'events';
	protected $tableAlias = 'e';
	protected $sortColumns = ['eventid', 'objectid', 'clock'];

	/**
	 * Array of supported objects where keys are object IDs and values are translated object names.
	 *
	 * @var array
	 */
	protected $objects = [];

	/**
	 * Array of supported sources where keys are source IDs and values are translated source names.
	 *
	 * @var array
	 */
	protected $sources = [];

	public function __construct() {
		parent::__construct();

		$this->sources = eventSource();
		$this->objects = eventObject();
	}

	/**
	 * Get events data.
	 *
	 * @param _array $options
	 * @param array $options['itemids']
	 * @param array $options['hostids']
	 * @param array $options['groupids']
	 * @param array $options['eventids']
	 * @param array $options['applicationids']
	 * @param array $options['status']
	 * @param array $options['editable']
	 * @param array $options['count']
	 * @param array $options['pattern']
	 * @param array $options['limit']
	 * @param array $options['order']
	 *
	 * @return array|int item data as array or false if error
	 */
	public function get($options = []) {
		$result = [];
		$userType = self::$userData['type'];
		$userid = self::$userData['userid'];

		$sqlParts = [
			'select'	=> [$this->fieldId('eventid')],
			'from'		=> ['events' => 'events e'],
			'where'		=> [],
			'order'		=> [],
			'group'		=> [],
			'limit'		=> null
		];

		$defOptions = [
			'groupids'					=> null,
			'hostids'					=> null,
			'objectids'					=> null,
			'eventids'					=> null,
			'editable'					=> null,
			'object'					=> EVENT_OBJECT_TRIGGER,
			'source'					=> EVENT_SOURCE_TRIGGERS,
			'acknowledged'				=> null,
			'nopermissions'				=> null,
			// filter
			'value'						=> null,
			'time_from'					=> null,
			'time_till'					=> null,
			'eventid_from'				=> null,
			'eventid_till'				=> null,
			// filter
			'filter'					=> null,
			'search'					=> null,
			'searchByAny'				=> null,
			'startSearch'				=> null,
			'excludeSearch'				=> null,
			'searchWildcardsEnabled'	=> null,
			// output
			'output'					=> API_OUTPUT_EXTEND,
			'selectHosts'				=> null,
			'selectRelatedObject'		=> null,
			'select_alerts'				=> null,
			'select_acknowledges'		=> null,
			'selectTags'				=> null,
			'countOutput'				=> null,
			'groupCount'				=> null,
			'preservekeys'				=> null,
			'sortfield'					=> '',
			'sortorder'					=> '',
			'limit'						=> null
		];
		$options = zbx_array_merge($defOptions, $options);

		$this->validateGet($options);

		// editable + PERMISSION CHECK
		if ($userType != USER_TYPE_SUPER_ADMIN && !$options['nopermissions']) {
			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				// specific triggers
				if ($options['objectids'] !== null) {
					$triggers = API::Trigger()->get([
						'output' => ['triggerid'],
						'triggerids' => $options['objectids'],
						'editable' => $options['editable']
					]);
					$options['objectids'] = zbx_objectValues($triggers, 'triggerid');
				}
				// all triggers
				else {
					$permission = $options['editable'] ? PERM_READ_WRITE : PERM_READ;
					$sqlParts['where'][] = 'EXISTS ('.
							'SELECT NULL'.
							' FROM functions f,items i,hosts_groups hgg'.
								' JOIN rights r'.
									' ON r.id=hgg.groupid'.
										' AND '.dbConditionInt('r.groupid', getUserGroupsByUserId($userid)).
							' WHERE e.objectid=f.triggerid'.
								' AND f.itemid=i.itemid'.
								' AND i.hostid=hgg.hostid'.
							' GROUP BY f.triggerid'.
							' HAVING MIN(r.permission)>'.PERM_DENY.
								' AND MAX(r.permission)>='.zbx_dbstr($permission).
							')';
				}
			}
			// items and LLD rules
			elseif ($options['object'] == EVENT_OBJECT_ITEM || $options['object'] == EVENT_OBJECT_LLDRULE) {
				// specific items or LLD rules
				if ($options['objectids'] !== null) {
					if ($options['object'] == EVENT_OBJECT_ITEM) {
						$items = API::Item()->get([
							'output' => ['itemid'],
							'itemids' => $options['objectids'],
							'editable' => $options['editable']
						]);
						$options['objectids'] = zbx_objectValues($items, 'itemid');
					}
					elseif ($options['object'] == EVENT_OBJECT_LLDRULE) {
						$items = API::DiscoveryRule()->get([
							'output' => ['itemid'],
							'itemids' => $options['objectids'],
							'editable' => $options['editable']
						]);
						$options['objectids'] = zbx_objectValues($items, 'itemid');
					}
				}
				// all items and LLD rules
				else {
					$permission = $options['editable'] ? PERM_READ_WRITE : PERM_READ;
					$sqlParts['where'][] = 'EXISTS ('.
							'SELECT NULL'.
							' FROM items i,hosts_groups hgg'.
								' JOIN rights r'.
									' ON r.id=hgg.groupid'.
										' AND '.dbConditionInt('r.groupid', getUserGroupsByUserId($userid)).
							' WHERE e.objectid=i.itemid'.
								' AND i.hostid=hgg.hostid'.
							' GROUP BY hgg.hostid'.
							' HAVING MIN(r.permission)>'.PERM_DENY.
								' AND MAX(r.permission)>='.zbx_dbstr($permission).
							')';
				}
			}
		}

		// eventids
		if (!is_null($options['eventids'])) {
			zbx_value2array($options['eventids']);
			$sqlParts['where'][] = dbConditionInt('e.eventid', $options['eventids']);
		}

		// objectids
		if ($options['objectids'] !== null
				&& in_array($options['object'], [EVENT_OBJECT_TRIGGER, EVENT_OBJECT_ITEM, EVENT_OBJECT_LLDRULE])) {

			zbx_value2array($options['objectids']);
			$sqlParts['where'][] = dbConditionInt('e.objectid', $options['objectids']);

			if (!is_null($options['groupCount'])) {
				$sqlParts['group']['objectid'] = 'e.objectid';
			}
		}

		// groupids
		if (!is_null($options['groupids'])) {
			zbx_value2array($options['groupids']);

			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$sqlParts['from']['functions'] = 'functions f';
				$sqlParts['from']['items'] = 'items i';
				$sqlParts['from']['hosts_groups'] = 'hosts_groups hg';
				$sqlParts['where']['hg'] = dbConditionInt('hg.groupid', $options['groupids']);
				$sqlParts['where']['hgi'] = 'hg.hostid=i.hostid';
				$sqlParts['where']['fe'] = 'f.triggerid=e.objectid';
				$sqlParts['where']['fi'] = 'f.itemid=i.itemid';
			}
			// lld rules and items
			elseif ($options['object'] == EVENT_OBJECT_LLDRULE || $options['object'] == EVENT_OBJECT_ITEM) {
				$sqlParts['from']['items'] = 'items i';
				$sqlParts['from']['hosts_groups'] = 'hosts_groups hg';
				$sqlParts['where']['hg'] = dbConditionInt('hg.groupid', $options['groupids']);
				$sqlParts['where']['hgi'] = 'hg.hostid=i.hostid';
				$sqlParts['where']['fi'] = 'e.objectid=i.itemid';
			}
		}

		// hostids
		if (!is_null($options['hostids'])) {
			zbx_value2array($options['hostids']);

			// triggers
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$sqlParts['from']['functions'] = 'functions f';
				$sqlParts['from']['items'] = 'items i';
				$sqlParts['where']['i'] = dbConditionInt('i.hostid', $options['hostids']);
				$sqlParts['where']['ft'] = 'f.triggerid=e.objectid';
				$sqlParts['where']['fi'] = 'f.itemid=i.itemid';
			}
			// lld rules and items
			elseif ($options['object'] == EVENT_OBJECT_LLDRULE || $options['object'] == EVENT_OBJECT_ITEM) {
				$sqlParts['from']['items'] = 'items i';
				$sqlParts['where']['i'] = dbConditionInt('i.hostid', $options['hostids']);
				$sqlParts['where']['fi'] = 'e.objectid=i.itemid';
			}
		}

		// object
		if (!is_null($options['object'])) {
			$sqlParts['where']['o'] = 'e.object='.zbx_dbstr($options['object']);
		}

		// source
		if (!is_null($options['source'])) {
			$sqlParts['where'][] = 'e.source='.zbx_dbstr($options['source']);
		}

		// acknowledged
		if (!is_null($options['acknowledged'])) {
			$sqlParts['where'][] = 'e.acknowledged='.($options['acknowledged'] ? 1 : 0);
		}

		// time_from
		if (!is_null($options['time_from'])) {
			$sqlParts['where'][] = 'e.clock>='.zbx_dbstr($options['time_from']);
		}

		// time_till
		if (!is_null($options['time_till'])) {
			$sqlParts['where'][] = 'e.clock<='.zbx_dbstr($options['time_till']);
		}

		// eventid_from
		if (!is_null($options['eventid_from'])) {
			$sqlParts['where'][] = 'e.eventid>='.zbx_dbstr($options['eventid_from']);
		}

		// eventid_till
		if (!is_null($options['eventid_till'])) {
			$sqlParts['where'][] = 'e.eventid<='.zbx_dbstr($options['eventid_till']);
		}

		// value
		if (!is_null($options['value'])) {
			zbx_value2array($options['value']);
			$sqlParts['where'][] = dbConditionInt('e.value', $options['value']);
		}

		// search
		if (is_array($options['search'])) {
			zbx_db_search('events e', $options, $sqlParts);
		}

		// filter
		if (is_array($options['filter'])) {
			$this->dbFilter('events e', $options, $sqlParts);
		}

		// limit
		if (zbx_ctype_digit($options['limit']) && $options['limit']) {
			$sqlParts['limit'] = $options['limit'];
		}

		$sqlParts = $this->applyQueryOutputOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$sqlParts = $this->applyQuerySortOptions($this->tableName(), $this->tableAlias(), $options, $sqlParts);
		$res = DBselect($this->createSelectQueryFromParts($sqlParts), $sqlParts['limit']);
		while ($event = DBfetch($res)) {
			if (!is_null($options['countOutput'])) {
				if (!is_null($options['groupCount'])) {
					$result[] = $event;
				}
				else {
					$result = $event['rowscount'];
				}
			}
			else {
				$result[$event['eventid']] = $event;
			}
		}

		if (!is_null($options['countOutput'])) {
			return $result;
		}

		if ($result) {
			$result = $this->addRelatedObjects($options, $result);
			$result = $this->unsetExtraFields($result, ['object', 'objectid'], $options['output']);
		}

		// removing keys (hash -> array)
		if (is_null($options['preservekeys'])) {
			$result = zbx_cleanHashes($result);
		}

		return $result;
	}

	/**
	 * Validates the input parameters for the get() method.
	 *
	 * @throws APIException     if the input is invalid
	 *
	 * @param array     $options
	 *
	 * @return void
	 */
	protected function validateGet(array $options) {
		$sourceValidator = new CLimitedSetValidator([
			'values' => array_keys(eventSource())
		]);
		if (!$sourceValidator->validate($options['source'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect source value.'));
		}

		$objectValidator = new CLimitedSetValidator([
			'values' => array_keys(eventObject())
		]);
		if (!$objectValidator->validate($options['object'])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect object value.'));
		}

		$sourceObjectValidator = new CEventSourceObjectValidator();
		if (!$sourceObjectValidator->validate(['source' => $options['source'], 'object' => $options['object']])) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $sourceObjectValidator->getError());
		}
	}

	/**
	 * Acknowledges the given events.
	 *
	 * Supported parameters:
	 * - eventids   - an event ID or an array of event IDs to acknowledge
	 * - message    - acknowledgment message
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function acknowledge(array $data) {
		$data['eventids'] = zbx_toArray($data['eventids']);

		$this->validateAcknowledge($data);

		$eventIds = zbx_toHash($data['eventids']);

		if (!DBexecute('UPDATE events SET acknowledged=1 WHERE '.dbConditionInt('eventid', $eventIds))) {
			self::exception(ZBX_API_ERROR_PARAMETERS, 'DBerror');
		}

		$time = time();
		$acknowledges = [];
		$action = array_key_exists('action', $data) ? $data['action'] : ZBX_ACKNOWLEDGE_ACTION_NONE;

		foreach ($eventIds as $eventId) {
			$acknowledges[] = [
				'userid' => self::$userData['userid'],
				'eventid' => $eventId,
				'clock' => $time,
				'message' => $data['message'],
				'action' => $action
			];
		}

		$acknowledgeids = DB::insert('acknowledges', $acknowledges);

		if ($action == ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
			// Close problem manually.
			$tasks = [];

			for ($i = 0; $i < count($acknowledgeids); $i++) {
				$tasks[] = ['type' => ZBX_TM_TASK_CLOSE_PROBLEM];
			}

			$taskids = DB::insert('task', $tasks);

			$task_close = [];

			for ($i = 0; $i < count($acknowledgeids); $i++) {
				$task_close[] = [
					'taskid' => $taskids[$i],
					'acknowledgeid' => $acknowledgeids[$i]
				];
			}

			DB::insert('task_close_problem', $task_close, false);
		}

		return ['eventids' => array_values($eventIds)];
	}

	/**
	 * Validates the input parameters for the acknowledge() method.
	 *
	 * @throws APIException     if the input is invalid
	 *
	 * @param array     $data
	 *
	 * @return void
	 */
	protected function validateAcknowledge(array $data) {
		$dbfields = ['eventids' => null, 'message' => null];

		if (!check_db_fields($dbfields, $data)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, _('Incorrect arguments passed to function.'));
		}

		if ($data['message'] === '') {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Incorrect value for field "%1$s": %2$s.', 'message', _('cannot be empty'))
			);
		}

		$this->checkCanBeAcknowledged($data['eventids']);

		if (array_key_exists('action', $data)) {
			if ($data['action'] != ZBX_ACKNOWLEDGE_ACTION_NONE
					&& $data['action'] != ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('Incorrect value for field "%1$s": %2$s.',
					'action', _s('unexpected value "%1$s"', $data['action'])
				));
			}

			if ($data['action'] == ZBX_ACKNOWLEDGE_ACTION_CLOSE_PROBLEM) {
				$this->checkCanBeManuallyClosed($data['eventids']);
			}
		}
	}

	protected function applyQueryOutputOptions($tableName, $tableAlias, array $options, array $sqlParts) {
		$sqlParts = parent::applyQueryOutputOptions($tableName, $tableAlias, $options, $sqlParts);

		if ($options['countOutput'] === null) {
			if ($this->outputIsRequested('r_eventid', $options['output'])
					|| $this->outputIsRequested('c_eventid', $options['output'])
					|| $this->outputIsRequested('correlationid', $options['output'])) {
				// Select fields from event_recovery table using LEFT JOIN.

				if ($this->outputIsRequested('r_eventid', $options['output'])) {
					$sqlParts['select']['r_eventid'] = 'er.r_eventid';
				}
				if ($this->outputIsRequested('c_eventid', $options['output'])) {
					$sqlParts['select']['c_eventid'] = 'er.c_eventid';
				}
				if ($this->outputIsRequested('correlationid', $options['output'])) {
					$sqlParts['select']['correlationid'] = 'er.correlationid';
				}

				$sqlParts['left_join'][] = ['from' => 'event_recovery er', 'on' => 'er.eventid=e.eventid'];
			}

			if ($options['selectRelatedObject'] !== null || $options['selectHosts'] !== null) {
				$sqlParts = $this->addQuerySelect('e.object', $sqlParts);
				$sqlParts = $this->addQuerySelect('e.objectid', $sqlParts);
			}
		}

		return $sqlParts;
	}

	protected function addRelatedObjects(array $options, array $result) {
		$result = parent::addRelatedObjects($options, $result);

		$eventIds = array_keys($result);

		// adding hosts
		if ($options['selectHosts'] !== null && $options['selectHosts'] != API_OUTPUT_COUNT) {
			// trigger events
			if ($options['object'] == EVENT_OBJECT_TRIGGER) {
				$query = DBselect(
					'SELECT e.eventid,i.hostid'.
						' FROM events e,functions f,items i'.
						' WHERE '.dbConditionInt('e.eventid', $eventIds).
						' AND e.objectid=f.triggerid'.
						' AND f.itemid=i.itemid'.
						' AND e.object='.zbx_dbstr($options['object']).
						' AND e.source='.zbx_dbstr($options['source'])
				);
			}
			// item and LLD rule events
			elseif ($options['object'] == EVENT_OBJECT_ITEM || $options['object'] == EVENT_OBJECT_LLDRULE) {
				$query = DBselect(
					'SELECT e.eventid,i.hostid'.
						' FROM events e,items i'.
						' WHERE '.dbConditionInt('e.eventid', $eventIds).
						' AND e.objectid=i.itemid'.
						' AND e.object='.zbx_dbstr($options['object']).
						' AND e.source='.zbx_dbstr($options['source'])
				);
			}

			$relationMap = new CRelationMap();
			while ($relation = DBfetch($query)) {
				$relationMap->addRelation($relation['eventid'], $relation['hostid']);
			}

			$hosts = API::Host()->get([
				'output' => $options['selectHosts'],
				'hostids' => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true
			]);
			$result = $relationMap->mapMany($result, $hosts, 'hosts');
		}

		// adding the related object
		if ($options['selectRelatedObject'] !== null && $options['selectRelatedObject'] != API_OUTPUT_COUNT
				&& $options['object'] != EVENT_OBJECT_AUTOREGHOST) {

			$relationMap = new CRelationMap();
			foreach ($result as $event) {
				$relationMap->addRelation($event['eventid'], $event['objectid']);
			}

			switch ($options['object']) {
				case EVENT_OBJECT_TRIGGER:
					$api = API::Trigger();
					break;
				case EVENT_OBJECT_DHOST:
					$api = API::DHost();
					break;
				case EVENT_OBJECT_DSERVICE:
					$api = API::DService();
					break;
				case EVENT_OBJECT_ITEM:
					$api = API::Item();
					break;
				case EVENT_OBJECT_LLDRULE:
					$api = API::DiscoveryRule();
					break;
			}

			$objects = $api->get([
				'output' => $options['selectRelatedObject'],
				$api->pkOption() => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true
			]);
			$result = $relationMap->mapOne($result, $objects, 'relatedObject');
		}

		// adding alerts
		if ($options['select_alerts'] !== null && $options['select_alerts'] != API_OUTPUT_COUNT) {
			$relationMap = $this->createRelationMap($result, 'eventid', 'alertid', 'alerts');
			$alerts = API::Alert()->get([
				'output' => $options['select_alerts'],
				'selectMediatypes' => API_OUTPUT_EXTEND,
				'alertids' => $relationMap->getRelatedIds(),
				'nopermissions' => true,
				'preservekeys' => true,
				'sortfield' => 'clock',
				'sortorder' => ZBX_SORT_DOWN
			]);
			$result = $relationMap->mapMany($result, $alerts, 'alerts');
		}

		// adding acknowledges
		if ($options['select_acknowledges'] !== null) {
			if ($options['select_acknowledges'] != API_OUTPUT_COUNT) {
				// create the base query
				$sqlParts = API::getApiService()->createSelectQueryParts('acknowledges', 'a', [
					'output' => $this->outputExtend($options['select_acknowledges'],
						['acknowledgeid', 'eventid', 'clock']
					),
					'filter' => ['eventid' => $eventIds]
				]);
				$sqlParts['order'][] = 'a.clock DESC';

				// if the user data is requested via extended output or specified fields, join the users table
				$userFields = ['alias', 'name', 'surname'];
				$requestUserData = [];
				foreach ($userFields as $userField) {
					if ($this->outputIsRequested($userField, $options['select_acknowledges'])) {
						$requestUserData[] = $userField;
					}
				}
				if ($requestUserData) {
					foreach ($requestUserData as $userField) {
						$sqlParts = $this->addQuerySelect('u.'.$userField, $sqlParts);
					}
					$sqlParts['from'][] = 'users u';
					$sqlParts['where'][] = 'a.userid=u.userid';
				}

				$acknowledges = DBFetchArrayAssoc(DBselect($this->createSelectQueryFromParts($sqlParts)), 'acknowledgeid');
				$relationMap = $this->createRelationMap($acknowledges, 'eventid', 'acknowledgeid');
				$acknowledges = $this->unsetExtraFields($acknowledges, ['eventid', 'acknowledgeid', 'clock'],
					$options['select_acknowledges']
				);
				$result = $relationMap->mapMany($result, $acknowledges, 'acknowledges');
			}
			else {
				$acknowledges = DBFetchArrayAssoc(DBselect(
					'SELECT COUNT(a.acknowledgeid) AS rowscount,a.eventid'.
						' FROM acknowledges a'.
						' WHERE '.dbConditionInt('a.eventid', $eventIds).
						' GROUP BY a.eventid'
				), 'eventid');
				foreach ($result as &$event) {
					if ((isset($acknowledges[$event['eventid']]))) {
						$event['acknowledges'] = $acknowledges[$event['eventid']]['rowscount'];
					}
					else {
						$event['acknowledges'] = 0;
					}
				}
				unset($event);
			}
		}

		// Adding event tags.
		if ($options['selectTags'] !== null && $options['selectTags'] != API_OUTPUT_COUNT) {
			$tags = API::getApiService()->select('event_tag', [
				'output' => $this->outputExtend($options['selectTags'], ['eventid']),
				'filter' => ['eventid' => $eventIds],
				'preservekeys' => true
			]);

			$relationMap = $this->createRelationMap($tags, 'eventid', 'eventtagid');
			$tags = $this->unsetExtraFields($tags, ['eventtagid', 'eventid'], []);
			$result = $relationMap->mapMany($result, $tags, 'tags');
		}

		return $result;
	}

	/**
	 * Checks if the given events exist, are accessible and can be acknowledged.
	 *
	 * @throws APIException     if an event does not exist, is not accessible or is not a trigger event
	 *
	 * @param array $eventIds
	 *
	 * @return void
	 */
	protected function checkCanBeAcknowledged(array $eventIds) {
		$allowedEvents = $this->get([
			'eventids' => $eventIds,
			'output' => ['eventid'],
			'preservekeys' => true
		]);
		foreach ($eventIds as $eventId) {
			if (!isset($allowedEvents[$eventId])) {
				// check if an event actually exists but maybe belongs to a different source or object
				$event = API::getApiService()->select($this->tableName(), [
					'output' => ['eventid', 'source', 'object'],
					'eventids' => $eventId,
					'limit' => 1
				]);
				$event = reset($event);

				// if the event exists, check if we have permissions to access it
				if ($event) {
					$event = $this->get([
						'output' => ['eventid'],
						'eventids' => $event['eventid'],
						'source' => $event['source'],
						'object' => $event['object'],
						'limit' => 1
					]);
				}

				// the event exists, is accessible but belongs to a different object or source
				if ($event) {
					self::exception(ZBX_API_ERROR_PERMISSIONS, _('Only trigger events can be acknowledged.'));
				}
				// the event either doesn't exist or is not accessible
				else {
					self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
				}
			}
		}
	}

	/**
	 * Checks if the given events can be closed manually.
	 *
	 * @param array $eventids
	 */
	protected function checkCanBeManuallyClosed(array $eventids) {
		$events_count = count($eventids);

		$events = $this->get([
			'output' => ['eventid'],
			'eventids' => $eventids,
			'editable' => true,
			'object' => EVENT_OBJECT_TRIGGER
		]);

		if ($events_count != count($events)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS, _('No permissions to referred object or it does not exist!'));
		}

		$events = $this->get([
			'output' => ['eventid'],
			'eventids' => $eventids,
			'value' => TRIGGER_VALUE_TRUE,
			'object' => EVENT_OBJECT_TRIGGER,
			'selectRelatedObject' => ['manual_close']
		]);

		if ($events_count != count($events)) {
			self::exception(ZBX_API_ERROR_PERMISSIONS,
				_s('Cannot close problem: %1$s.',  _('event is not in PROBLEM state'))
			);
		}

		foreach($events as $event) {
			if ($event['relatedObject']['manual_close'] != ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_s('Cannot close problem: %1$s.',  _('trigger does not allow manual closing'))
				);
			}
		}
	}
}
