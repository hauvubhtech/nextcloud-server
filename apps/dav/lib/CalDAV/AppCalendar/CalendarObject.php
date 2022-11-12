<?php

namespace OCA\DAV\CalDAV\AppCalendar;

use Sabre\CalDAV\ICalendarObject;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAVACL\IACL;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\ICalendar\DateTime;

class CalendarObject implements ICalendarObject, IACL {
	private VCalendar $vobject;
	private AppCalendar $calendar;

	public function __construct(AppCalendar $calendar, array $sourceItem) {
		$this->calendar = $calendar;
		$this->vobject = new VCalendar($sourceItem);
	}

	public function getOwner() {
		return $this->calendar->getOwner();
	}

	public function getGroup() {
		return $this->calendar->getGroup();
	}

	public function getACL(): array {
		return [
			[
				'privilege' => '{DAV:}read',
				'principal' => $this->getOwner(),
				'protected' => true,
			]
		];
	}

	public function setACL(array $acl): void {
		throw new Forbidden('Setting ACL is not supported on this node');
	}

	public function getSupportedPrivilegeSet(): ?array {
		return null;
	}

	public function put($data): void {
		throw new Forbidden('This calendar-object is read-only');
	}

	public function get(): string {
		return $this->vobject->serialize();
	}

	public function getContentType(): string {
		return 'text/calendar; charset=utf-8';
	}

	public function getETag(): ?string {
		return null;
	}

	public function getSize() {
		return mb_strlen($this->vobject->serialize());
	}

	public function delete(): void {
		throw new Forbidden('This calendar-object is read-only');
	}

	public function getName(): string {
		// Every object is required to have an UID
		$base = $this->vobject->getBaseComponent();
		// This should never happen except the app provides invalid calendars (VEvent, VTodo... all require UID to be present)
		if ($base === null) {
			throw new NotFound('Invalid node');
		}
		return (string)$base->UID;
	}

	public function setName($name): void {
		throw new Forbidden('This calendar-object is read-only');
	}

	public function getLastModified(): ?int {
		$base = $this->vobject->getBaseComponent();
		if ($base !== null && $this->vobject->getBaseComponent()->{'LAST-MODIFIED'}) {
			/** @var DateTime */
			$lastModified = $this->vobject->getBaseComponent()->{'LAST-MODIFIED'};
			return $lastModified->getDateTime()->getTimestamp();
		}
		return null;
	}
}
