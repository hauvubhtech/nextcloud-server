<?php

namespace OCA\DAV\CalDAV\AppCalendar;

use OCA\DAV\CalDAV\Plugin;
use OCA\DAV\CalDAV\Integration\ExternalCalendar;
use OCP\Calendar\ICalendar;
use OCP\Calendar\ICreateFromString;
use OCP\Constants;
use Sabre\CalDAV\CalendarQueryValidator;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\PropPatch;
use Sabre\VObject\Reader;

class AppCalendar extends ExternalCalendar {
	protected string $principal;
	protected ICalendar $calendar;

	public function __construct(string $appId, ICalendar $calendar, string $principal) {
		parent::__construct($appId, $calendar->getUri());
		$this->principal = $principal;
		$this->calendar = $calendar;
	}

	public function getOwner(): ?string {
		return $this->principal;
	}

	public function getGroup(): ?string {
		return null;
	}

	public function getACL(): array {
		$acl = [
			[
				'privilege' => '{DAV:}read',
				'principal' => $this->getOwner(),
				'protected' => true,
			],
			[
				'privilege' => '{DAV:}write-properties',
				'principal' => $this->getOwner(),
				'protected' => true,
			]
		];
		if ($this->calendar instanceof ICreateFromString &&
			$this->calendar->getPermissions() & Constants::PERMISSION_CREATE) {
			$acl[] = [
				'privilege' => '{DAV:}write',
				'principal' => $this->getOwner(),
				'protected' => true,
			];
		}
		return $acl;
	}

	public function setACL(array $acl): void {
		throw new Forbidden('Setting ACL is not supported on this node');
	}

	public function getSupportedPrivilegeSet(): ?array {
		// Use the default one
		return null;
	}

	public function getLastModified(): ?int {
		// unknown
		return null;
	}

	public function delete(): void {
		// No delete method in OCP\Calendar\ICalendar
		throw new Forbidden('Deleting an entry is not implemented');
	}

	public function createFile($name, $data = null) {
		if ($this->calendar instanceof ICreateFromString) {
			if (is_resource($data)) {
				$data = stream_get_contents($data);
			}
			$this->calendar->createFromString($name, is_null($data) ? '' : $data);
			return null;
		} else {
			throw new Forbidden('Creating a new entry is not allowed');
		}
	}

	public function getProperties($properties) {
		return [
			'{DAV:}displayname' => $this->calendar->getDisplayName() ?: $this->calendar->getKey(),
			'{http://apple.com/ns/ical/}calendar-color' => '#' . ($this->calendar->getDisplayColor() ?: '0082c9'),
			'{' . Plugin::NS_CALDAV . '}supported-calendar-component-set' => new SupportedCalendarComponentSet(['VEVENT', 'VTODO']),
		];
	}

	public function calendarQuery(array $filters) {
		$result = [];
		$objects = $this->getChildren();

		foreach ($objects as $object) {
			if ($this->validateFilterForObject($object, $filters)) {
				$result[] = $object->getName();
			}
		}

		return $result;
	}

	protected function validateFilterForObject(ICalendarObject $object, array $filters): bool {
		/** @var \Sabre\VObject\Component\VCalendar */
		$vObject = Reader::read($object->get());

		$validator = new CalendarQueryValidator();
		$result = $validator->validate($vObject, $filters);

		// Destroy circular references so PHP will GC the object.
		$vObject->destroy();

		return $result;
	}

	public function childExists($name): bool {
		try {
			$this->getChild($name);
			return true;
		} catch (NotFound $error) {
			return false;
		}
	}

	public function getChild($name) {
		$pos = strrpos($name, '.ics');
		$children = $this->calendar->search(substr($name, 0, $pos === false ? null : $pos), ['UID'], [], 1);

		if (count($children) > 0) {
			return new CalendarObject($this, $children[0]);
		}

		throw new NotFound('Node not found');
	}

	/**
	 * @return ICalendarObject[]
	 */
	public function getChildren(): array {
		$children = array_map(function ($calendar) {
			return new CalendarObject($this, $calendar);
		}, $this->calendar->search(''));

		return $children;
	}

	public function propPatch(PropPatch $propPatch): void {
		// no setDisplayColor or setDisplayName in \OCP\Calendar\ICalendar
	}
}
