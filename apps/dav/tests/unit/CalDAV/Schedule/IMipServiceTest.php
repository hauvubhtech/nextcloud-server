<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @copyright Copyright (c) 2017, Georg Ehrke
 *
 * @author brad2014 <brad2014@users.noreply.github.com>
 * @author Brad Rubenstein <brad@wbr.tech>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Georg Ehrke <oc.list@georgehrke.com>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Thomas Citharel <nextcloud@tcit.fr>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Tests\unit\CalDAV\Schedule;

use OC\L10N\L10N;
use OC\L10N\LazyL10N;
use OC\URLGenerator;
use OCA\DAV\CalDAV\Schedule\IMipPlugin;
use OCA\DAV\CalDAV\Schedule\IMipService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Defaults;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as L10NFactory;
use OCP\Mail\IAttachment;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Property\ICalendar\DateTime;
use Sabre\VObject\Reader;
use Test\TestCase;
use function array_merge;

class IMipServiceTest extends TestCase {
	/** @var URLGenerator|MockObject  */
	private $urlGenerator;

	/** @var IConfig|MockObject  */
	private $config;

	/** @var IDBConnection|MockObject  */
	private $db;

	/** @var ISecureRandom|MockObject  */
	private $random;

	/** @var L10NFactory|MockObject  */
	private $l10nFactory;

	/** @var L10N|MockObject  */
	private $l10n;

	/** @var IMipService  */
	private $service;

	protected function setUp(): void {
		$this->urlGenerator = $this->createMock(URLGenerator::class);
		$this->config = $this->createMock(IConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->random = $this->createMock(ISecureRandom::class);
		$this->l10nFactory = $this->createMock(L10NFactory::class);
		$this->l10n = $this->createMock(LazyL10N::class);
		$this->l10nFactory->expects(self::once())
			->method('findGenericLanguage')
			->willReturn('en');
		$this->l10nFactory->expects(self::once())
			->method('get')
			->with('dav', 'en')
			->willReturn($this->l10n);
		$this->service = new IMipService(
							$this->urlGenerator,
							$this->config,
							$this->db,
							$this->random,
							$this->l10nFactory
		);
	}

	public function testRemoveUnchangedWithUnchanged(): void {
		$vCalendar = new VCalendar();
		$vEvent = new VEvent($vCalendar, 'one', [
			'UID' => 'uid-1234',
			'LAST-MODIFIED' => 123456,
			'SEQUENCE' => 2,
			'SUMMARY' => 'Fellowship meeting',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RRULE' => 'FREQ=DAILY;INTERVAL=1;UNTIL=20160201T000000Z'
		]);
		$vEvent->add('ORGANIZER', 'mailto:gandalf@wiz.ard');
		$vEvent->add('ATTENDEE', 'mailto:' . 'frodo@hobb.it', ['RSVP' => 'TRUE', 'CN' => 'Frodo']);
		$vCalendar->add($vEvent);
		$vEvent = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 1,
			'LAST-MODIFIED' => 456789,
			'SUMMARY' => 'Elevenses',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);
		$vEvent->add('ORGANIZER', 'mailto:gandalf@wiz.ard');
		$vEvent->add('ATTENDEE', 'mailto:' . 'frodo@hobb.it', ['RSVP' => 'TRUE', 'CN' => 'Frodo']);
		$vCalendar->add($vEvent);
		$eventsToFilter = $vCalendar->getComponents();
		// event unchanged
		$event = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 1,
			'LAST-MODIFIED' => 456789,
			'SUMMARY' => 'Elevenses',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);

		$result = $this->service->removeIfUnchanged($event, $eventsToFilter);
		$this->assertTrue($result);
	}

	public function testRemoveUnchangedWithChanged(): void {
		$vCalendar = new VCalendar();
		$vEvent = new VEvent($vCalendar, 'one', [
			'UID' => 'uid-1234',
			'LAST-MODIFIED' => 123456,
			'SEQUENCE' => 2,
			'SUMMARY' => 'Fellowship meeting',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RRULE' => 'FREQ=DAILY;INTERVAL=1;UNTIL=20160201T000000Z'
		]);
		$vEvent->add('ORGANIZER', 'mailto:gandalf@wiz.ard');
		$vEvent->add('ATTENDEE', 'mailto:' . 'frodo@hobb.it', ['RSVP' => 'TRUE', 'CN' => 'Frodo']);
		$vCalendar->add($vEvent);
		$vEvent = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 1,
			'LAST-MODIFIED' => 456789,
			'SUMMARY' => 'Elevenses',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);
		$vEvent->add('ORGANIZER', 'mailto:gandalf@wiz.ard');
		$vEvent->add('ATTENDEE', 'mailto:' . 'frodo@hobb.it', ['RSVP' => 'TRUE', 'CN' => 'Frodo']);
		$vCalendar->add($vEvent);
		$eventsToFilter = $vCalendar->getComponents();
		// this event was changed
		$event = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 3,
			'LAST-MODIFIED' => 789456,
			'SUMMARY' => 'Second breakfast',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);

		$result = $this->service->removeIfUnchanged($event, $eventsToFilter);
		$this->assertFalse($result);
	}

	public function testGetFrom(): void {
		$senderName = "Detective McQueen";
		$default = "Twin Lakes Police Department - Darkside Division";
		$expected = "Detective McQueen via Twin Lakes Police Department - Darkside Division";

		$this->l10n->expects(self::once())
			->method('t')
			->willReturn($expected);

		$actual = $this->service->getFrom($senderName, $default);
		$this->assertEquals($expected, $actual);
	}

	public function testBuildBodyDataCreated(): void {
		$vCalendar = new VCalendar();
		$oldVevent = null;
		$newVevent = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 3,
			'LAST-MODIFIED' => 789456,
			'SUMMARY' => 'Second Breakfast',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);

		$expected = [
			'meeting_when' => $this->service->generateWhenString($newVevent),
			'meeting_description' => '',
			'meeting_title' => 'Second Breakfast',
			'meeting_location' => '',
			'meeting_url' => '',
			'meeting_url_html' => '',
		];

		$actual = $this->service->buildBodyData($newVevent, $oldVevent);

		$this->assertEquals($expected, $actual);
	}

	public function testBuildBodyDataUpdate(): void {
		$vCalendar = new VCalendar();
		$oldVevent = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 1,
			'LAST-MODIFIED' => 456789,
			'SUMMARY' => 'Elevenses',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);
		$oldVevent->add('ORGANIZER', 'mailto:gandalf@wiz.ard');
		$oldVevent->add('ATTENDEE', 'mailto:' . 'frodo@hobb.it', ['RSVP' => 'TRUE', 'CN' => 'Frodo']);
		$newVevent = new VEvent($vCalendar, 'two', [
			'UID' => 'uid-1234',
			'SEQUENCE' => 3,
			'LAST-MODIFIED' => 789456,
			'SUMMARY' => 'Second Breakfast',
			'DTSTART' => new \DateTime('2016-01-01 00:00:00'),
			'RECURRENCE-ID' => new \DateTime('2016-01-01 00:00:00')
		]);

		$expected = [
			'meeting_when' => $this->service->generateWhenString($newVevent),
			'meeting_description' => '',
			'meeting_title' => 'Second Breakfast',
			'meeting_location' => '',
			'meeting_url' => '',
			'meeting_url_html' => '',
			'meeting_when_html' => $this->service->generateWhenString($newVevent),
			'meeting_title_html' => sprintf("<span style='text-decoration: line-through'>%s</span><br />%s", 'Elevenses', 'Second Breakfast'),
			'meeting_description_html' => '',
			'meeting_location_html' => ''
		];

		$actual = $this->service->buildBodyData($newVevent, $oldVevent);

		$this->assertEquals($expected, $actual);
	}

//	public function testGenerateWhenStringHourlyEvent(): void {
//
//		$vCalendar = new VCalendar();
//		$vevent = new VEvent($vCalendar, 'two', [
//			'UID' => 'uid-1234',
//			'SEQUENCE' => 1,
//			'LAST-MODIFIED' => 456789,
//			'SUMMARY' => 'Elevenses',
//			'TZID' => 'Europe/Vienna'
//		]);
//		$start = (new DateTime($vevent,'start', null))->setValue('2016-01-01 08:00:00');
//		$end = new DateTime($vevent,'end', '2016-01-01 09:00:00');
//		$vevent->add($start);
//		$vevent->add($end);
//		$this->l10n->expects(self::exactly(3))
//			->method('l')
//			->withConsecutive(
//				['weekdayName', $start->getDateTime(), ['width' => 'abbreviated']],
//				['datetime', $start->getDateTime(), ['width' => 'medium|short']],
//				['time', $end->getDateTime(), ['width' => 'medium|short']]
//			)->willReturnOnConsecutiveCalls(
//				['Fr.'],
//				['01.01. 08:00'],
//				['09:00']
//			);
//		// Fr., 06.01.2023, 11:00
//		// 12:00
//		// Fr., 06.01.2023, 09:00 - 10:30 (Europe/Vienna)
//		$whenString = $this->service->generateWhenString($vevent);
//	}
}
